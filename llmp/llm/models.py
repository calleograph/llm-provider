"""Модели внутри провайдеров (llm_models): то, что выбирает пользователь и между чем переключается резервирование.

Доступ к модели — доступ к её провайдеру (AccessPolicy) плюс включённая модель и включённый провайдер."""

from __future__ import annotations

import json
import re
import sqlite3

from .. import db
from ..chat.settings import php_int
from .providers import php_empty

_SELECT = "SELECT m.*, p.name AS provider_name, p.provider_type, p.is_enabled AS provider_enabled FROM llm_models m JOIN llm_providers p ON p.id = m.provider_id"
_NUMERIC = re.compile(r"[ \t\n\r\v\f]*[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?[ \t\n\r\v\f]*", re.ASCII)


def php_is_numeric(v) -> bool:
    if isinstance(v, bool):
        return False
    if isinstance(v, (int, float)):
        return True
    return isinstance(v, str) and bool(_NUMERIC.fullmatch(v))


class ModelRepository:
    def __init__(self, conn: sqlite3.Connection):
        self.conn = conn

    def all(self) -> list[dict]:
        return [self._normalize(r) for r in self.conn.execute(_SELECT + " ORDER BY p.name, m.priority, m.display_name")]

    def find(self, model_id: int) -> dict | None:
        row = self.conn.execute(_SELECT + " WHERE m.id = ?", (model_id,)).fetchone()
        return None if row is None else self._normalize(row)

    def default_for(self, provider_id: int) -> int | None:
        row = self.conn.execute("SELECT id FROM llm_models WHERE provider_id = ? ORDER BY is_default DESC, priority, id LIMIT 1", (provider_id,)).fetchone()
        return None if row is None else int(row[0])

    def allowed_for(self, allowed_providers: dict[int, dict]) -> dict[int, dict]:
        """Включённые модели провайдеров из AccessPolicy.allowed_providers(), по id модели."""
        ids = [int(i) for i in allowed_providers]
        if not ids:
            return {}
        rows = self.conn.execute(
            "SELECT m.*, p.name AS provider_name, p.provider_type, p.is_enabled AS provider_enabled, p.is_default AS provider_default "
            "FROM llm_models m JOIN llm_providers p ON p.id = m.provider_id "
            f"WHERE m.provider_id IN ({','.join('?' * len(ids))}) AND m.is_enabled = 1 AND p.is_enabled = 1 ORDER BY m.priority, p.name, m.display_name",
            ids,
        )
        # нейросеть, доступная только как базовая, — одной своей моделью по умолчанию
        base = {i for i, p in allowed_providers.items() if p.get("base_only")}
        return {int(r["id"]): self._normalize(r) for r in rows if int(r["provider_id"]) not in base or r["is_default"]}

    def chain(self, model_id: int, allowed_models: dict[int, dict], auto_by_priority: bool) -> list[int]:
        """Сама модель, её резервные по порядку, затем (если включено) остальные доступные по приоритету. Без повторов —
        каждая модель один раз, поэтому циклическое переключение невозможно."""
        primary = self.find(model_id)
        if primary is None:
            return []
        chain = [model_id]
        for i in primary["fallback_ids"]:
            if i in allowed_models and i not in chain:
                chain.append(i)
        if auto_by_priority:
            chain += [i for i in allowed_models if i not in chain]
        return chain

    def create(self, data: dict) -> int:
        now = db.now_iso()
        b = self._bind(data)
        cur = self.conn.execute(
            "INSERT INTO llm_models (provider_id, model, display_name, context_window, max_output_tokens, temperature, supports_streaming, supports_json, "
            "priority, is_enabled, fallback_ids, price_in_per_mtoken, price_out_per_mtoken, created_at, updated_at) "
            "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            (php_int(data["provider_id"]), *b.values(), now, now),
        )
        return int(cur.lastrowid)

    def update(self, model_id: int, data: dict) -> None:
        b = self._bind(data)
        with db.transaction(self.conn):
            self.conn.execute(
                "UPDATE llm_models SET model = ?, display_name = ?, context_window = ?, max_output_tokens = ?, temperature = ?, supports_streaming = ?, "
                "supports_json = ?, priority = ?, is_enabled = ?, fallback_ids = ?, price_in_per_mtoken = ?, price_out_per_mtoken = ?, updated_at = ? WHERE id = ?",
                (*b.values(), db.now_iso(), model_id),
            )
            # Модель по умолчанию — зеркало полей провайдера (совместимость со схемой «провайдер = модель»).
            self.conn.execute(
                "UPDATE llm_providers SET model = ?, max_tokens = ? WHERE id = (SELECT provider_id FROM llm_models WHERE id = ? AND is_default = 1)",
                (data["model"], php_int(data["max_output_tokens"]), model_id),
            )

    def delete(self, model_id: int) -> bool:
        """Модель по умолчанию у провайдера удалить нельзя (её задаёт форма провайдера)."""
        with db.transaction(self.conn):
            if self.conn.execute("DELETE FROM llm_models WHERE id = ? AND is_default = 0", (model_id,)).rowcount == 0:
                return False
            # Удалённая модель исчезает из чужих цепочек резервирования.
            for row in self.conn.execute(f"SELECT id, fallback_ids FROM llm_models WHERE fallback_ids LIKE '%{int(model_id)}%'").fetchall():
                ids = [x for x in self.ids(str(row["fallback_ids"])) if x != model_id]
                self.conn.execute("UPDATE llm_models SET fallback_ids = ? WHERE id = ?", (json.dumps(ids, separators=(",", ":")), int(row["id"])))
        return True

    @staticmethod
    def validate(data: dict) -> dict[str, str]:
        errors: dict[str, str] = {}
        if not re.fullmatch(r"[A-Za-z0-9._:/@+-]{1,200}", str(data.get("model") or "")):
            errors["model"] = "Идентификатор модели: латиница, цифры и . _ : / @ + -"
        name = str(data.get("display_name") or "").strip(" \t\n\r\0\x0b")
        if name == "" or len(name) > 80:
            errors["display_name"] = "Название — от 1 до 80 символов."
        for f, (lo, hi) in {"context_window": (1024, 10_000_000), "max_output_tokens": (16, 1_000_000), "priority": (0, 10000)}.items():
            if not lo <= php_int(data.get(f) or 0) <= hi:
                errors[f] = f"Допустимый диапазон: {lo}–{hi}."
        if php_int(data.get("max_output_tokens") or 0) >= php_int(data.get("context_window") or 0):
            errors["max_output_tokens"] = "Длина ответа должна быть меньше контекстного окна."
        t = data.get("temperature", "")
        if t not in ("", None) and (not php_is_numeric(t) or not 0 <= float(t) <= 2):
            errors["temperature"] = "Температура: от 0 до 2 или пусто."
        for f in ("price_in", "price_out"):
            v = data.get(f, "")
            if v not in ("", None) and (not php_is_numeric(v) or float(v) < 0):
                errors[f] = "Цена — неотрицательное число или пусто."
        return errors

    @staticmethod
    def ids(raw: str) -> list[int]:
        try:
            decoded = json.loads(raw)
        except ValueError:
            return []
        if isinstance(decoded, dict):
            decoded = list(decoded.values())
        if not isinstance(decoded, list):
            return []
        return list(dict.fromkeys(x for x in (php_int(v) for v in decoded) if x > 0))

    @staticmethod
    def _bind(data: dict) -> dict:
        # порядок ключей = порядок колонок в create()/update()
        num = lambda v: None if v in ("", None) else float(v)  # noqa: E731
        fb = data.get("fallback_ids")
        fb = [] if fb is None else fb if isinstance(fb, list) else list(fb.values()) if isinstance(fb, dict) else [fb]
        return {
            "model": str(data["model"]),
            "display_name": str(data["display_name"]).strip(" \t\n\r\0\x0b"),
            "context_window": php_int(data["context_window"]),
            "max_output_tokens": php_int(data["max_output_tokens"]),
            "temperature": num(data.get("temperature")),
            "supports_streaming": 0 if php_empty(data.get("supports_streaming")) else 1,
            "supports_json": 0 if php_empty(data.get("supports_json")) else 1,
            "priority": php_int(data["priority"]) if data.get("priority") is not None else 100,
            "is_enabled": 0 if php_empty(data.get("is_enabled")) else 1,
            "fallback_ids": json.dumps([php_int(x) for x in fb], separators=(",", ":")),
            "price_in": num(data.get("price_in")),
            "price_out": num(data.get("price_out")),
        }

    @staticmethod
    def _normalize(row) -> dict:
        r = dict(row)
        for f in ("id", "provider_id", "context_window", "max_output_tokens", "priority"):
            r[f] = int(r[f])
        for f in ("supports_streaming", "supports_json", "is_enabled", "is_default", "provider_enabled"):
            r[f] = bool(r.get(f) or False)
        r["temperature"] = float(r["temperature"]) if r["temperature"] is not None else None
        r["fallback_ids"] = ModelRepository.ids(str(r["fallback_ids"]))
        return r
