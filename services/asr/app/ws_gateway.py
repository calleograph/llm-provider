"""Голосовая диктовка сообщений чата: WebSocket `/ws/dictation`.

Отличие от донора — только вход: PHP-приложение (PHP-FPM не держит WebSocket) выдаёт одноразовый билет, подписанный общим секретом
DICTATION_SECRET (HMAC-SHA256), со сроком 60 с и настройками диктовки; шлюз проверяет подпись, срок и одноразовость (Redis SET NX).
Дальше — тот же конвейер: браузер шлёт двоичные кадры PCM (int16, 16 кГц, моно) и `{"type":"stop"|"cancel"}`; в ответ `ready`, `final`
(с номером — повтор клиент отбрасывает), `busy`, `limit`, `done`, `error`. Распознаёт DictationManager через Redis Streams.
Звук не сохраняется: потоки Redis удаляются по завершении, текст в журнал не пишется.
"""
from __future__ import annotations

import asyncio
import base64
import binascii
import contextlib
import hashlib
import hmac
import json
import logging
import os
import time
import uuid
from collections.abc import Callable

from fastapi import FastAPI, WebSocket, WebSocketDisconnect
from fastapi.responses import JSONResponse
from redis.asyncio import Redis

log = logging.getLogger("asr.dictation.ws")

CONTROL = "asr:dictation:control"
ACTIVE = "dictation:active"                 # ZSET: sid → срок, до которого диктовка считается идущей (для предела одновременных)
KEY_TTL = 600
MAX_FRAME = 64 * 1024
BYTES_PER_SECOND = 32000
DONE_WAIT_S = 35.0
PER_HOUR = 120                              # диктовок на пользователя в час
TICKET_MAX_AGE = 120


def in_key(sid: str) -> str:
    return f"asr:dict:{sid}:in"


def out_key(sid: str) -> str:
    return f"asr:dict:{sid}:out"


def _b64d(s: str) -> bytes:
    return base64.urlsafe_b64decode(s + "=" * (-len(s) % 4))


def verify_ticket(ticket: str, secret: str, now: float | None = None) -> dict | None:
    """`base64url(json).base64url(hmac)` → данные билета или None. Формат совпадает с PHP `DictationTicket::issue()`."""
    if not secret or not ticket or ticket.count(".") != 1 or len(ticket) > 2048:
        return None
    body, sig = ticket.split(".")
    want = hmac.new(secret.encode(), body.encode(), hashlib.sha256).digest()
    try:
        if not hmac.compare_digest(_b64d(sig), want):
            return None
        data = json.loads(_b64d(body))
    except (binascii.Error, ValueError):
        return None
    now = time.time() if now is None else now
    if not isinstance(data, dict) or not isinstance(data.get("exp"), int) or not (now <= data["exp"] <= now + TICKET_MAX_AGE):
        return None
    if not isinstance(data.get("u"), str) or not isinstance(data.get("n"), str) or len(data["n"]) < 16:
        return None
    return data


def origin_allowed(origin: str | None, allowed: list[str]) -> bool:
    return origin is None or not allowed or origin.rstrip("/") in allowed


async def _send(ws: WebSocket, **msg) -> None:
    with contextlib.suppress(Exception):
        await ws.send_text(json.dumps(msg, ensure_ascii=False))


async def within_limit(redis: Redis, key: str, limit: int, window: int) -> bool:
    n = await redis.incr(key)
    if n == 1:
        await redis.expire(key, window)
    return n <= limit


def mount_dictation(app: FastAPI, redis: Redis, snapshot: Callable[[], dict]) -> None:
    secret = os.environ.get("DICTATION_SECRET", "")
    allowed = [o.strip().rstrip("/") for o in os.environ.get("APP_ORIGINS", "").split(",") if o.strip()]

    @app.get("/ws/dictation")
    async def dictation_needs_upgrade():
        # обычный GET сюда доходит, когда внешний reverse proxy не передал Upgrade/Connection; без этого ответа было бы немое «404»
        return JSONResponse({"detail": "Нужно WebSocket-соединение: reverse proxy перед сайтом должен передавать заголовки Upgrade и Connection."},
                            status_code=426, headers={"Upgrade": "websocket"})

    @app.websocket("/ws/dictation")
    async def dictation(ws: WebSocket):
        if not origin_allowed(ws.headers.get("origin"), allowed):
            await ws.close(code=4403)
            return
        data = verify_ticket(ws.query_params.get("ticket", ""), secret)
        # одноразовость: повтор перехваченного билета не проходит
        if data is None or not await redis.set(f"dictation:ticket:{data['n']}", "1", nx=True, ex=TICKET_MAX_AGE * 2):
            await ws.close(code=4401)
            return
        max_seconds = max(5, min(int(data.get("ms") or 120), 600))
        max_concurrent = max(1, min(int(data.get("mc") or 4), 64))
        await ws.accept()
        snap = snapshot()
        if not snap.get("model_loaded"):
            await _send(ws, type="error", code="asr_unavailable", message="Распознавание речи сейчас недоступно. Введите текст вручную.")
            await ws.close(code=1011)
            return
        user = hashlib.sha256(data["u"].encode()).hexdigest()[:32]
        if not await within_limit(redis, f"rl:dictation:{user}", PER_HOUR, 3600):
            await _send(ws, type="error", code="rate", message="Слишком много диктовок подряд. Попробуйте позже.")
            await ws.close(code=4429)
            return
        sid = uuid.uuid4().hex
        now = time.time()
        await redis.zremrangebyscore(ACTIVE, 0, now)
        if await redis.zcard(ACTIVE) >= max_concurrent:
            await _send(ws, type="error", code="busy", message="Сейчас диктуют слишком многие — распознавание занято. Попробуйте через минуту.")
            await ws.close(code=4429)
            return
        await redis.zadd(ACTIVE, {sid: now + max_seconds + DONE_WAIT_S})
        await redis.xadd(CONTROL, {"type": "start", "sid": sid, "max_seconds": str(max_seconds)}, maxlen=1000, approximate=True)
        await _send(ws, type="ready", max_seconds=max_seconds)
        state = {"stopping": False, "cancel": False, "bytes": 0, "stop_at": None}
        limit = max_seconds * BYTES_PER_SECOND

        async def eof() -> None:
            if not state["stopping"]:
                state["stopping"], state["stop_at"] = True, time.monotonic()
                await redis.xadd(in_key(sid), {"eof": "1"})

        async def pump_in() -> None:
            while True:
                msg = await ws.receive()
                if msg.get("type") == "websocket.disconnect":
                    state["cancel"] = True
                    await eof()
                    return
                if msg.get("bytes") is not None:
                    chunk = msg["bytes"]
                    if state["stopping"] or not chunk or len(chunk) > MAX_FRAME or len(chunk) % 2:
                        continue
                    state["bytes"] += len(chunk)
                    await redis.xadd(in_key(sid), {"pcm": base64.b64encode(chunk).decode()}, maxlen=5000, approximate=True)
                    await redis.expire(in_key(sid), KEY_TTL)
                    if state["bytes"] >= limit:
                        await _send(ws, type="limit", max_seconds=max_seconds)
                        await eof()
                elif msg.get("text"):
                    try:
                        kind = json.loads(msg["text"]).get("type")
                    except (ValueError, AttributeError):
                        continue
                    if kind == "cancel":
                        state["cancel"] = True
                        await eof()
                        return
                    if kind == "stop":
                        await eof()

        async def pump_out() -> None:
            last = "0-0"
            while True:
                if state["stop_at"] is not None and time.monotonic() - state["stop_at"] > DONE_WAIT_S:
                    await _send(ws, type="error", code="timeout", message="Распознавание не ответило вовремя. Уже распознанный текст сохранён в поле ввода.")
                    return
                res = await redis.xread({out_key(sid): last}, block=1000, count=20)
                for _s, items in res or []:
                    for mid, f in items:
                        last = mid
                        kind = f.get("type")
                        if kind == "final":
                            await _send(ws, type="final", seq=int(f.get("seq") or 0), text=f.get("text") or "")
                        elif kind == "busy":
                            await _send(ws, type="busy", seq=int(f.get("seq") or 0), message="Распознавание перегружено — часть фразы не распознана")
                        elif kind in ("done", "error"):
                            await _send(ws, **({"type": "done", "timeout": f.get("timeout") == "1"} if kind == "done" else
                                               {"type": "error", "code": f.get("code") or "failed", "message": f.get("message") or "Сбой распознавания"}))
                            return

        reader = asyncio.create_task(pump_in())
        writer = asyncio.create_task(pump_out())
        try:
            done, _pending = await asyncio.wait({reader, writer}, return_when=asyncio.FIRST_COMPLETED)
            if reader in done and not state["cancel"]:
                await writer                                       # клиент закончил отправку — дождаться окончательного текста
        except WebSocketDisconnect:
            pass
        except Exception:  # noqa: BLE001
            log.exception("Сбой диктовки")
        finally:
            for t in (reader, writer):
                t.cancel()
                with contextlib.suppress(asyncio.CancelledError, Exception):
                    await t
            if not state["stopping"]:
                with contextlib.suppress(Exception):
                    await redis.xadd(in_key(sid), {"eof": "1"})
            with contextlib.suppress(Exception):
                await redis.zrem(ACTIVE, sid)
                await redis.expire(in_key(sid), 60)
                await redis.delete(out_key(sid))
            with contextlib.suppress(Exception):
                await ws.close()
