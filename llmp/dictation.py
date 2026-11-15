"""Голосовой ввод: одноразовый билет для WebSocket-шлюза диктовки в контейнере ASR. Формат
`base64url(json).base64url(HMAC-SHA256)` совпадает с services/asr/app/ws_gateway.py::verify_ticket и версией 1.x.
В билете — ключ пользователя (для лимита диктовок в час), срок 60 с, одноразовый номер и настройки диктовки;
шлюз проверяет подпись, срок и одноразовость."""

from __future__ import annotations

import base64
import hashlib
import hmac
import json
import os
import secrets
import time
from typing import Protocol

import httpx

from .mail.settings import php_int
from .settings import AppSettings

TTL = 60


class _Settings(Protocol):
    def get(self, name: str) -> str: ...


def _b64(raw: bytes) -> str:
    return base64.urlsafe_b64encode(raw).rstrip(b"=").decode()


def _secret() -> str:
    return os.environ.get("DICTATION_SECRET", "")


class DictationTicket:
    TTL = TTL

    def __init__(self, settings: _Settings):
        """settings — llmp.chat.ChatSettings: get(name) без префикса llm_chat_."""
        self.settings = settings

    def enabled(self) -> bool:
        """Включено администратором и развёрнуто (контейнер ASR, секрет)."""
        return self.settings.get("dictation_enabled") == "1" and os.environ.get("ASR_ENABLED") == "1" and _secret() != ""

    def max_seconds(self) -> int:
        return max(5, min(600, php_int(self.settings.get("dictation_max_seconds"))))

    def asr_ready(self) -> bool:
        """Готова ли модель распознавания (без инференса): GET /readyz ASR во внутренней сети, только http."""
        url = os.environ.get("ASR_URL", "").rstrip("/")
        if not url.lower().startswith("http://"):
            return False
        try:
            with httpx.Client(timeout=httpx.Timeout(3, connect=2), follow_redirects=False, trust_env=False) as client:
                return client.get(url + "/readyz").status_code == 200
        except httpx.HTTPError:
            return False

    def issue(self, owner_key: str, now: int | None = None) -> str:
        body = _b64(json.dumps({
            "u": owner_key,
            "exp": (int(time.time()) if now is None else now) + TTL,
            "n": secrets.token_hex(16),
            "ms": self.max_seconds(),
            "mc": max(1, min(64, php_int(self.settings.get("dictation_max_concurrent")))),
        }, ensure_ascii=False, separators=(",", ":")).encode())
        return body + "." + _b64(hmac.new(_secret().encode(), body.encode(), hashlib.sha256).digest())
