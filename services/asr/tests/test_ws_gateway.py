"""WS-шлюз диктовки LLM-провайдера: вход по одноразовому билету PHP, протокол ready → final → done, отказ без билета, звук не остаётся в Redis."""
from __future__ import annotations

import base64
import hashlib
import hmac
import json
import threading
import time

import fakeredis
import pytest
from fastapi import FastAPI
from fastapi.testclient import TestClient
from starlette.websockets import WebSocketDisconnect

from app import ws_gateway
from app.ws_gateway import CONTROL, in_key, mount_dictation, out_key, verify_ticket

SECRET = "s" * 48


def ticket(secret=SECRET, exp=None, nonce="n" * 32, user="alice@corp.local", **kw) -> str:
    body = base64.urlsafe_b64encode(json.dumps({"u": user, "exp": exp or int(time.time()) + 60, "n": nonce, **kw}).encode()).rstrip(b"=").decode()
    sig = base64.urlsafe_b64encode(hmac.new(secret.encode(), body.encode(), hashlib.sha256).digest()).rstrip(b"=").decode()
    return f"{body}.{sig}"


def test_ticket_signature_expiry_and_shape():
    assert verify_ticket(ticket(), SECRET)["u"] == "alice@corp.local"
    assert verify_ticket(ticket(secret="x" * 48), SECRET) is None
    assert verify_ticket(ticket(exp=int(time.time()) - 1), SECRET) is None
    assert verify_ticket(ticket(exp=int(time.time()) + 3600), SECRET) is None, "слишком долгий срок — не наш билет"
    assert verify_ticket(ticket(nonce="short"), SECRET) is None
    assert verify_ticket("garbage", SECRET) is None and verify_ticket(ticket(), "") is None


@pytest.fixture
def stack(monkeypatch):
    monkeypatch.setenv("DICTATION_SECRET", SECRET)
    monkeypatch.setenv("APP_ORIGINS", "https://chat.example.com")
    server = fakeredis.FakeServer()
    app = FastAPI()
    ready = {"model_loaded": True}
    r_async = fakeredis.FakeAsyncRedis(server=server, decode_responses=True)
    mount_dictation(app, r_async, lambda: ready)
    sync = fakeredis.FakeRedis(server=server, decode_responses=True)
    return app, sync, ready


def _fake_asr(sync, stop):
    """Сторона ASR (DictationManager): принять start, дочитать звук до eof, ответить final + done."""
    last = "0-0"
    while not stop.is_set():
        for _s, items in sync.xread({CONTROL: last}, count=10, block=100) or []:
            for mid, f in items:
                last = mid
                sid, got, pos = f["sid"], 0, "0-0"
                while not stop.is_set():
                    res = sync.xread({in_key(sid): pos}, count=50, block=100) or []
                    eof = False
                    for _k, frames in res:
                        for fid, fr in frames:
                            pos = fid
                            if fr.get("eof"):
                                eof = True
                            else:
                                got += len(base64.b64decode(fr["pcm"]))
                    if eof:
                        break
                sync.xadd(out_key(sid), {"type": "final", "seq": "1", "text": f"распознано {got} байт"})
                sync.xadd(out_key(sid), {"type": "done"})


def test_full_dictation_cycle_and_single_use_ticket(stack):
    app, sync, _ = stack
    stop = threading.Event()
    th = threading.Thread(target=_fake_asr, args=(sync, stop), daemon=True)
    th.start()
    t = ticket(ms=30, mc=2)
    try:
        with TestClient(app) as c:
            with c.websocket_connect(f"/ws/dictation?ticket={t}", headers={"origin": "https://chat.example.com"}) as ws:
                assert ws.receive_json() == {"type": "ready", "max_seconds": 30}
                ws.send_bytes(b"\x00\x01" * 1600)
                ws.send_bytes(b"\x01")                          # нечётная длина — отброшен
                ws.send_text(json.dumps({"type": "stop"}))
                assert ws.receive_json() == {"type": "final", "seq": 1, "text": "распознано 3200 байт"}
                assert ws.receive_json()["type"] == "done"
            with pytest.raises(WebSocketDisconnect) as e, c.websocket_connect(f"/ws/dictation?ticket={t}") as ws:
                ws.receive_json()
            assert e.value.code == 4401, "повтор билета не проходит"
    finally:
        stop.set()
        th.join(2)
    assert not [k for k in sync.keys("asr:dict:*:out")], "поток результатов удалён"


def test_refusals_without_ticket_foreign_origin_and_unloaded_model(stack):
    app, _sync, ready = stack
    with TestClient(app) as c:
        for url, headers, code in [("/ws/dictation", {}, 4401), (f"/ws/dictation?ticket={ticket(nonce='o' * 32)}", {"origin": "https://evil.example.org"}, 4403)]:
            with pytest.raises(WebSocketDisconnect) as e, c.websocket_connect(url, headers=headers) as ws:
                ws.receive_json()
            assert e.value.code == code
        ready["model_loaded"] = False
        with c.websocket_connect(f"/ws/dictation?ticket={ticket(nonce='m' * 32)}") as ws:
            assert ws.receive_json()["code"] == "asr_unavailable"


def test_concurrency_limit(stack, monkeypatch):
    app, sync, _ = stack
    sync.zadd(ws_gateway.ACTIVE, {"other": time.time() + 100})
    with TestClient(app) as c, c.websocket_connect(f"/ws/dictation?ticket={ticket(nonce='c' * 32, mc=1)}") as ws:
        assert ws.receive_json()["code"] == "busy"


def test_plain_get_without_upgrade_explains_the_proxy_problem(stack):
    """Прокси без Upgrade/Connection даёт обычный GET: вместо немого 404 — 426 с понятной причиной."""
    app, _sync, _ready = stack
    with TestClient(app) as c:
        r = c.get("/ws/dictation")
    assert r.status_code == 426 and "Upgrade" in r.json()["detail"] and r.headers["upgrade"] == "websocket"
