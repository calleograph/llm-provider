"""Контекст для модели в пределах её реального окна:

  окно модели − резерв под ответ − запас 5 % = бюджет;
  обязательно: инструкции (llmp.prompt) + текущий вопрос — не помещаются → ContextOverflowException, а не обрезка;
  затем сводка ранней части диалога и память проекта → материалы проекта (целиком, если помещаются; иначе
  релевантные вопросу фрагменты без повторов) → свежие сообщения истории от последнего к первому.

Порядок блоков в system — от постоянного к изменчивому: материалы
целиком → память проекта → сводка диалога → фрагменты под текущий вопрос. Так неизменная часть промпта остаётся
общим префиксом запросов и попадает в кэш промптов провайдера.

Токены — консервативная оценка (2,5 знака на токен): лучше недобрать, чем переполнить окно."""

from __future__ import annotations

import math
import re
from collections import Counter

from .. import prompt

CHARS_PER_TOKEN = 2.5
CHUNK_CHARS = 1500
UNANONYMIZED_STUB = "[Ранее это сообщение было отправлено без обезличивания; повторно оно не передаётся.]"
_PARA = re.compile(r"\n{2,}")
_WORD = re.compile(r"[^\W_]{4,}")
_WS = re.compile(r"\s+")


class ContextOverflowException(RuntimeError):
    """Инструкции и текущее сообщение сами по себе не помещаются в окно модели — запрос не отправляется."""

    def __init__(self, required: int, budget: int):
        super().__init__(f"Требуется ~{required} токенов, доступно {budget}.")
        self.required = required
        self.budget = budget


def tokens(text: str) -> int:
    return 0 if text == "" else math.ceil(len(text) / CHARS_PER_TOKEN) + 4


def build(context_window: int, max_output: int, layers: dict, sources: list[dict], history: list[dict], current: str,
          summary: dict | None, project_state: str | None, history_cap_tokens: int | None = None, sources_share: float = 0.4) -> dict:
    """→ {system, messages: [{role, content}], report, needsSummary, droppedUncovered}.
    sources_share — гарантированная доля остатка под материалы проекта (настройка проекта)."""
    budget = context_window - max_output - math.ceil(context_window * 0.05)
    instructions = prompt.build(layers)
    fixed = tokens(instructions) + tokens(current)
    if fixed > budget:
        raise ContextOverflowException(fixed, budget)
    rest = budget - fixed

    # История после сводки; сообщения, ушедшие без обезличивания, повторно не передаются никому.
    covered = int((summary or {}).get("covered_until_id") or 0)
    candidates = [
        {"id": int(m["id"]), "role": m["role"], "content": UNANONYMIZED_STUB if m.get("unanonymized") else str(m["content"])}
        for m in history if int(m["id"]) > covered
    ]
    history_total = sum(tokens(m["content"]) for m in candidates)

    # 1. Сводка и память проекта: не больше 20 % остатка.
    state_text = _clip(project_state or "", int(rest * 0.08))
    summary_text = _clip(str((summary or {}).get("text") or ""), int(rest * 0.12))
    volatile = [{"title": "Состояние проекта", "text": state_text}, {"title": "Сводка ранней части диалога", "text": summary_text}]
    used = tokens(state_text) + tokens(summary_text) + 40

    # 2. Источники: целиком, если помещаются в свою долю; иначе — релевантные фрагменты.
    sources_total = sum(tokens(s["text"]) for s in sources)
    source_budget = max(int((rest - used) * sources_share), rest - used - history_total)
    if sources_total <= source_budget:
        source_text, source_tokens, chunks_used, chunks_total = _join_sources(sources), sources_total, -1, -1
    else:
        query = current + "\n" + "\n".join(m["content"] for m in candidates[-2:])
        source_text, source_tokens, chunks_used, chunks_total = _relevant(sources, query, source_budget)
    if chunks_used < 0:
        data = [{"title": "Источники проекта", "text": source_text}] + volatile   # целиком — постоянная часть, раньше изменчивой
    else:
        data = volatile + [{"title": f"Источники проекта — {chunks_used} наиболее подходящих фрагментов из {chunks_total}", "text": source_text}]
    used += source_tokens

    # 3. История: от свежих к ранним, пока помещается (потолок истории администратора — поверх окна).
    left = min(rest - used, history_cap_tokens) if history_cap_tokens is not None else rest - used
    kept, history_tokens = [], 0
    for m in reversed(candidates):
        t = tokens(m["content"])
        if history_tokens + t > left:
            break
        history_tokens += t
        kept.append(m)
    kept.reverse()
    dropped = len(candidates) - len(kept)
    if dropped > 0 and summary_text == "":
        data.append({"title": "Примечание", "text": f"Ранняя часть диалога ({dropped} сообщ.) не поместилась в контекст модели. "
                                                     "Если для ответа нужны те сведения — попроси пользователя напомнить."})

    system = prompt.build(layers, data)
    messages = [{"role": m["role"], "content": m["content"]} for m in kept] + [{"role": "user", "content": current}]
    report = {
        "window": context_window, "reserve": max_output, "budget": budget,
        "instructions": tokens(instructions), "summary": tokens(summary_text), "memory": tokens(state_text),
        "sources": source_tokens, "sources_total": sources_total, "history": history_tokens, "history_messages": len(kept),
        "history_dropped": dropped, "current": tokens(current),
    }
    report["total"] = tokens(system) + history_tokens + report["current"]
    # Сводку пора обновить: часть истории не вошла, либо непокрытая история уже больше 60 % своей доли.
    needs_summary = dropped > 0 or history_total > int((rest - used) * 0.6)
    return {"system": system, "messages": messages, "report": report, "needsSummary": needs_summary and len(candidates) > 4, "droppedUncovered": dropped}


def _join_sources(sources: list[dict]) -> str:
    return "\n\n".join(f"[Источник {i}: {s['title']}]\n{s['text']}" for i, s in enumerate(sources, 1))


def _norm(text: str) -> str:
    return _WS.sub(" ", text).lower()


def _relevant(sources: list[dict], query: str, budget: int) -> tuple[str, int, int, int]:
    """Фрагменты, наиболее близкие вопросу (совпадение основ слов с весом редкости), без повторов, в исходном порядке.
    Первый фрагмент каждого источника получает небольшой бонус (обычно там тема документа)."""
    chunks = []
    for si, s in enumerate(sources):
        parts = _chunk(s["text"])
        for ci, text in enumerate(parts):
            chunks.append({"s": si, "c": ci, "n": len(parts), "title": s["title"], "text": text, "stems": Counter(_stems(text))})
    df: Counter = Counter()
    for c in chunks:
        df.update(c["stems"].keys())
    q = dict.fromkeys(_stems(query))
    n = max(1, len(chunks))
    for c in chunks:
        score = 0.3 if c["c"] == 0 else 0.0
        for st in q:
            if st in c["stems"]:
                score += math.log(1 + n / df[st]) * (1 + math.log(c["stems"][st]))
        c["score"] = score
    chunks.sort(key=lambda c: (-c["score"], c["s"], c["c"]))
    picked, seen, total = [], set(), 0
    for c in chunks:
        key = _norm(c["text"])
        t = tokens(c["text"]) + 12
        if key in seen or total + t > budget:
            continue   # повтор того же текста в другом источнике не передаётся дважды
        seen.add(key)
        total += t
        picked.append(c)
    picked.sort(key=lambda c: (c["s"], c["c"]))
    # Повторы на уровне абзацев: один и тот же абзац из разных источников или фрагментов передаётся один раз.
    paras, out, total = set(), [], 0
    for c in picked:
        keep = []
        for para in _PARA.split(c["text"]):
            key = _norm(para).strip(prompt.PHP_WS)
            if para.strip(prompt.PHP_WS) != "" and key not in paras:
                paras.add(key)
                keep.append(para)
        if keep:
            block = f"[Источник {c['s'] + 1}: {c['title']}, фрагмент {c['c'] + 1} из {c['n']}]\n" + "\n\n".join(keep)
            total += tokens(block)
            out.append(block)
    return "\n\n".join(out), total, len(out), len(chunks)


def _chunk(text: str) -> list[str]:
    """Абзацы, собранные в куски до CHUNK_CHARS; длинный абзац режется на срезы фиксированной длины."""
    out, buf = [], ""
    for para in _PARA.split(text):
        if len(para) > CHUNK_CHARS:
            if buf:
                out.append(buf)
                buf = ""
            cut = len(para) - (len(para) - 1) % CHUNK_CHARS - 1   # всё, кроме последнего неполного (или ровно полного) среза
            out += [para[i:i + CHUNK_CHARS] for i in range(0, cut, CHUNK_CHARS)]
            para = para[cut:]
        if buf and len(buf) + len(para) > CHUNK_CHARS:
            out.append(buf)
            buf = ""
        buf += ("\n\n" if buf else "") + para
    if buf.strip(prompt.PHP_WS) != "":
        out.append(buf)
    return out


def _stems(text: str) -> list[str]:
    """Грубые основы слов (первые 6 букв слов от 4 букв) — без морфологии, но устойчиво к окончаниям."""
    return [w[:6] for w in _WORD.findall(text.lower())]


def _clip(text: str, tok: int) -> str:
    limit = int(tok * CHARS_PER_TOKEN)
    return text if len(text) <= limit else text[:max(0, limit - 1)] + "…"
