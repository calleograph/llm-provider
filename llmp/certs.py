"""Доверенные CA-сертификаты: разбор (CertificateParser), хранение (CertificateRepository: файлы var/certs/cert_<16hex>.pem
0640, метаданные — таблица certificates) и собственный CA bundle приложения var/certs/ca-bundle.pem (CaBundleBuilder).
Системное хранилище ОС не трогается. Форматы полей (subject, serial, fingerprint, даты) — как у PHP openssl_x509_parse."""

from __future__ import annotations

import base64
import hashlib
import os
import re
import secrets
import sqlite3
from datetime import datetime

from cryptography import x509
from cryptography.hazmat.primitives.serialization import Encoding
from cryptography.x509.oid import NameOID

from . import paths
from .db import now_iso

BUNDLE_FILENAME = "ca-bundle.pem"

# короткие имена OpenSSL (OBJ_nid2sn), как ключи в openssl_x509_parse; прочие OID — точечной записью
_SHORT_NAMES = {
    NameOID.COMMON_NAME: "CN", NameOID.COUNTRY_NAME: "C", NameOID.LOCALITY_NAME: "L", NameOID.STATE_OR_PROVINCE_NAME: "ST",
    NameOID.ORGANIZATION_NAME: "O", NameOID.ORGANIZATIONAL_UNIT_NAME: "OU", NameOID.DOMAIN_COMPONENT: "DC",
    NameOID.EMAIL_ADDRESS: "emailAddress", NameOID.SERIAL_NUMBER: "serialNumber", NameOID.SURNAME: "SN",
    NameOID.GIVEN_NAME: "GN", NameOID.TITLE: "title", NameOID.STREET_ADDRESS: "street", NameOID.USER_ID: "UID",
    NameOID.POSTAL_CODE: "postalCode", NameOID.INITIALS: "initials", NameOID.PSEUDONYM: "pseudonym",
    NameOID.BUSINESS_CATEGORY: "businessCategory", NameOID.DN_QUALIFIER: "dnQualifier",
    NameOID.GENERATION_QUALIFIER: "generationQualifier",
}
_PRIVATE_KEY = re.compile(rb"-----BEGIN (RSA |EC |ENCRYPTED )?PRIVATE KEY-----")


def name_parts(name: x509.Name) -> dict[str, str | list[str]]:
    """Структура openssl_x509_parse: ключ в порядке первого появления, повторы ключа — список значений."""
    parts: dict[str, str | list[str]] = {}
    for attr in name:
        key = _SHORT_NAMES.get(attr.oid, attr.oid.dotted_string)
        value = attr.value if isinstance(attr.value, str) else attr.value.decode("utf-8", "replace")
        if key in parts:
            prev = parts[key]
            parts[key] = (prev if isinstance(prev, list) else [prev]) + [value]
        else:
            parts[key] = value
    return parts


def format_name(parts: dict[str, str | list[str]]) -> str:
    """Многозначный компонент — каждый раз со своим ключом: «DC=local, DC=corp», а не «DC=local+corp»."""
    segments = []
    for key, value in parts.items():
        for v in value if isinstance(value, list) else [value]:
            segments.append(f"{key}={v}")
    return ", ".join(segments)


def format_timestamp(dt: datetime) -> str:
    return dt.strftime("%Y-%m-%d %H:%M:%S") + " UTC"


def _serial_hex(serial: int) -> str:
    """Как BN_bn2hex: верхний регистр, целые байты, ноль — «0»."""
    if serial == 0:
        return "0"
    h = format(abs(serial), "X")
    return ("-" if serial < 0 else "") + ("0" + h if len(h) % 2 else h)


def _to_pem(content: bytes) -> bytes:
    if b"-----BEGIN CERTIFICATE-----" in content:
        return content
    # иначе считаем DER; разбивка как chunk_split(…, 64, "\n")
    b64 = base64.b64encode(content)
    chunks = b"".join(b64[i:i + 64] + b"\n" for i in range(0, len(b64), 64))
    return b"-----BEGIN CERTIFICATE-----\n" + chunks + b"-----END CERTIFICATE-----\n"


def parse_certificate(raw: bytes | str) -> dict:
    """Разбор X.509 (PEM или DER) перед сохранением. Приватные ключи и не-CA сертификаты отклоняются."""
    content = raw.encode("utf-8", "surrogateescape") if isinstance(raw, str) else raw
    if _PRIVATE_KEY.search(content):
        raise RuntimeError("Загружаемый файл похож на приватный ключ. Приватные ключи не принимаются.")
    pem = _to_pem(content)
    try:
        cert = x509.load_pem_x509_certificate(pem)
    except ValueError:
        raise RuntimeError("Файл не распознан как корректный X.509 сертификат.") from None
    try:
        subject, issuer = format_name(name_parts(cert.subject)), format_name(name_parts(cert.issuer))
        valid_from, valid_to = format_timestamp(cert.not_valid_before_utc), format_timestamp(cert.not_valid_after_utc)
        try:
            is_ca = cert.extensions.get_extension_for_class(x509.BasicConstraints).value.ca
        except x509.ExtensionNotFound:
            is_ca = False
    except ValueError:
        raise RuntimeError("Не удалось разобрать параметры сертификата.") from None
    if not is_ca:
        raise RuntimeError("Сертификат не является CA-сертификатом (basicConstraints CA:TRUE отсутствует).")
    return {
        "pem": pem.decode("utf-8", "surrogateescape"),
        "subject": subject,
        "issuer": issuer,
        "serial": _serial_hex(cert.serial_number),
        "fingerprint_sha256": hashlib.sha256(cert.public_bytes(Encoding.DER)).hexdigest().upper(),
        "valid_from": valid_from,
        "valid_to": valid_to,
    }


class CertificateParser:
    """Имена как у PHP-класса — для переносимого кода."""

    parse = staticmethod(parse_certificate)
    format_name = staticmethod(format_name)


def _write_0640(path, text: str) -> None:
    """Запись через временный файл: параллельное LDAP-подключение не прочитает недописанный файл."""
    tmp = path.with_name(path.name + ".tmp")
    try:
        tmp.write_bytes(text.encode("utf-8", "surrogateescape"))
        os.chmod(tmp, 0o640)
        os.replace(tmp, path)
    except OSError:
        tmp.unlink(missing_ok=True)
        raise


class CertificateRepository:
    def __init__(self, conn: sqlite3.Connection):
        self.conn = conn

    def store(self, parsed: dict, cert_type: str, uploaded_by: str) -> int:
        if self.conn.execute("SELECT COUNT(*) FROM certificates WHERE fingerprint_sha256 = ?", (parsed["fingerprint_sha256"],)).fetchone()[0] > 0:
            raise RuntimeError("Такой сертификат уже загружен (совпадает fingerprint).")
        paths.ensure_directories()
        filename = f"cert_{secrets.token_hex(8)}.pem"
        try:
            _write_0640(paths.certs() / filename, parsed["pem"])
        except OSError:
            raise RuntimeError("Не удалось сохранить файл сертификата.") from None
        cur = self.conn.execute(
            "INSERT INTO certificates (filename, type, subject, issuer, serial, fingerprint_sha256, valid_from, valid_to, uploaded_by, uploaded_at) "
            "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            (filename, cert_type, parsed["subject"], parsed["issuer"], parsed["serial"], parsed["fingerprint_sha256"],
             parsed["valid_from"], parsed["valid_to"], uploaded_by, now_iso()),
        )
        return int(cur.lastrowid)

    def all(self) -> list[dict]:
        return [dict(r) for r in self.conn.execute("SELECT * FROM certificates ORDER BY uploaded_at DESC")]

    def find_by_id(self, cert_id: int) -> dict | None:
        row = self.conn.execute("SELECT * FROM certificates WHERE id = ?", (cert_id,)).fetchone()
        return dict(row) if row is not None else None

    def delete(self, cert_id: int) -> None:
        cert = self.find_by_id(cert_id)
        if cert is None:
            return
        (paths.certs() / cert["filename"]).unlink(missing_ok=True)
        self.conn.execute("DELETE FROM certificates WHERE id = ?", (cert_id,))

    def read_pem(self, cert_row: dict) -> str:
        try:
            return (paths.certs() / cert_row["filename"]).read_bytes().decode("utf-8", "surrogateescape")
        except OSError:
            raise RuntimeError("Не удалось прочитать файл сертификата.") from None


class CaBundleBuilder:
    """CA bundle только для проверки TLS приложением (LDAPS, HTTPS к сервисам с корпоративным CA)."""

    def __init__(self, repository: CertificateRepository):
        self.repository = repository

    def current_bundle_path(self) -> str:
        path = paths.certs() / BUNDLE_FILENAME
        if not path.is_file():
            self.rebuild()
        return str(path)

    def rebuild(self) -> str:
        paths.ensure_directories()
        bundle = "".join(self.repository.read_pem(c) + "\n" for c in self.repository.all())
        path = paths.certs() / BUNDLE_FILENAME
        if bundle == "":
            # нет сертификатов — bundle не создаём, TLS-проверка закономерно не пройдёт (fail-closed)
            path.unlink(missing_ok=True)
            raise RuntimeError("Не загружено ни одного доверенного CA-сертификата.")
        try:
            _write_0640(path, bundle)
        except OSError:
            raise RuntimeError("Не удалось записать CA bundle.") from None
        return str(path)
