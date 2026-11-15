"""HTTP-транспорт для внешних LLM и внутренних сервисов (обезличивание, ASR).

Только https (http — лишь для явно разрешённых внутренних адресов), сертификат проверяется всегда, перенаправления
не выполняются, ответ ограничен по размеру. Ошибки сети — HttpTransportError с видом: timeout | dns | connect | tls |
scheme | closed | too_large | other (как у версии 1.x: по виду строится политика резервирования)."""

from __future__ import annotations

import contextlib
import functools
import json as _json
import ssl
import time
from dataclasses import dataclass, field
from email.utils import parsedate_to_datetime
from typing import Any, Callable, Protocol
from urllib.parse import urlsplit

import httpx


class HttpTransportError(RuntimeError):
    def __init__(self, kind: str, message: str):
        super().__init__(message)
        self.kind = kind


@dataclass(frozen=True)
class HttpRequest:
    method: str
    url: str
    headers: dict[str, str] = field(default_factory=dict)
    body: str | bytes | None = None
    connect_timeout: int = 10
    timeout: int = 60
    ca_bundle_path: str | None = None
    allow_plain_http: bool = False
    max_response_bytes: int = 8 * 1024 * 1024

    @classmethod
    def json(cls, url: str, payload: Any, headers: dict[str, str], connect_timeout: int, timeout: int,
             ca_bundle_path: str | None = None, allow_plain_http: bool = False) -> "HttpRequest":
        return cls("POST", url, {**headers, "Content-Type": "application/json", "Accept": "application/json"},
                   _json.dumps(payload, ensure_ascii=False, separators=(",", ":")), connect_timeout, timeout, ca_bundle_path, allow_plain_http)

    def json_body(self) -> Any:
        return _json.loads(self.body) if self.body else None


@dataclass(frozen=True)
class HttpResponse:
    status: int
    body: str
    duration_ms: int = 0
    headers: dict[str, str] = field(default_factory=dict)   # имена в нижнем регистре

    def retry_after(self, now: float | None = None) -> int | None:
        value = self.headers.get("retry-after", "").strip()
        if not value:
            return None
        if value.isdigit():
            return int(value)
        try:
            return max(0, int(parsedate_to_datetime(value).timestamp() - (now or time.time())))
        except (TypeError, ValueError):
            return None

    def is_success(self) -> bool:
        return 200 <= self.status < 300

    def json(self) -> dict | list | None:
        try:
            v = _json.loads(self.body)
        except (ValueError, TypeError):
            return None
        return v if isinstance(v, (dict, list)) else None


class HttpTransport(Protocol):
    def send(self, request: HttpRequest) -> HttpResponse: ...


@functools.cache
def _internal_client() -> httpx.Client:
    """Один клиент с пулом соединений на процесс для внутренних сервисов по http (обезличивание): без нового TCP-соединения и
    TLS-контекста (~11 мс) на каждое сообщение. Простой соединения короче keep-alive сервиса (75 с), чтобы не взять закрытое."""
    return httpx.Client(follow_redirects=False, trust_env=False,
                        limits=httpx.Limits(max_connections=32, max_keepalive_connections=8, keepalive_expiry=60))


class HttpxTransport:
    def send(self, request: HttpRequest) -> HttpResponse:
        scheme = urlsplit(request.url).scheme.lower()
        if scheme != "https" and not (scheme == "http" and request.allow_plain_http):
            raise HttpTransportError("scheme", "Разрешены только https:// адреса.")
        if scheme == "http":
            return self._exchange(contextlib.nullcontext(_internal_client()), request)
        # Без корпоративного CA — системное хранилище сертификатов (как curl в версии 1.x, учитывает SSL_CERT_FILE),
        # а не встроенный набор certifi: CA, добавленный администратором в систему, работает.
        try:
            verify = ssl.create_default_context(cafile=request.ca_bundle_path)
        except (FileNotFoundError, ssl.SSLError):
            raise HttpTransportError("tls", "CA bundle приложения не найден — загрузите сертификаты в разделе «Сертификаты».") from None
        # Python 3.13+ включает строгий разбор X.509: корпоративный CA без отдельных расширений (AKI и т. п.),
        # который OpenSSL/PHP принимали, был бы отвергнут. Сертификат и имя хоста по-прежнему проверяются.
        verify.verify_flags &= ~getattr(ssl, "VERIFY_X509_STRICT", 0)
        return self._exchange(httpx.Client(verify=verify, follow_redirects=False, trust_env=False), request)

    @staticmethod
    def _exchange(client_cm, request: HttpRequest) -> HttpResponse:
        timeout = httpx.Timeout(max(1, request.timeout), connect=max(1, request.connect_timeout))
        started = time.monotonic()
        try:
            with client_cm as client:
                with client.stream(request.method, request.url, headers=request.headers, content=request.body, timeout=timeout) as resp:
                    buf = bytearray()
                    for chunk in resp.iter_bytes():
                        buf += chunk
                        if len(buf) > request.max_response_bytes:
                            raise HttpTransportError("too_large", "Ответ сервера превысил допустимый размер.")
                    headers = {k.lower(): v for k, v in list(resp.headers.items())[:100]}
                    status = resp.status_code
        except HttpTransportError:
            raise
        except httpx.TimeoutException as exc:
            raise HttpTransportError("timeout", f"timeout: {exc}") from None
        except httpx.ConnectError as exc:
            msg = str(exc)
            kind = "tls" if ("SSL" in msg or "certificate" in msg.lower()) else "dns" if ("Name or service" in msg or "nodename" in msg or "getaddrinfo" in msg) else "connect"
            raise HttpTransportError(kind, f"connect: {msg}") from None
        except (httpx.RemoteProtocolError, httpx.ReadError, httpx.WriteError) as exc:
            raise HttpTransportError("closed", f"closed: {exc}") from None
        except httpx.HTTPError as exc:
            raise HttpTransportError("other", f"http: {exc}") from None
        return HttpResponse(status, buf.decode("utf-8", errors="replace"), int((time.monotonic() - started) * 1000), headers)


class FakeHttpTransport:
    """Для тестов: обработчик запроса и список отправленных запросов."""

    def __init__(self, handler: Callable[[HttpRequest], HttpResponse] | None = None):
        self.handler = handler or (lambda r: HttpResponse(500, "{}"))
        self.requests: list[HttpRequest] = []

    def send(self, request: HttpRequest) -> HttpResponse:
        self.requests.append(request)
        return self.handler(request)

    def requests_to(self, url_part: str) -> list[HttpRequest]:
        return [r for r in self.requests if url_part in r.url]
