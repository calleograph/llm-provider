"""Внутренний сервис обезличивания (движок — app/privacy).

Сервис без состояния: настройки (словарь, исключения, внутренние домены, свой профиль) присылает PHP-приложение в каждом запросе,
поэтому БД здесь нет. Доступен только во внутренней сети Compose и только с общим токеном INTERNAL_API_TOKEN.

Fail closed: любая ошибка (модели не загружены, тайм-аут, ошибка движка, утечка при повторной проверке) — HTTP 4xx/5xx без текста,
PHP-шлюз в этом случае ничего не отправляет во внешнюю LLM. Тексты и найденные значения в журнал не пишутся — только объёмы и счётчики.
"""
from __future__ import annotations

import asyncio
import contextlib
import hmac
import logging
import os
import threading
import time

from fastapi import Body, FastAPI, Header, HTTPException, Query, Request

from .privacy import fetch_models
from .privacy.engine import ACTIONS, CATEGORIES, PROFILES, Anonymizer, PrivacyError
from .privacy.extract import SUPPORTED, ExtractError, extract
from .privacy.names import NLP_DIR, NlpUnavailable, models
from .privacy.policy import PROFILE_TITLES, build_policy
from .verify import independent_check

VERSION = os.environ.get("APP_VERSION", "0.0.0")
TOKEN = os.environ.get("INTERNAL_API_TOKEN", "")
TIMEOUT_S = float(os.environ.get("ANON_TIMEOUT_S", "120"))
MAX_CHARS = int(os.environ.get("ANON_MAX_CHARS", "2000000"))
MAX_FILE_MB = int(os.environ.get("ANON_MAX_FILE_MB", "50"))
_slots = asyncio.Semaphore(int(os.environ.get("ANON_CONCURRENCY", "4")))
log = logging.getLogger("anonymizer")
logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(name)s %(message)s")

@contextlib.asynccontextmanager
async def _lifespan(_app):
    threading.Thread(target=_warm, daemon=True).start()
    yield


app = FastAPI(title="anonymizer", version=VERSION, docs_url=None, redoc_url=None, openapi_url=None, lifespan=_lifespan)
_state = {"ready": False, "error": None, "load_ms": None, "requests": 0, "failures": 0}


class Cfg:
    """Поля настроек, которые policy.build_policy читает как атрибуты."""

    def __init__(self, d: dict):
        self.terms = [t for t in (d.get("terms") or []) if isinstance(t, dict) and str(t.get("term", "")).strip()][:5000]
        self.exceptions = [str(x) for x in (d.get("exceptions") or []) if str(x).strip()][:5000]
        self.internal_domains = [str(x) for x in (d.get("internal_domains") or []) if str(x).strip()][:500]
        acts = d.get("custom_actions") or {}
        self.custom_actions = {k: v for k, v in acts.items() if k in CATEGORIES and k != "secret" and v in ACTIONS}
        self.custom_person_format = d.get("custom_person_format") if d.get("custom_person_format") in ("short", "role") else "short"
        self.custom_org_scope = d.get("custom_org_scope") if d.get("custom_org_scope") in ("legal", "all") else "legal"


def _auth(token: str | None) -> None:
    if not TOKEN or not token or not hmac.compare_digest(token, TOKEN):
        raise HTTPException(status_code=401, detail="unauthorized")


def _warm() -> None:
    """Модели NER и морфология загружаются один раз на процесс (синглтон names.models) — до первого запроса, а не на каждый."""
    t0 = time.monotonic()
    try:
        if not fetch_models.ready(NLP_DIR):
            raise NlpUnavailable(f"модели NER не найдены или повреждены в {NLP_DIR}")
        models()
        Anonymizer(build_policy(Cfg({}), "strict")).process("Иван Петров позвонил в ООО «Ромашка».")
        _state.update(ready=True, error=None, load_ms=int((time.monotonic() - t0) * 1000))
        log.info("models ready in %d ms", _state["load_ms"])
    except Exception as exc:  # noqa: BLE001 — сервис остаётся живым и честно отвечает «не готов»
        _state.update(ready=False, error=type(exc).__name__ + ": " + str(exc)[:200])
        log.error("models failed: %s", _state["error"])


@app.get("/healthz")
async def healthz():
    return {"ok": True, "version": VERSION}


@app.get("/readyz")
async def readyz():
    body = {"ready": _state["ready"], "version": VERSION, "load_ms": _state["load_ms"], "error": _state["error"],
            "requests": _state["requests"], "failures": _state["failures"]}
    if not _state["ready"]:
        raise HTTPException(status_code=503, detail=body)
    return body


@app.get("/v1/catalog")
async def catalog(x_internal_token: str | None = Header(default=None)):
    _auth(x_internal_token)
    return {"categories": [{"id": k, "title": v, "mandatory": k == "secret"} for k, v in CATEGORIES.items()], "actions": list(ACTIONS),
            "profiles": {k: {"title": PROFILE_TITLES[k], "person_format": v["person_format"], "org_scope": v["org_scope"], "actions": v["actions"]}
                         for k, v in PROFILES.items()},
            "formats": SUPPORTED, "nlp": {"ready": _state["ready"], "engine": "slovnet NER + navec (Natasha, MIT), pymorphy3 (MIT)"}}


def _process(texts: list[str], profile: str, cfg: Cfg, participants: list[str]) -> dict:
    """Все тексты — одной сессией замен: один и тот же человек получает одно обозначение в сообщении, инструкции и файлах (исправление 0.56.1)."""
    policy = build_policy(cfg, profile)
    anon = Anonymizer(policy, participants)
    out, reps, warnings, candidates = [], [], [], []
    for i, text in enumerate(texts):
        if not text.strip():
            out.append(text)
            continue
        res = anon.process(text)
        leaks = independent_check(res.text, policy)
        if leaks:
            # Один дополнительный проход той же сессией замен (обозначения согласованы), как движок делает с секретами;
            # осталось и после него — блокировка.
            again = anon.process(res.text)
            res.text, res.warnings = again.text, res.warnings + again.warnings + [f"Повторная проверка: дозаменено ({', '.join(sorted(set(leaks)))})"]
            res.replacements += again.replacements
            leaks = independent_check(res.text, policy)
        if leaks:
            raise PrivacyError("verification_failed", "Повторная проверка нашла неубранные данные (" + ", ".join(sorted(set(leaks))) + "): отправка остановлена.")
        out.append(res.text)
        warnings += res.warnings
        reps += [(i, r) for r in res.replacements]
        candidates += [(i, c) for c in res.candidates]
    return {"texts": out, "reps": reps, "warnings": warnings, "candidates": candidates}


@app.post("/v1/anonymize")
async def anonymize(body: dict = Body(...), x_internal_token: str | None = Header(default=None)):
    """{texts: [str], profile: basic|strict|custom, settings: {...}, participants: [ФИО], detail: bool} → {texts, stats, replaced, warnings, ms}.

    detail=true (только лаборатория администратора) добавляет список замен с исходными значениями — PHP не сохраняет и не журналирует его."""
    _auth(x_internal_token)
    texts = body.get("texts")
    if not isinstance(texts, list) or not all(isinstance(t, str) for t in texts) or len(texts) > 200:
        raise HTTPException(status_code=422, detail="texts: список строк (не больше 200)")
    if sum(len(t) for t in texts) > MAX_CHARS:
        raise HTTPException(status_code=413, detail=f"Объём больше {MAX_CHARS} знаков")
    profile = str(body.get("profile") or "basic")
    if profile not in PROFILE_TITLES:
        raise HTTPException(status_code=422, detail="profile: basic, strict или custom")
    cfg = Cfg(body.get("settings") if isinstance(body.get("settings"), dict) else {})
    parts = [str(p).strip() for p in (body.get("participants") or []) if str(p).strip()][:200]
    if not _state["ready"]:
        raise HTTPException(status_code=503, detail="nlp_unavailable")
    t0 = time.monotonic()
    _state["requests"] += 1
    async with _slots:
        try:
            r = await asyncio.wait_for(asyncio.to_thread(_process, texts, profile, cfg, parts), TIMEOUT_S)
        except asyncio.TimeoutError:
            _state["failures"] += 1
            raise HTTPException(status_code=504, detail="timeout") from None
        except NlpUnavailable:
            _state["failures"] += 1
            raise HTTPException(status_code=503, detail="nlp_unavailable") from None
        except PrivacyError as exc:
            _state["failures"] += 1
            log.warning("blocked code=%s chars=%d", exc.code, sum(map(len, texts)))
            raise HTTPException(status_code=422, detail={"code": exc.code, "message": exc.message}) from None
        except Exception as exc:  # noqa: BLE001 — любой сбой движка = отказ, без подробностей текста
            _state["failures"] += 1
            log.error("engine failure %s", type(exc).__name__)
            raise HTTPException(status_code=500, detail="anonymizer_failed") from None
    ms = int((time.monotonic() - t0) * 1000)
    stats: dict[str, int] = {}
    for _, rep in r["reps"]:
        stats[rep.category] = stats.get(rep.category, 0) + 1
    log.info("ok profile=%s texts=%d chars=%d replaced=%d ms=%d", profile, len(texts), sum(map(len, texts)), len(r["reps"]), ms)
    resp = {"texts": r["texts"], "stats": stats, "replaced": len(r["reps"]), "warnings": r["warnings"], "ms": ms, "verified": True}
    if body.get("detail"):
        resp["replacements"] = [{"text": i, "start": x.start, "end": x.end, "category": x.category, "rule": x.rule, "original": x.original,
                                 "replacement": x.replacement, "evidence": x.evidence} for i, x in r["reps"]]
        resp["candidates"] = [{"text": i, "start": c["start"], "end": c["end"], "original": texts[i][c["start"]:c["end"]], "reason": c["reason"]}
                              for i, c in r["candidates"]]
    return resp


@app.post("/v1/extract")
async def extract_file(request: Request, name: str = Query(..., max_length=200), x_internal_token: str | None = Header(default=None)):
    """Текст документа (DOCX/ODT с колонтитулами, сносками и примечаниями; PDF — только текстовый слой). Файл читается в память, на диск не пишется."""
    _auth(x_internal_token)
    limit = MAX_FILE_MB * 1024 * 1024
    buf = bytearray()
    async for chunk in request.stream():
        buf += chunk
        if len(buf) > limit:
            raise HTTPException(status_code=413, detail="too_large")
    if not buf:
        raise HTTPException(status_code=422, detail="empty")
    try:
        text, info = await asyncio.wait_for(asyncio.to_thread(extract, name, bytes(buf)), 60)
    except ExtractError as exc:
        raise HTTPException(status_code=422, detail={"code": "extract_failed", "message": str(exc)}) from None
    except asyncio.TimeoutError:
        raise HTTPException(status_code=504, detail="timeout") from None
    finally:
        buf.clear()
    return {"text": text, "info": info}
