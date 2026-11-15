"""Подключения к LLM (llm_providers): снимок для шлюза, CRUD и проверка формы «Нейросети» — как у PHP-версии.

API-ключ и доп. заголовки шифруются SecretBox; в открытом виде отдаются только в ProviderConfig для LlmGateway.
Списки для админки и чата секретов не содержат."""

from __future__ import annotations

import json
import re
import sqlite3
from dataclasses import dataclass, field, replace
from urllib.parse import urlsplit

from .. import db, secretbox
from ..chat.settings import php_int

PHP_WS = " \t\n\r\0\x0b"   # набор trim() PHP


def php_empty(v) -> bool:
    return v in (None, False, 0, 0.0, "", "0") or v == [] or v == {}


def php_strval(v) -> str:
    if isinstance(v, bool):
        return "1" if v else ""
    return "" if v is None else str(v)


@dataclass(frozen=True)
class ProviderConfig:
    """Неизменяемый снимок подключения. Содержит расшифрованный ключ — не попадает в шаблоны и журналы (repr скрывает)."""

    id: int
    name: str
    type: str
    base_url: str
    endpoint: str
    api_key: str = field(repr=False)
    model: str
    api_version: str | None
    connect_timeout: int
    response_timeout: int
    max_tokens: int
    extra_headers: dict[str, str] = field(repr=False)
    use_corporate_ca: bool
    is_enabled: bool
    routing_provider: str | None = None       # жёсткая фиксация маршрута у мультипровайдерного шлюза
    sort_by_price: bool = False               # provider.sort=price
    max_price_per_mtoken: float | None = None  # provider.max_price, ₽ за миллион токенов
    allow_fallbacks: bool = True              # provider.allow_fallbacks
    temperature: float | None = None          # параметр выбранной модели; None — по умолчанию провайдера
    model_id: int | None = None               # llm_models.id; None — модель провайдера по умолчанию

    def with_model(self, model_id: int, model: str, max_tokens: int, temperature: float | None) -> "ProviderConfig":
        return replace(self, model_id=model_id, model=model, max_tokens=max_tokens, temperature=temperature)

    def without_optional_params(self) -> "ProviderConfig":
        return self if self.model_id is None else self.with_model(self.model_id, self.model, self.max_tokens, None)

    def url(self) -> str:
        return self.base_url.rstrip("/") + "/" + self.endpoint.lstrip("/")


def _headers_json(headers: dict[str, str]) -> str:
    return json.dumps(headers, separators=(",", ":"))


class ProviderRepository:
    def __init__(self, conn: sqlite3.Connection):
        self.conn = conn

    def all(self) -> list[dict]:
        """Строки без секретов."""
        return [dict(r) for r in self.conn.execute(
            "SELECT id, name, provider_type, base_url, endpoint, api_key_hint, model, api_version, connect_timeout, "
            "response_timeout, max_tokens, extra_header_names, routing_provider, sort_by_price, "
            "max_price_per_mtoken, allow_fallbacks, is_default, use_corporate_ca, is_enabled, "
            "last_check_at, last_check_ok, last_check_message, created_at, updated_at, "
            "(api_key_encrypted IS NOT NULL AND api_key_encrypted != '') AS has_api_key "
            "FROM llm_providers ORDER BY name"
        )]

    def find(self, provider_id: int) -> dict | None:
        return next((r for r in self.all() if int(r["id"]) == provider_id), None)

    def names(self) -> dict[int, str]:
        return {int(r[0]): str(r[1]) for r in self.conn.execute("SELECT id, name FROM llm_providers ORDER BY name")}

    def name_exists(self, name: str, exclude_id: int | None = None) -> bool:
        return self.conn.execute("SELECT COUNT(*) FROM llm_providers WHERE name = ? AND id != ?", (name, exclude_id or 0)).fetchone()[0] > 0

    def create(self, data: dict, api_key: str, extra_headers: dict[str, str] | None) -> int:
        """data — поля из ProviderValidator.normalize()."""
        now = db.now_iso()
        b = self._bind(data)
        with db.transaction(self.conn):
            cur = self.conn.execute(
                "INSERT INTO llm_providers (name, provider_type, base_url, endpoint, api_key_encrypted, api_key_hint, model, api_version, "
                "connect_timeout, response_timeout, max_tokens, extra_headers_encrypted, extra_header_names, "
                "routing_provider, sort_by_price, max_price_per_mtoken, allow_fallbacks, use_corporate_ca, is_enabled, created_at, updated_at) "
                "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                (b["name"], b["provider_type"], b["base_url"], b["endpoint"], secretbox.encrypt(api_key) if api_key != "" else None,
                 self.hint(api_key), b["model"], b["api_version"], b["connect_timeout"], b["response_timeout"], b["max_tokens"],
                 secretbox.encrypt(_headers_json(extra_headers)) if extra_headers else None,
                 ", ".join(extra_headers) if extra_headers else None,
                 b["routing_provider"], b["sort_by_price"], b["max_price_per_mtoken"], b["allow_fallbacks"], b["use_corporate_ca"], b["is_enabled"], now, now),
            )
            new_id = int(cur.lastrowid)
            db.seed_default_models(self.conn)
        return new_id

    def update(self, provider_id: int, data: dict, new_api_key: str | None, extra_headers: dict[str, str] | None | bool) -> None:
        """new_api_key None — оставить сохранённый ключ; extra_headers False — не менять, None/{} — очистить."""
        b = self._bind(data)
        now = db.now_iso()
        sets = [f"{k} = ?" for k in b] + ["updated_at = ?"]
        params: list = list(b.values()) + [now]
        with db.transaction(self.conn):
            row = self.conn.execute("SELECT base_url FROM llm_providers WHERE id = ?", (provider_id,)).fetchone()
            old_url = row[0] if row is not None else False
            if (new_api_key is None or new_api_key == "") and old_url != data["base_url"]:
                # сменился адрес — сохранённый ключ и заголовки не уходят на новый сервер без повторного ввода
                sets += ["api_key_encrypted = NULL", "api_key_hint = NULL"]
                extra_headers = None if extra_headers is False else extra_headers
            elif new_api_key is not None and new_api_key != "":
                sets += ["api_key_encrypted = ?", "api_key_hint = ?"]
                params += [secretbox.encrypt(new_api_key), self.hint(new_api_key)]
            if extra_headers is not False:
                sets += ["extra_headers_encrypted = ?", "extra_header_names = ?"]
                params += [secretbox.encrypt(_headers_json(extra_headers)) if extra_headers else None,
                           ", ".join(extra_headers) if extra_headers else None]
            self.conn.execute(f"UPDATE llm_providers SET {', '.join(sets)} WHERE id = ?", (*params, provider_id))
            # Модель провайдера по умолчанию следует полям «Модель» и «Макс. токенов ответа» формы провайдера.
            self.conn.execute(
                "UPDATE llm_models SET model = ?, display_name = CASE WHEN display_name = model THEN ? ELSE display_name END, "
                "max_output_tokens = ?, updated_at = ? WHERE provider_id = ? AND is_default = 1 "
                "AND NOT EXISTS (SELECT 1 FROM llm_models o WHERE o.provider_id = ? AND o.model = ? AND o.is_default = 0)",
                (b["model"], b["model"], b["max_tokens"], now, provider_id, provider_id, b["model"]),
            )
            db.seed_default_models(self.conn)

    def set_enabled(self, provider_id: int, enabled: bool) -> None:
        self.conn.execute("UPDATE llm_providers SET is_enabled = ?, updated_at = ? WHERE id = ?", (1 if enabled else 0, db.now_iso(), provider_id))

    def set_default(self, provider_id: int) -> None:
        """Нейросеть, открывающаяся в чате по умолчанию; is_default = 1 ровно у одной строки."""
        now = db.now_iso()
        with db.transaction(self.conn):
            self.conn.execute("UPDATE llm_providers SET is_default = 0, updated_at = ? WHERE is_default = 1", (now,))
            self.conn.execute("UPDATE llm_providers SET is_default = 1, updated_at = ? WHERE id = ?", (now, provider_id))

    def clear_default(self) -> None:
        self.conn.execute("UPDATE llm_providers SET is_default = 0, updated_at = ? WHERE is_default = 1", (db.now_iso(),))

    def delete(self, provider_id: int) -> None:
        self.conn.execute("DELETE FROM llm_providers WHERE id = ?", (provider_id,))

    def record_check(self, provider_id: int, ok: bool, message: str) -> None:
        self.conn.execute("UPDATE llm_providers SET last_check_at = ?, last_check_ok = ?, last_check_message = ? WHERE id = ?",
                          (db.now_iso(), 1 if ok else 0, message[:300], provider_id))

    def load_for_gateway(self, provider_id: int) -> ProviderConfig | None:
        """Полная конфигурация с расшифрованными секретами — только для LlmGateway."""
        row = self.conn.execute("SELECT * FROM llm_providers WHERE id = ?", (provider_id,)).fetchone()
        if row is None:
            return None
        r = dict(row)
        try:
            api_key = secretbox.decrypt(str(r["api_key_encrypted"])) if r["api_key_encrypted"] else ""
            headers = json.loads(secretbox.decrypt(str(r["extra_headers_encrypted"]))) if r["extra_headers_encrypted"] else {}
        except Exception:
            raise RuntimeError("Не удалось расшифровать секреты подключения (проверьте master.key).") from None
        return ProviderConfig(
            id=int(r["id"]), name=str(r["name"]), type=str(r["provider_type"]), base_url=str(r["base_url"]), endpoint=str(r["endpoint"]),
            api_key=api_key, model=str(r["model"]),
            api_version=str(r["api_version"]) if r["api_version"] not in (None, "") else None,
            connect_timeout=int(r["connect_timeout"]), response_timeout=int(r["response_timeout"]), max_tokens=int(r["max_tokens"]),
            extra_headers={str(k): php_strval(v) for k, v in headers.items()} if isinstance(headers, dict) else {},
            use_corporate_ca=bool(r["use_corporate_ca"]), is_enabled=bool(r["is_enabled"]),
            routing_provider=str(r["routing_provider"]) if r.get("routing_provider") not in (None, "") else None,
            sort_by_price=bool(r.get("sort_by_price") or False),
            max_price_per_mtoken=float(r["max_price_per_mtoken"]) if r.get("max_price_per_mtoken") is not None else None,
            allow_fallbacks=bool(r["allow_fallbacks"]) if r.get("allow_fallbacks") is not None else True,
        )

    @staticmethod
    def _bind(data: dict) -> dict:
        # порядок ключей = порядок SET в update()
        return {
            "name": data["name"],
            "provider_type": data["provider_type"],
            "base_url": data["base_url"],
            "endpoint": data["endpoint"],
            "model": data["model"],
            "api_version": data["api_version"] if data["api_version"] != "" else None,
            "connect_timeout": data["connect_timeout"],
            "response_timeout": data["response_timeout"],
            "max_tokens": data["max_tokens"],
            "routing_provider": data["routing_provider"] if data["routing_provider"] != "" else None,
            "sort_by_price": 1 if data["sort_by_price"] else 0,
            # (float) null в PHP — 0.0: строка из find() с NULL сохраняется как 0
            "max_price_per_mtoken": float(data["max_price_per_mtoken"] or 0) if data["max_price_per_mtoken"] != "" else None,
            "allow_fallbacks": 1 if data["allow_fallbacks"] else 0,
            "use_corporate_ca": 1 if data["use_corporate_ca"] else 0,
            "is_enabled": 1 if data["is_enabled"] else 0,
        }

    @staticmethod
    def hint(api_key: str) -> str | None:
        if api_key == "":
            return None
        return "…" + api_key[-4:] if len(api_key) >= 12 else "…"


_FORBIDDEN_HEADERS = ("host", "content-length", "content-type", "transfer-encoding", "connection", "expect", "accept")
_HOST_RE = re.compile(r"(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*\.?|\[[0-9A-Fa-f:.]+\]")
_LINE_SPLIT = re.compile(r"\r\n|[\n\x0b\x0c\r\x85  ]")   # \R PCRE


def _valid_url(url: str) -> bool:
    """Приближение FILTER_VALIDATE_URL: ASCII без пробелов, схема и корректное имя хоста."""
    if not url.isascii() or re.search(r"[\x00-\x20\x7f]", url):
        return False
    try:
        p = urlsplit(url)
        p.port
    except ValueError:
        return False
    host = p.netloc.rsplit("@", 1)[-1]
    host = host.rsplit(":", 1)[0] if not host.endswith("]") and ":" in host else host
    return bool(re.fullmatch(r"[A-Za-z][A-Za-z0-9+.-]*", p.scheme)) and host != "" and bool(_HOST_RE.fullmatch(host))


class ProviderValidator:
    @staticmethod
    def normalize(post: dict) -> dict:
        s = lambda k: php_strval(post.get(k)).strip(PHP_WS)  # noqa: E731
        i = lambda k, d: php_int(post[k] if post.get(k) is not None else d)  # noqa: E731
        return {
            "name": s("name"),
            "provider_type": s("provider_type"),
            "base_url": s("base_url").rstrip("/"),
            "endpoint": s("endpoint"),
            "model": s("model"),
            "api_version": s("api_version"),
            "routing_provider": s("routing_provider"),
            "sort_by_price": not php_empty(post.get("sort_by_price")),
            "max_price_per_mtoken": s("max_price_per_mtoken"),
            "allow_fallbacks": not php_empty(post.get("allow_fallbacks")),
            "connect_timeout": i("connect_timeout", 10),
            "response_timeout": i("response_timeout", 120),
            "max_tokens": i("max_tokens", 2048),
            "use_corporate_ca": not php_empty(post.get("use_corporate_ca")),
            "is_enabled": not php_empty(post.get("is_enabled")),
        }

    @staticmethod
    def validate(data: dict, registry) -> dict[str, str]:
        errors: dict[str, str] = {}
        name = str(data["name"])
        if name == "" or len(name) > 100 or re.search(r"[\x00-\x1f]", name):
            errors["name"] = "Укажите название (до 100 символов)."
        if not registry.has(str(data["provider_type"])):
            errors["provider_type"] = "Выберите тип провайдера."
        url = str(data["base_url"])
        if not _valid_url(url) or urlsplit(url).scheme.lower() != "https":
            errors["base_url"] = "Base URL должен быть корректным https:// адресом."
        elif "@" in urlsplit(url).netloc or "?" in url.split("#", 1)[0]:
            errors["base_url"] = "Base URL не должен содержать логин/пароль или параметры запроса."
        if not re.fullmatch(r"/[A-Za-z0-9._~/%:-]*", str(data["endpoint"])):
            errors["endpoint"] = "Endpoint — путь, начинающийся с /, например /chat/completions."
        if not re.fullmatch(r"[A-Za-z0-9._:/@+-]{1,200}", str(data["model"])):
            errors["model"] = "Укажите ID модели (латиница, цифры, . _ : / @ + -)."
        if data["api_version"] != "" and not re.fullmatch(r"[A-Za-z0-9._-]{1,50}", str(data["api_version"])):
            errors["api_version"] = "Недопустимая версия API."
        if data["routing_provider"] != "" and not re.fullmatch(r"[A-Za-z0-9._ /-]{1,80}", str(data["routing_provider"])):
            errors["routing_provider"] = "Название провайдера маршрута: латиница, цифры, пробел, . _ / - (до 80 символов)."
        if data["routing_provider"] != "" and data["sort_by_price"]:
            errors["sort_by_price"] = "Нельзя одновременно зафиксировать провайдера маршрута и включить сортировку по цене."
        if data["max_price_per_mtoken"] != "":
            if not re.fullmatch(r"\d{1,9}(\.\d{1,6})?", str(data["max_price_per_mtoken"]), re.ASCII):
                errors["max_price_per_mtoken"] = "Укажите неотрицательное число (₽ за миллион токенов)."
            elif not data["sort_by_price"]:
                errors["max_price_per_mtoken"] = "Потолок цены имеет смысл только вместе с сортировкой по цене."
        if not 1 <= data["connect_timeout"] <= 60:
            errors["connect_timeout"] = "Диапазон 1–60 сек."
        if not 5 <= data["response_timeout"] <= 900:
            errors["response_timeout"] = "Диапазон 5–900 сек."
        if not 1 <= data["max_tokens"] <= 200000:
            errors["max_tokens"] = "Диапазон 1–200000."
        return errors

    @staticmethod
    def parse_headers(raw: str) -> tuple[dict[str, str], str | None]:
        """Разбор textarea «Имя: значение» по строкам → (заголовки, текст ошибки)."""
        headers: dict[str, str] = {}
        for n, line in enumerate(_LINE_SPLIT.split(raw.strip(PHP_WS)), start=1):
            line = line.strip(PHP_WS)
            if line == "":
                continue
            if ":" not in line:
                return {}, f"Строка {n}: ожидается формат «Имя: значение»."
            name, value = (x.strip(PHP_WS) for x in line.split(":", 1))
            if not re.fullmatch(r"[A-Za-z0-9-]{1,64}", name):
                return {}, f"Строка {n}: недопустимое имя заголовка."
            if name.lower() in _FORBIDDEN_HEADERS:
                return {}, f"Заголовок {name} задаётся приложением и не может быть переопределён."
            if value == "" or len(value.encode()) > 2000 or re.search(r"[\x00-\x1f\x7f]", value):
                return {}, f"Строка {n}: недопустимое значение заголовка."
            headers[name] = value
        if len(headers) > 20:
            return {}, "Не более 20 дополнительных заголовков."
        return headers, None
