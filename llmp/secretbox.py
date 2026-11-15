"""Шифрование сохранённых секретов (ключи провайдеров, пароль служебной учётки, пароль SMTP, тела писем).

Формат PHP-версии без изменений: AES-256-GCM, base64(iv[12] ‖ tag[16] ‖ ciphertext), без AAD. Ключ — APP_MASTER_KEY
(base64 32 байта) или var/data/master.key. Новый ключ создаётся только до первой установки: потерянный ключ после
неё — явная ошибка, иначе все сохранённые секреты молча стали бы нерасшифровываемыми."""

from __future__ import annotations

import base64
import binascii
import os
import secrets

from cryptography.exceptions import InvalidTag
from cryptography.hazmat.primitives.ciphers.aead import AESGCM

from . import paths

_key: bytes | None = None


class SecretError(RuntimeError):
    pass


def _b64(raw: str) -> bytes | None:
    try:
        return base64.b64decode(raw, validate=True)
    except (binascii.Error, ValueError):
        return None


def key() -> bytes:
    global _key
    if _key is not None:
        return _key
    env = os.environ.get("APP_MASTER_KEY", "")
    if env and (decoded := _b64(env)) is not None and len(decoded) == 32:
        _key = decoded
        return _key
    f = paths.secret_key_file()
    if not f.is_file():
        if paths.installed_marker().is_file():
            raise SecretError(f"Мастер-ключ не найден (APP_MASTER_KEY или {f}): восстановите его из резервной копии, сохранённые секреты без него не расшифровать.")
        paths.ensure_directories()
        generated = secrets.token_bytes(32)
        fd = os.open(f, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
        with os.fdopen(fd, "w") as fh:
            fh.write(base64.b64encode(generated).decode())
        _key = generated
        return _key
    decoded = _b64(f.read_text().strip())
    if decoded is None or len(decoded) != 32:
        raise SecretError("Мастер-ключ повреждён.")
    _key = decoded
    return _key


def reset_cache() -> None:
    global _key
    _key = None


def encrypt(plaintext: str) -> str:
    iv = secrets.token_bytes(12)
    sealed = AESGCM(key()).encrypt(iv, plaintext.encode(), None)  # ciphertext ‖ tag
    return base64.b64encode(iv + sealed[-16:] + sealed[:-16]).decode()


def decrypt(encoded: str) -> str:
    raw = _b64(encoded)
    if raw is None or len(raw) < 28:
        raise SecretError("Ошибка расшифровки.")
    iv, tag, ct = raw[:12], raw[12:28], raw[28:]
    try:
        return AESGCM(key()).decrypt(iv, ct + tag, None).decode()
    except (InvalidTag, UnicodeDecodeError) as exc:
        raise SecretError("Ошибка расшифровки.") from exc
