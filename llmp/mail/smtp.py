"""SMTP-клиент с поэтапной диагностикой (алгоритм ArkLock apps/api/src/mail/smtp.ts, как у PHP-версии):
dns → tcp → greeting → ehlo → tls → certificate → methods → auth → sender → recipient → data.
Каждый этап записывается {stage, ok, info, code}; тайм-аут относится к этапу, на котором случился.

Безопасность: имя сервера разрешается один раз и соединение идёт на проверенный адрес (SSRF: запрещены
loopback, link-local, multicast и служебные порты); TLS не ниже 1.2 с проверкой сертификата и имени
(отключается только явно); пароль и обмен AUTH в результаты и журналы не попадают — ответы сервера
очищаются (server_text): управляющие символы, base64 длиннее 40 знаков, обрезка до 300 знаков."""

from __future__ import annotations

import base64
import ipaddress
import re
import secrets
import socket
import ssl
from datetime import datetime, timezone
from email.utils import format_datetime

from cryptography import x509

from .settings import MailSettings, php_int

STAGES = ("dns", "tcp", "greeting", "ehlo", "tls", "certificate", "methods", "auth", "sender", "recipient", "data")
FORBIDDEN_PORTS = (22, 80, 443, 3306, 5432, 6379, 2375, 2376, 9000, 11211, 27017)
# Повторяются в очереди только временные ошибки; неверный пароль — никогда (иначе блокировка учётной записи в AD).
RETRYABLE = ("temporary", "limit", "connect", "timeout", "closed", "dns")


class SmtpException(RuntimeError):
    """Сбой на конкретном этапе SMTP; code — класс ошибки (classify, RETRYABLE). Текст без секретов."""

    def __init__(self, stage: str, code: str, message: str):
        super().__init__(message)
        self.stage = stage
        self.code = code


class SmtpClient:
    STAGES = STAGES
    RETRYABLE = RETRYABLE

    def __init__(self, settings: MailSettings, allow_loopback: bool = False):
        self.settings = settings
        self.allow_loopback = allow_loopback
        self.log: list[dict] = []
        self._sock: socket.socket | None = None
        self._file = None
        self._password = ""

    def check(self, recipient: str | None = None, subject: str = "", body: str = "") -> dict:
        """Проверка соединения по этапам; с recipient — до DATA включительно (настоящее письмо)."""
        self.log = []
        try:
            try:
                self._password = self.settings.password()
            except RuntimeError:   # пароль не расшифровать (сменился мастер-ключ) — ошибка входа, а не сбой очереди
                raise SmtpException("auth", "auth_failed", "stored password cannot be decrypted") from None
            self._run(recipient, subject, body)
            ok, code = True, None
        except SmtpException as e:
            self.log.append({"stage": e.stage, "ok": False, "info": self.server_text(str(e)), "code": e.code})
            ok, code = False, e.code
        finally:
            self._password = ""
            if self._sock is not None:
                try:
                    self._sock.sendall(b"QUIT\r\n")
                except OSError:
                    pass
                self._file.close()   # иначе makefile держит сокет открытым
                self._sock.close()
            self._sock = self._file = None
        return {"ok": ok, "stages": self.log, "code": code}

    def _run(self, to: str | None, subject: str, body: str) -> None:
        host = self.settings.get("host")
        port = php_int(self.settings.get("port"))
        timeout = max(3, php_int(self.settings.get("timeout")))
        security = self.settings.get("security")

        # dns + защита от SSRF
        if _is_ip(host):
            ip = host
        else:
            try:
                ip = socket.gethostbyname(host)
            except (OSError, UnicodeError):
                raise SmtpException("dns", "dns", "Имя сервера не разрешается: " + host) from None
        if port in FORBIDDEN_PORTS or not self._address_allowed(ip):
            raise SmtpException("dns", "forbidden_target", f"Адрес или порт запрещён для почтового сервера: {ip}:{port}")
        self._ok("dns", f"{host} → {ip}")

        # tcp (+ сразу TLS для 465)
        try:
            sock = socket.create_connection((ip, port), timeout=timeout)
        except TimeoutError as exc:
            raise SmtpException("tcp", "timeout", str(exc) or "Connection timed out") from None
        except OSError as exc:
            raise SmtpException("tcp", "connect", exc.strerror or str(exc) or "Нет соединения") from None
        self._attach(sock)
        if security == "tls":
            try:
                self._attach(self._tls_context().wrap_socket(sock, server_hostname=host))
            except (ssl.SSLError, OSError) as exc:
                raise SmtpException("tls", "certificate", str(exc) or "Нет соединения") from None
        self._ok("tcp", f"{ip}:{port}")
        if security == "tls":
            self._ok("tls", "TLS установлен при подключении")
            self._cert_info()

        self._expect("greeting", 220)
        helo = self.settings.get("helo") or socket.gethostname() or "localhost"
        caps = self._ehlo(helo)

        if security == "starttls":
            if "STARTTLS" not in caps:
                raise SmtpException("tls", "starttls", "Сервер не предлагает STARTTLS — выберите другой режим или порт.")
            self._command("tls", "STARTTLS", 220)
            try:
                self._attach(self._tls_context().wrap_socket(self._sock, server_hostname=host))
            except (ssl.SSLError, OSError) as exc:
                raise SmtpException("certificate", "certificate", "TLS не установлен: " + (str(exc) or "проверка сертификата не пройдена")) from None
            self._ok("tls", "STARTTLS")
            self._cert_info()
            caps = self._ehlo(helo)
        elif security == "none":
            self.log.append({"stage": "tls", "ok": True, "info": "не применяется (без шифрования)", "code": None, "skipped": True})
            self.log.append({"stage": "certificate", "ok": True, "info": "не применяется", "code": None, "skipped": True})

        methods = [m for m in caps["AUTH"].upper().split(" ") if m] if "AUTH" in caps else []
        self._ok("methods", "AUTH: " + ", ".join(methods) if methods else "сервер не предлагает AUTH")
        self._auth(methods)

        if to is None:
            return
        sender = self.settings.get("from_address")
        self._command("sender", f"MAIL FROM:<{sender}>", 250)
        self._command("recipient", f"RCPT TO:<{to}>", (250, 251))
        self._command("data", "DATA", 354)
        self._write(self.message(sender, self.settings.get("from_name"), to, subject, body) + "\r\n.")
        self._expect("data", 250)

    def _auth(self, methods: list[str]) -> None:
        mode = self.settings.get("auth")
        user = self.settings.get("username")
        if mode == "none" or user == "":
            self.log.append({"stage": "auth", "ok": True, "info": "без авторизации", "code": None, "skipped": True})
            return
        pick = mode.upper() if mode != "auto" else ("PLAIN" if "PLAIN" in methods else "LOGIN" if "LOGIN" in methods else None)
        if pick is None or (methods and pick not in methods):
            raise SmtpException("auth", "auth_unsupported", f"Сервер не поддерживает {pick or 'PLAIN/LOGIN'}; доступно: "
                                f"{', '.join(methods) if methods else 'ничего'}. Для NTLM/Kerberos используйте relay без авторизации.")
        b64 = lambda s: base64.b64encode(s.encode()).decode()  # noqa: E731
        if pick == "PLAIN":
            self._command("auth", "AUTH PLAIN " + b64(f"\0{user}\0{self._password}"), 235, True)
        else:
            self._command("auth", "AUTH LOGIN", 334, True)
            self._command("auth", b64(user), 334, True)
            self._command("auth", b64(self._password), 235, True)
        self._ok("auth", pick + " — принят")

    def _ehlo(self, helo: str) -> dict[str, str]:
        self._command_line("EHLO " + helo, "ehlo")
        code, lines = self._read("ehlo")
        if code != 250:
            raise SmtpException("ehlo", self.classify("ehlo", code, " ".join(lines)), f"{code} {' '.join(lines)}")
        caps: dict[str, str] = {}
        for line in lines[1:]:
            parts = line.strip().split(" ", 1)
            caps[parts[0].upper()] = parts[1] if len(parts) > 1 else ""
        self._ok("ehlo", lines[0] if lines else "")
        return caps

    def _command(self, stage: str, line: str, want: int | tuple[int, ...], secret: bool = False) -> None:
        self._command_line(line, stage)
        self._expect(stage, want, secret)

    def _command_line(self, line: str, stage: str) -> None:
        # защита от внедрения команд: адреса и HELO приходят из настроек и форм
        if "\r" in line or "\n" in line:
            raise SmtpException(stage, "protocol", "Перевод строки в команде SMTP недопустим.")
        self._write(line)

    def _expect(self, stage: str, want: int | tuple[int, ...], secret: bool = False) -> None:
        code, lines = self._read(stage)
        if code not in (want if isinstance(want, tuple) else (want,)):
            raise SmtpException(stage, self.classify(stage, code, " ".join(lines)), f"{code} {' '.join(lines)}")
        if not secret and stage in ("greeting", "sender", "recipient", "data"):
            self._ok(stage, f"{code} {lines[0] if lines else ''}")

    def _write(self, line: str) -> None:
        try:
            self._sock.sendall((line + "\r\n").encode())
        except OSError:
            raise SmtpException("data", "closed", "Соединение закрыто сервером") from None

    def _read(self, stage: str) -> tuple[int, list[str]]:
        lines: list[str] = []
        while True:
            try:
                raw = self._file.readline(4096)
            except TimeoutError:
                raise SmtpException(stage, "timeout", "Сервер не ответил вовремя") from None
            except OSError:
                raw = b""
            if not raw:
                raise SmtpException(stage, "closed", "Соединение закрыто сервером")
            line = raw.decode("utf-8", errors="replace")
            code = int(line[:3]) if line[:3].isdigit() else 0
            lines.append(line[4:].rstrip())
            if line[3:4] != "-" or len(lines) > 100:
                return code, lines

    def _attach(self, sock: socket.socket) -> None:
        self._sock = sock
        self._file = sock.makefile("rb")

    @staticmethod
    def classify(stage: str, status: int, text: str) -> str:
        """Классификация ответа (classifyReply ArkLock)."""
        t = text.lower()
        if status in (421, 450, 451, 454, 455):
            if re.search(r"too many|rate|limit|throttl|exceed|quota", t):
                return "limit"
            return "auth_failed" if stage == "auth" and status == 454 else "temporary"
        if status == 452 or (status == 552 and re.search(r"too many|rate limit|quota exceeded", t)):
            return "limit"
        if 400 <= status < 500:
            return "temporary"
        if stage == "auth":
            return "auth_unsupported" if status in (504, 538) else "auth_failed"
        if re.search(r"relay|not permitted|unable to relay|not allowed to send|access denied", t):
            return "sender_rejected" if stage == "sender" else "relay_denied"
        return {"sender": "sender_rejected", "recipient": "recipient_rejected", "tls": "starttls",
                "greeting": "protocol", "ehlo": "protocol"}.get(stage, "data")

    @staticmethod
    def server_text(text: str) -> str:
        """Текст ответа сервера без секретов: управляющие символы, длинный base64 (обмен AUTH), обрезка."""
        text = re.sub(r"[\x00-\x1f\x7f]+", " ", text)
        text = re.sub(r"[A-Za-z0-9+/=]{40,}", "[…]", text)
        return text.strip(" \t\n\r\0\x0b")[:300]

    serverText = server_text

    @staticmethod
    def message(sender: str, from_name: str, to: str, subject: str, body: str) -> str:
        enc = lambda s: "=?UTF-8?B?" + base64.b64encode(s.encode()).decode() + "?="  # noqa: E731
        headers = [
            "Date: " + format_datetime(datetime.now(timezone.utc).replace(microsecond=0)),
            "From: " + (enc(from_name) + " " if from_name else "") + f"<{sender}>",
            f"To: <{to}>",
            "Subject: " + enc(subject),
            f"Message-ID: <{secrets.token_hex(12)}@{sender.split('@')[1] if '@' in sender else 'localhost'}>",
            "MIME-Version: 1.0",
            "Content-Type: text/plain; charset=UTF-8",
            "Content-Transfer-Encoding: base64",
        ]
        b = base64.b64encode(re.sub(r"\r?\n", "\r\n", body).encode()).decode()
        return "\r\n".join(headers) + "\r\n\r\n" + "\r\n".join(b[i:i + 76] for i in range(0, len(b), 76))

    def _address_allowed(self, ip: str) -> bool:
        try:
            a = ipaddress.IPv4Address(ip)
        except ValueError:
            return False   # IPv6-адреса почтовых серверов — по имени через IPv4 (упрощение: корпоративные SMTP почти всегда IPv4)
        if a in ipaddress.IPv4Network("127.0.0.0/8"):
            return self.allow_loopback
        return not any(a in ipaddress.IPv4Network(n) for n in ("0.0.0.0/8", "169.254.0.0/16", "224.0.0.0/4", "240.0.0.0/4"))

    def _tls_context(self) -> ssl.SSLContext:
        ctx = ssl.create_default_context()
        ctx.minimum_version = ssl.TLSVersion.TLSv1_2
        if self.settings.get("verify_cert") != "1":
            ctx.check_hostname = False
            ctx.verify_mode = ssl.CERT_NONE
        return ctx

    def _cert_info(self) -> None:
        info = "сертификат принят"
        try:
            der = self._sock.getpeercert(binary_form=True)
            if der:
                cert = x509.load_der_x509_certificate(der)
                cn = cert.subject.get_attributes_for_oid(x509.NameOID.COMMON_NAME)
                info = (f"CN={cn[0].value if cn else '?'}, до {cert.not_valid_after_utc:%Y-%m-%d}"
                        + ("" if self.settings.get("verify_cert") == "1" else " — проверка ОТКЛЮЧЕНА"))
        except (ValueError, AttributeError):
            pass
        self._ok("certificate", info)

    def _ok(self, stage: str, info: str) -> None:
        self.log.append({"stage": stage, "ok": True, "info": self.server_text(info), "code": None})


def _is_ip(host: str) -> bool:
    try:
        ipaddress.ip_address(host)
        return True
    except ValueError:
        return False
