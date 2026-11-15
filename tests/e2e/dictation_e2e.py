#!/usr/bin/env python3
"""Сквозная проверка диктовки на работающем стенде: билет (как его выдаёт api.php) → nginx → WS-шлюз ASR → Redis → GigaAM → текст.

Запуск: python3 tests/e2e/dictation_e2e.py --env /путь/к/.env [--url ws://127.0.0.1:PORT] [--wav файл.wav] [--ticket билет]
Звук отправляется кадрами по 100 мс (как AudioWorklet браузера). Код 0 — распознан непустой текст.
"""
from __future__ import annotations

import argparse
import asyncio
import base64
import hashlib
import hmac
import json
import os
import sys
import time
import wave

import websockets


def env_value(path: str, key: str) -> str:
    for line in open(path, encoding="utf-8"):
        if line.startswith(key + "="):
            return line.split("=", 1)[1].strip().strip('"')
    return ""


def ticket(secret: str) -> str:
    body = base64.urlsafe_b64encode(json.dumps({"u": "e2e@corp.local", "exp": int(time.time()) + 60, "n": os.urandom(16).hex(), "ms": 60, "mc": 4}).encode()).rstrip(b"=").decode()
    sig = base64.urlsafe_b64encode(hmac.new(secret.encode(), body.encode(), hashlib.sha256).digest()).rstrip(b"=").decode()
    return f"{body}.{sig}"


async def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--env", required=True)
    ap.add_argument("--url")
    ap.add_argument("--ticket", help="билет, выданный приложением (api.php?action=dictation_ticket); без него — свой, как его выдаёт приложение")
    ap.add_argument("--wav", default=os.path.join(os.path.dirname(__file__), "../../services/asr/app/assets/selftest_ru.wav"))
    a = ap.parse_args()
    url = a.url or f"ws://127.0.0.1:{env_value(a.env, 'APP_PORT')}"
    w = wave.open(a.wav)
    assert w.getframerate() == 16000 and w.getnchannels() == 1 and w.getsampwidth() == 2, "нужен WAV PCM16 16 кГц моно"
    pcm = w.readframes(w.getnframes())
    t0 = time.monotonic()
    texts: list[str] = []
    async with websockets.connect(f"{url}/ws/dictation?ticket={a.ticket or ticket(env_value(a.env, 'DICTATION_SECRET'))}", max_size=2**20) as ws:
        ready = json.loads(await ws.recv())
        assert ready["type"] == "ready", ready
        for i in range(0, len(pcm), 3200):
            await ws.send(pcm[i:i + 3200])
            await asyncio.sleep(0.02)
        sent = time.monotonic()
        await ws.send(json.dumps({"type": "stop"}))
        async for raw in ws:
            msg = json.loads(raw)
            if msg["type"] == "final":
                texts.append(msg["text"])
            elif msg["type"] in ("done", "error"):
                print("end:", msg)
                break
    text = " ".join(texts).strip()
    print(f"audio={len(pcm) / 32000:.1f}s total={time.monotonic() - t0:.1f}s after_stop={time.monotonic() - sent:.1f}s fragments={len(texts)}")
    print("text:", text)
    return 0 if text else 1


if __name__ == "__main__":
    sys.exit(asyncio.run(main()))
