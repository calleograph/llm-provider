"""Хеши паролей локального администратора. Новые — Argon2id с параметрами PHP по умолчанию (m=64 МБ, t=4, p=1);
проверяются и прежние хеши PHP: $argon2id$… и bcrypt $2y$… — пароль после переноса не меняется."""

from __future__ import annotations

import bcrypt
from argon2 import PasswordHasher
from argon2.exceptions import InvalidHashError, VerificationError

_argon = PasswordHasher(time_cost=4, memory_cost=65536, parallelism=1)


def hash_password(password: str) -> str:
    return _argon.hash(password)


def verify_password(password: str, stored: str) -> bool:
    try:
        if stored.startswith("$argon2"):
            return _argon.verify(stored, password)
        if stored.startswith(("$2y$", "$2b$", "$2a$")):
            return bcrypt.checkpw(password.encode(), ("$2b$" + stored[4:]).encode())
    except (VerificationError, InvalidHashError, ValueError):
        return False
    return False
