"""Разбор и хранение CA-сертификатов, сборка CA bundle (перенос tests/CertificateParserTest + проверки хранилища)."""

from __future__ import annotations

import os
import re

import pytest
from cryptography.x509 import NameAttribute
from cryptography.x509.oid import NameOID

from kit_ldap import der, make_cert, pem, private_key_pem
from llmp import paths
from llmp.certs import CaBundleBuilder, CertificateParser, CertificateRepository, name_parts, parse_certificate


# ---- CertificateParserTest ----
def test_parses_valid_ca_certificate():
    """CA-сертификат разбирается: subject, SHA-256 отпечаток из 64 знаков, серийный номер."""
    result = parse_certificate(pem(make_cert("Test Root CA")[0]))
    assert "Test Root CA" in result["subject"]
    assert re.fullmatch(r"[0-9A-F]{64}", result["fingerprint_sha256"])
    assert result["serial"]


def test_rejects_private_key():
    """Приватный ключ отклоняется."""
    with pytest.raises(RuntimeError, match="приватный ключ"):
        parse_certificate(private_key_pem(make_cert()[1]))


def test_rejects_garbage_input():
    """Мусор — ошибка."""
    with pytest.raises(RuntimeError):
        parse_certificate(b"this is not a certificate at all")


def test_rejects_non_ca_certificate():
    """Сертификат с CA:FALSE отклоняется."""
    with pytest.raises(RuntimeError, match="CA-сертификатом"):
        parse_certificate(pem(make_cert("leaf.example.com", ca=False)[0]))


def test_format_name_keeps_key_for_each_multi_valued_rdn_component():
    """Многозначный RDN — «DC=local, DC=corp», а не «DC=local+corp»."""
    assert CertificateParser.format_name({"DC": ["local", "corp"], "CN": "CORP-IssuingCA"}) == "DC=local, DC=corp, CN=CORP-IssuingCA"


def test_format_name_handles_single_valued_components():
    """Одиночный компонент."""
    assert CertificateParser.format_name({"CN": "CORP-RootCA"}) == "CN=CORP-RootCA"


# ---- форматы как у openssl_x509_parse ----
def test_der_input_and_php_compatible_fields():
    """DER принимается (PEM с переносом по 64), без basicConstraints — отказ; subject AD-вида и серийный номер — как у PHP."""
    cert, _ = make_cert("CORP-IssuingCA", extra_name=[NameAttribute(NameOID.DOMAIN_COMPONENT, "local"), NameAttribute(NameOID.DOMAIN_COMPONENT, "corp")])
    result = parse_certificate(der(cert))
    assert result["pem"].startswith("-----BEGIN CERTIFICATE-----\n") and all(len(l) <= 64 for l in result["pem"].splitlines())
    assert result["subject"] == "DC=local, DC=corp, CN=CORP-IssuingCA" == result["issuer"]
    hex_serial = format(cert.serial_number, "X")
    assert result["serial"] == ("0" * (len(hex_serial) % 2)) + hex_serial   # BN_bn2hex: целые байты
    assert re.fullmatch(r"\d{4}-\d\d-\d\d \d\d:\d\d:\d\d UTC", result["valid_to"])
    assert name_parts(cert.subject) == {"DC": ["local", "corp"], "CN": "CORP-IssuingCA"}
    with pytest.raises(RuntimeError, match="CA-сертификатом"):
        parse_certificate(pem(make_cert("no-bc", ca=None)[0]))


# ---- хранилище и CA bundle ----
def test_repository_files_and_bundle(conn):
    """Файлы var/certs/cert_<16hex>.pem с правами 0640, повтор отклоняется, bundle из всех сертификатов, удаление."""
    repo = CertificateRepository(conn)
    builder = CaBundleBuilder(repo)
    with pytest.raises(RuntimeError, match="ни одного"):
        builder.current_bundle_path()
    root, inter = make_cert("Root")[0], make_cert("Intermediate")[0]
    a = repo.store(parse_certificate(pem(root)), "root", "admin")
    repo.store(parse_certificate(pem(inter)), "intermediate", "admin")
    with pytest.raises(RuntimeError, match="уже загружен"):
        repo.store(parse_certificate(pem(root)), "root", "admin")
    row = repo.find_by_id(a)
    assert re.fullmatch(r"cert_[0-9a-f]{16}\.pem", row["filename"])
    f = paths.certs() / row["filename"]
    assert f.read_bytes() == pem(root) and oct(os.stat(f).st_mode & 0o777) == "0o640"
    bundle = builder.current_bundle_path()
    assert bundle == str(paths.certs() / "ca-bundle.pem") and oct(os.stat(bundle).st_mode & 0o777) == "0o640"
    content = open(bundle, "rb").read()
    assert pem(root) in content and pem(inter) in content
    repo.delete(a)
    assert not f.exists() and pem(root) not in open(builder.rebuild(), "rb").read()
