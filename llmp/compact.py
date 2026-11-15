"""Сжатие текстов в БД — формат PHP (gzdeflate = raw DEFLATE, уровень 6), уже сохранённые данные читаются как есть.

encode/decode — сообщения (content + content_z): сжимается текст от 1 КБ, если выходит короче.
pack/unpack — колонки *_z: префикс 'z' + DEFLATE или 't' + текст."""

from __future__ import annotations

import zlib

THRESHOLD_BYTES = 1024


def _deflate(data: bytes) -> bytes:
    c = zlib.compressobj(6, zlib.DEFLATED, -15)
    return c.compress(data) + c.flush()


def _inflate(data: bytes, what: str) -> str:
    try:
        return zlib.decompress(data, -15).decode()
    except (zlib.error, UnicodeDecodeError) as exc:
        raise RuntimeError(f"Повреждённые сжатые данные{what}.") from exc


def encode(text: str) -> tuple[str, bytes | None]:
    raw = text.encode()
    if len(raw) < THRESHOLD_BYTES:
        return text, None
    z = _deflate(raw)
    return ("", z) if len(z) < len(raw) else (text, None)


def decode(text: str | None, compressed: bytes | None) -> str:
    if compressed:
        return _inflate(bytes(compressed), " сообщения")
    return text or ""


def pack(text: str) -> bytes:
    return b"z" + _deflate(text.encode())


def unpack(blob: bytes | str | None) -> str:
    if not blob:
        return ""
    b = blob.encode() if isinstance(blob, str) else bytes(blob)
    return _inflate(b[1:], "") if b[:1] == b"z" else b[1:].decode()
