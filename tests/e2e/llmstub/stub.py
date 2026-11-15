"""Управляемая заглушка OpenAI-совместимого LLM для сквозных проверок (HTTPS, своя тестовая CA).

Поведение задаётся именем модели: stub-ok — нормальный ответ; stub-empty — HTTP 200 с пустым content; stub-429 — 429 с Retry-After;
stub-500 — ошибка сервера; stub-slow — ответ через 30 с (для проверки таймаута); stub-drop — обрыв соединения посреди ответа;
stub-badjson — тело не JSON. GET /last — последнее полученное тело запроса (проверка, что наружу ушёл только обезличенный текст),
GET /count — число запросов по моделям.
"""
from __future__ import annotations

import json
import ssl
import sys
import threading
import time
from collections import Counter
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

LAST: dict = {}
COUNT: Counter = Counter()
LOCK = threading.Lock()


class H(BaseHTTPRequestHandler):
    def log_message(self, *a):  # тишина в журнале
        pass

    def _json(self, code: int, obj, headers: dict | None = None) -> None:
        body = json.dumps(obj, ensure_ascii=False).encode()
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        for k, v in (headers or {}).items():
            self.send_header(k, v)
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        with LOCK:
            if self.path == "/last":
                return self._json(200, LAST)
            if self.path == "/count":
                return self._json(200, dict(COUNT))
        return self._json(404, {"error": {"message": "not found"}})

    def do_POST(self):
        raw = self.rfile.read(int(self.headers.get("Content-Length", "0")))
        try:
            req = json.loads(raw)
        except ValueError:
            return self._json(400, {"error": {"message": "bad json"}})
        model = str(req.get("model", ""))
        with LOCK:
            COUNT[model] += 1
            LAST.clear()
            LAST.update(req)
        last_user = next((m.get("content", "") for m in reversed(req.get("messages", [])) if m.get("role") == "user"), "")
        usage = {"prompt_tokens": sum(len(str(m.get("content", ""))) for m in req.get("messages", [])) // 3, "completion_tokens": 12}
        if model == "stub-empty":
            return self._json(200, {"model": model, "choices": [{"message": {"content": ""}, "finish_reason": "stop"}], "usage": usage})
        if model == "stub-429":
            return self._json(429, {"error": {"message": "rate limit", "type": "rate_limit"}}, {"Retry-After": "1"})
        if model == "stub-500":
            return self._json(500, {"error": {"message": "upstream error"}})
        if model == "stub-badjson":
            body = b"<html>gateway</html>"
            self.send_response(200)
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)
            return None
        if model == "stub-drop":
            self.send_response(200)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", "500")
            self.end_headers()
            self.wfile.write(b'{"choices":[{"mess')
            self.wfile.flush()
            self.connection.close()
            return None
        if model == "stub-slow":
            time.sleep(30)
        text = f"Ответ модели {model}: получено {len(last_user)} знаков. Последняя фраза: «{last_user[-60:]}»"
        return self._json(200, {"model": model, "choices": [{"message": {"content": text}, "finish_reason": "stop"}], "usage": usage})


if __name__ == "__main__":
    cert, key = sys.argv[1], sys.argv[2]
    srv = ThreadingHTTPServer(("0.0.0.0", 8443), H)
    ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    ctx.load_cert_chain(cert, key)
    srv.socket = ctx.wrap_socket(srv.socket, server_side=True)
    srv.serve_forever()
