"""Настоящий ldap3 против локального TLS-сервера (без AD): проверка сертификата по CA bundle и разбор ответа bind.
Сервер отвечает на bindRequest заранее заданным bindResponse (BER), этого хватает для пути connect → bind."""

from __future__ import annotations

import datetime as dt
import socket
import ssl
import threading

import pytest
from cryptography import x509
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import ec
from cryptography.x509.oid import NameOID

from kit_ldap import make_cert, pem
from llmp.ldap import LdapClient, LdapConnectionConfig, LdapErrorCode, LdapException


def _server_cert(ca, ca_key, tmp_path):
    key = ec.generate_private_key(ec.SECP256R1())
    now = dt.datetime.now(dt.timezone.utc)
    cert = (x509.CertificateBuilder().subject_name(x509.Name([x509.NameAttribute(NameOID.COMMON_NAME, "localhost")]))
            .issuer_name(ca.subject).public_key(key.public_key()).serial_number(x509.random_serial_number())
            .not_valid_before(now - dt.timedelta(days=1)).not_valid_after(now + dt.timedelta(days=30))
            .add_extension(x509.SubjectAlternativeName([x509.DNSName("localhost")]), critical=False)
            .sign(ca_key, hashes.SHA256()))
    cert_file, key_file = tmp_path / "srv.pem", tmp_path / "srv.key"
    cert_file.write_bytes(pem(cert))
    key_file.write_bytes(key.private_bytes(serialization.Encoding.PEM, serialization.PrivateFormat.PKCS8, serialization.NoEncryption()))
    return str(cert_file), str(key_file)


def _ber_len(n: int) -> bytes:
    return bytes([n]) if n < 128 else bytes([0x81, n]) if n < 256 else bytes([0x82, n >> 8, n & 0xFF])


def _bind_response(message_id: int, code: int, diagnostic: str) -> bytes:
    diag = diagnostic.encode()
    op = b"\x0a\x01" + bytes([code]) + b"\x04\x00" + b"\x04" + _ber_len(len(diag)) + diag
    body = b"\x02\x01" + bytes([message_id]) + b"\x61" + _ber_len(len(op)) + op
    return b"\x30" + _ber_len(len(body)) + body


@pytest.fixture
def tls_server(tmp_path):
    """Возвращает (порт, CA-сертификат доверенного издателя, задание ответа)."""
    ca, ca_key = make_cert("Test LDAPS CA")
    cert_file, key_file = _server_cert(ca, ca_key, tmp_path)
    ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    ctx.load_cert_chain(cert_file, key_file)
    listener = socket.create_server(("127.0.0.1", 0))
    reply = {"code": 0, "diag": ""}

    def serve():
        while True:
            try:
                raw, _ = listener.accept()
            except OSError:
                return
            try:
                with ctx.wrap_socket(raw, server_side=True) as s:
                    req = s.recv(4096)
                    if req:
                        s.sendall(_bind_response(req[4], reply["code"], reply["diag"]))   # 30 len 02 01 <id>
                        s.recv(4096)
            except (OSError, ssl.SSLError):
                pass

    threading.Thread(target=serve, daemon=True).start()
    yield listener.getsockname()[1], ca, reply
    listener.close()


def _config(port: int) -> LdapConnectionConfig:
    return LdapConnectionConfig(1, "AD", True, f"ldaps://localhost:{port}/", "localhost", port, "DC=corp,DC=local", "corp.local",
                                "CORP", "", 3, False, 3, None)


def test_untrusted_server_certificate_is_tls_error(tls_server, tmp_path):
    """Сертификат сервера подписан не загруженным CA — TLS_ERROR, bind не выполняется."""
    port, _, _ = tls_server
    bundle = tmp_path / "bundle.pem"
    bundle.write_bytes(pem(make_cert("Other CA")[0]))
    c = LdapClient(_config(port), str(bundle))
    c.connect()
    with pytest.raises(LdapException) as e:
        c.bind("", "")
    assert e.value.error_code == LdapErrorCode.TLS_ERROR
    c.close()


def test_trusted_server_bind_result_is_classified(tls_server, tmp_path):
    """Доверенный сертификат: TLS проходит, ответ AD 49/data 773 → PASSWORD_MUST_CHANGE, успех — без исключения."""
    port, ca, reply = tls_server
    bundle = tmp_path / "bundle.pem"
    bundle.write_bytes(pem(ca))
    reply.update(code=49, diag="80090308: LdapErr: DSID-0C0903AA, comment: AcceptSecurityContext error, data 773, v4f7c")
    c = LdapClient(_config(port), str(bundle))
    c.connect()
    with pytest.raises(LdapException) as e:
        c.bind("ivanov@corp.local", "secret")
    assert e.value.error_code == LdapErrorCode.PASSWORD_MUST_CHANGE, str(e.value)
    c.close()
    reply.update(code=0, diag="")
    c = LdapClient(_config(port), str(bundle))
    c.connect()
    c.bind("ivanov@corp.local", "secret")
    c.close()


def test_closed_port_is_server_unavailable(tmp_path):
    """Порт закрыт — SERVER_UNAVAILABLE."""
    s = socket.create_server(("127.0.0.1", 0))
    port = s.getsockname()[1]
    s.close()
    bundle = tmp_path / "bundle.pem"
    bundle.write_bytes(pem(make_cert()[0]))
    c = LdapClient(_config(port), str(bundle))
    c.connect()
    with pytest.raises(LdapException) as e:
        c.bind("", "")
    assert e.value.error_code == LdapErrorCode.SERVER_UNAVAILABLE


def test_hostname_mismatch_is_tls_error(tls_server, tmp_path):
    """Сертификат выдан на localhost, подключаемся по 127.0.0.1 — TLS_ERROR (имя проверяется, как у libldap)."""
    port, ca, _ = tls_server
    bundle = tmp_path / "bundle.pem"
    bundle.write_bytes(pem(ca))
    cfg = LdapConnectionConfig(1, "AD", True, f"ldaps://127.0.0.1:{port}/", "127.0.0.1", port, "DC=corp,DC=local", "corp.local",
                               "CORP", "", 3, False, 3, None)
    c = LdapClient(cfg, str(bundle))
    c.connect()
    with pytest.raises(LdapException) as e:
        c.bind("", "")
    assert e.value.error_code == LdapErrorCode.TLS_ERROR, str(e.value)
