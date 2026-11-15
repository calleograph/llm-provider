"""DLP: правила поиска чувствительного содержимого в том, что ушло во внешние нейросети (llm_outbound).

Правило — набор строк одного вида:
  * words — фразы, по одной в строке; каждое слово совпадает по началу («коммерческ тайн» найдёт «коммерческой тайны»),
    регистр и ё/е не различаются;
  * regex — регулярные выражения Python, по одному в строке, совпадение любого из них.
Встроенные шаблоны (builtin_key) создаются при установке; их можно править и выключать, удалить нельзя. Некоторым нужна
проверка контрольной суммы (карта — Луна, СНИЛС, ИНН): совпадение без верной суммы не считается.

Проверка идёт при записи каждого запроса (Telemetry.record) — фильтр по правилу в админке работает по индексу, а не
перебором текстов. После правки правил старые записи перепроверяет планировщик (Telemetry.rescan_step)."""

from __future__ import annotations

import re
import sqlite3
from dataclasses import dataclass

from markupsafe import Markup, escape

from ..db import now_iso

SEVERITIES = {3: "Критично", 2: "Важно", 1: "Внимание"}
KINDS = {"words": "Слова и фразы", "regex": "Регулярные выражения"}
MAX_PATTERN_CHARS = 20000


def _luhn(s: str) -> bool:
    d = [int(c) for c in s if c.isdigit()]
    if not 13 <= len(d) <= 19 or len(set(d)) == 1:
        return False
    # удваивается каждая вторая цифра, считая от последней
    total = sum((x * 2 - 9 if x > 4 else x * 2) if i % 2 == len(d) % 2 else x for i, x in enumerate(d))
    return total % 10 == 0


def _snils(s: str) -> bool:
    d = [int(c) for c in s if c.isdigit()]
    if len(d) != 11:
        return False
    total = sum(x * (9 - i) for i, x in enumerate(d[:9]))
    check = total % 101 % 100
    return check == d[9] * 10 + d[10]


def _inn(s: str) -> bool:
    d = [int(c) for c in re.sub(r"\D", "", s)[-12:]]
    w = lambda ws, n: sum(a * b for a, b in zip(ws, d)) % 11 % 10 == d[n]  # noqa: E731
    if len(d) == 10:
        return w((2, 4, 10, 3, 5, 9, 4, 6, 8), 9)
    if len(d) == 12:
        return w((7, 2, 4, 10, 3, 5, 9, 4, 6, 8), 10) and w((3, 7, 2, 4, 10, 3, 5, 9, 4, 6, 8), 11)
    return False


VALIDATORS = {"cards": _luhn, "snils": _snils, "inn": _inn}

# (builtin_key, название, вид, важность, шаблон). Порядок — порядок в админке.
BUILTIN: tuple[tuple[str, str, str, int, str], ...] = (
    ("secrets", "Пароли, ключи и токены доступа", "regex", 3, "\n".join((
        r"(?:парол[ьяюе]\w*|password|passwd|pwd|secret|token|api[_-]?key)\s*[:=]\s*\S{4,}",
        r"-----BEGIN [A-Z ]*PRIVATE KEY-----",
        r"\b(?:gh[pousr]_[A-Za-z0-9]{36,}|github_pat_\w{22,}|sk-[A-Za-z0-9_-]{20,}|AKIA[0-9A-Z]{16}|xox[baprs]-[A-Za-z0-9-]{10,})\b",
    ))),
    ("cards", "Номера банковских карт", "regex", 3, r"\b\d{4}[ -]?\d{4}[ -]?\d{4}[ -]?\d{1,7}\b"),
    ("passport", "Паспортные данные", "regex", 3, r"паспорт\w*\D{0,30}\d{2}\s?\d{2}\s?\d{6}\b"),
    ("snils", "СНИЛС", "regex", 3, r"\b\d{3}-\d{3}-\d{3}[ -]\d{2}\b"),
    ("grif", "Грифы конфиденциальности", "words", 3, "\n".join((
        "коммерческ тайн", "конфиденциальн", "для служебного пользования", "ДСП", "строго секретн", "не для распространения",
        "NDA", "confidential", "internal use only",
    ))),
    ("inn", "ИНН", "regex", 2, r"ИНН\D{0,5}\d{10}(?:\d{2})?\b"),
    ("contacts", "Телефоны и e-mail (не обезличены)", "regex", 2, "\n".join((
        r"[\w.+-]+@[\w-]+(?:\.[\w-]+)*\.[a-zA-Zа-яА-Я]{2,}",
        r"(?<!\d)(?:\+7|8)[\s(-]*\d{3}[\s)-]*\d{3}[\s-]*\d{2}[\s-]*\d{2}(?!\d)",
    ))),
    ("infra", "Внутренние адреса и строки подключения", "regex", 2, "\n".join((
        r"\b(?:10|192\.168|172\.(?:1[6-9]|2\d|3[01]))(?:\.\d{1,3}){2,3}\b",
        r"\bjdbc:\w+:",
        r"\b(?:Server|Data Source|Host)=[^;\n]+;[^\n]*(?:Password|Pwd)=",
        r"\b(?:postgres(?:ql)?|mysql|mongodb(?:\+srv)?|redis|amqp)://\S+:\S+@",
    ))),
    ("finance", "Финансы и персонал", "words", 1, "\n".join((
        "выручк", "EBITDA", "чистая прибыль", "бюджет", "зарплат", "заработн плат", "оклад", "премирован", "увольнен",
        "сокращени штат",
    ))),
    ("deals", "Сделки, тендеры, договоры", "words", 1, "\n".join((
        "тендер", "коммерческ предложени", "слияни", "поглощени", "M&A", "сделк", "котировк", "договор №",
    ))),
)


def seed_builtin(conn: sqlite3.Connection) -> None:
    """Встроенные шаблоны — только те, которых ещё нет (правки и выключение администратора не перезаписываются)."""
    have = {r[0] for r in conn.execute("SELECT builtin_key FROM dlp_rules WHERE builtin_key IS NOT NULL")}
    now = now_iso()
    for pos, (key, name, kind, severity, pattern) in enumerate(BUILTIN):
        if key not in have:
            conn.execute(
                "INSERT INTO dlp_rules (name, kind, pattern, severity, is_enabled, builtin_key, position, created_at, updated_at) "
                "VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?)", (name, kind, pattern, severity, key, pos, now, now))


def fold(s: str) -> str:
    """ё → е: полнотекстовый индекс SQLite считает их разными буквами."""
    return s.replace("ё", "е").replace("Ё", "Е")


def words_regex(pattern: str) -> str:
    """Фразы по строкам → одно выражение: слова по началу, между словами любые разделители."""
    alts = []
    for line in pattern.splitlines():
        chunks = [re.escape(fold(c)) + r"\w*" for c in line.split() if c]
        if chunks:
            alts.append(r"[\W_]+".join(chunks))
    return r"(?<!\w)(?:" + "|".join(alts) + ")" if alts else ""


def compile_pattern(kind: str, pattern: str) -> re.Pattern[str] | None:
    """None — шаблон пуст. re.error — ошибка в регулярном выражении (проверяется при сохранении)."""
    if kind == "words":
        rx = words_regex(pattern)
    else:
        lines = [ln for ln in pattern.splitlines() if ln.strip()]
        rx = "|".join(f"(?:{ln})" for ln in lines)
    return re.compile(rx, re.IGNORECASE | re.MULTILINE) if rx else None


@dataclass(frozen=True)
class Rule:
    id: int
    name: str
    severity: int
    regex: re.Pattern[str]
    validator: object | None

    def matches(self, text: str):
        for m in self.regex.finditer(text):
            if m.end() > m.start() and (self.validator is None or self.validator(m.group(0))):
                yield m


_cache: dict[tuple, Rule | None] = {}


def load_rules(conn: sqlite3.Connection, only_enabled: bool = True) -> list[Rule]:
    """Включённые правила; скомпилированные выражения кешируются по (id, updated_at, шаблон) — правило в БД меняется редко."""
    sql = "SELECT id, name, kind, pattern, severity, builtin_key, updated_at FROM dlp_rules"
    rules = []
    for r in conn.execute(sql + (" WHERE is_enabled = 1" if only_enabled else "") + " ORDER BY position, id"):
        key = (r["id"], r["updated_at"], r["kind"], r["pattern"], r["severity"], r["name"])
        if key not in _cache:
            try:
                rx = compile_pattern(r["kind"], r["pattern"])
            except re.error:
                rx = None
            _cache[key] = Rule(r["id"], r["name"], r["severity"], rx, VALIDATORS.get(r["builtin_key"] or "")) if rx else None
        if _cache[key] is not None:
            rules.append(_cache[key])
    return rules


def scan(rules: list[Rule], text: str) -> list[tuple[Rule, int]]:
    """[(правило, число совпадений)] для правил, нашедших хоть что-то."""
    text = fold(text)
    found = []
    for rule in rules:
        n = sum(1 for _ in rule.matches(text))
        if n:
            found.append((rule, n))
    return found


# ---- поиск по тексту (FTS5) ----
_ENDING = re.compile(r"[аяоеиыуюйь]+$")


def _stem(word: str) -> str:
    """Грубое отсечение окончания у русских слов («тайна» → «тайн»), поиск идёт по началу слова; фраза в кавычках — как есть."""
    stem = _ENDING.sub("", word)
    return stem if len(stem) >= 3 else word

_TERM = re.compile(r'(-?)"([^"]+)"|(-?)(\S+)')


def search_terms(q: str) -> tuple[list[list[str]], list[list[str]]]:
    """Запрос администратора → (включить, исключить): каждый элемент — список слов одной фразы.
    Синтаксис: слова (все должны быть, по началу слова), "точная фраза", -слово (исключить)."""
    pos: list[list[str]] = []
    neg: list[list[str]] = []
    for m in _TERM.finditer(fold(q)):
        minus, text = (m.group(1), m.group(2)) if m.group(2) is not None else (m.group(3), m.group(4))
        words = re.findall(r"\w+", text.lower())
        if m.group(2) is None:
            words = [_stem(w) for w in words]
        if words:
            (neg if minus else pos).append(words)
    return pos, neg


def fts_query(q: str) -> str | None:
    """Выражение MATCH для llm_outbound_fts; None — в запросе нет ни одного слова для поиска."""
    pos, neg = search_terms(q)
    if not pos:
        return None
    phrase = lambda words: '"' + " ".join(words) + '"*'  # noqa: E731 — слова в кавычках: спецсимволы FTS не работают
    expr = " AND ".join(phrase(w) for w in pos)
    if neg:
        expr = f"({expr}) NOT ({' OR '.join(phrase(w) for w in neg)})"
    return expr


def terms_regex(q: str) -> re.Pattern[str] | None:
    pos, _ = search_terms(q)
    if not pos:
        return None
    alts = [r"[\W_]+".join(re.escape(w).replace("е", "[её]") + r"\w*" for w in words) for words in pos]
    return re.compile(r"(?<!\w)(?:" + "|".join(alts) + ")", re.IGNORECASE)


# ---- подсветка ----
def highlight(text: str, rules: list[Rule], terms: re.Pattern[str] | None, *, limit: int | None = None,
              around_first: bool = False) -> tuple[Markup, bool]:
    """Текст с <mark>: совпадения поиска и правил DLP. limit — показать отрывок такой длины (вокруг первого совпадения,
    если around_first). Возвращает (разметка, обрезан ли текст)."""
    folded = fold(text)
    spans: list[tuple[int, int, str, str]] = []
    if terms is not None:
        spans += [(m.start(), m.end(), "hl-q", "") for m in terms.finditer(text)]
    for rule in rules:
        spans += [(m.start(), m.end(), f"hl-dlp hl-sev-{rule.severity}", rule.name) for m in rule.matches(folded)]
    # при пересечении побеждает то, что начинается раньше; при равном начале — подсветка поиска (правило видно по метке записи)
    spans.sort(key=lambda s: (s[0], s[2] != "hl-q", -s[1]))
    start, end = 0, len(text)
    if limit is not None and len(text) > limit:
        if around_first and spans:
            start = max(0, spans[0][0] - limit // 4)
            # начало отрывка — с границы слова, чтобы не резать слово пополам
            space = text.rfind(" ", 0, start) if start else -1
            start = space + 1 if space >= 0 and start - space < 40 else start
        end = min(len(text), start + limit)
    out, pos = [], start
    for s, e, cls, title in spans:
        if s < pos or e > end:
            continue
        out.append(escape(text[pos:s]))
        attr = f' title="{escape(title)}"' if title else ""
        out.append(Markup(f'<mark class="{cls}"{attr}>') + escape(text[s:e]) + Markup("</mark>"))
        pos = e
    out.append(escape(text[pos:end]))
    body = Markup("").join(out)
    cut = start > 0 or end < len(text)
    return (Markup("… ") if start > 0 else Markup("")) + body + (Markup(" …") if end < len(text) else Markup("")), cut
