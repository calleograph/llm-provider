"""Помощники тестов LDAP/сертификатов: генерация CA/leaf-сертификатов (cryptography, без сети) и подделка LdapClient."""

from __future__ import annotations

import datetime as dt

from cryptography import x509
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import ec
from cryptography.x509.oid import NameOID

from llmp.ldap import LdapErrorCode, LdapException


def make_cert(cn: str = "Test Root CA", ca: bool | None = True, extra_name: list | None = None,
              issuer_cert=None, issuer_key=None, days: int = 3650):
    """Сертификат (self-signed или подписанный issuer_*); ca=None — без basicConstraints. Возвращает (cert, key)."""
    key = ec.generate_private_key(ec.SECP256R1())
    subject = x509.Name([*(extra_name or []), x509.NameAttribute(NameOID.COMMON_NAME, cn)])
    now = dt.datetime.now(dt.timezone.utc)
    builder = (x509.CertificateBuilder().subject_name(subject)
               .issuer_name(issuer_cert.subject if issuer_cert else subject)
               .public_key(key.public_key()).serial_number(x509.random_serial_number())
               .not_valid_before(now - dt.timedelta(days=1)).not_valid_after(now + dt.timedelta(days=days)))
    if ca is not None:
        builder = builder.add_extension(x509.BasicConstraints(ca=ca, path_length=None), critical=True)
    return builder.sign(issuer_key or key, hashes.SHA256()), key


def pem(cert) -> bytes:
    return cert.public_bytes(serialization.Encoding.PEM)


def der(cert) -> bytes:
    return cert.public_bytes(serialization.Encoding.DER)


def private_key_pem(key) -> bytes:
    return key.private_bytes(serialization.Encoding.PEM, serialization.PrivateFormat.PKCS8, serialization.NoEncryption())


class FakeDirectory:
    """Каталог для FakeLdapClient: пароли, DN пользователей, группы по CN и членство (уже с учётом вложенности)."""

    def __init__(self):
        self.passwords: dict[str, str] = {}        # principal → пароль
        self.bind_errors: dict[str, str] = {}      # principal → код LdapErrorCode
        self.users: dict[str, str] = {}            # sAMAccountName → DN
        self.groups_by_cn: dict[str, str] = {}     # CN → DN
        self.members: dict[str, set[str]] = {}     # DN группы → DN пользователей
        self.clients: list["FakeLdapClient"] = []


def fake_client_factory(directory: FakeDirectory):
    """Подставляется вместо LdapClient (monkeypatch) — тот же интерфейс, без сети."""

    class FakeLdapClient:
        def __init__(self, config, ca_bundle_path):
            self.config, self.ca_bundle_path = config, ca_bundle_path
            self.bound_as: str | None = None
            self.closed = False
            directory.clients.append(self)

        def connect(self):
            pass

        def bind(self, dn, password):
            if dn in directory.bind_errors:
                raise LdapException(directory.bind_errors[dn], "fake")
            if directory.passwords.get(dn) != password:
                raise LdapException(LdapErrorCode.INVALID_CREDENTIALS, "fake")
            self.bound_as = dn

        def find_user_dn_by_sam_account_name(self, sam):
            return directory.users.get(sam)

        def find_group_dn_by_cn(self, cn):
            return directory.groups_by_cn.get(cn)

        def group_exists(self, dn):
            return dn in directory.members

        def is_member_of_group_recursive(self, user_dn, group_dn):
            return user_dn in directory.members.get(group_dn, set())

        def close(self):
            self.closed = True

    return FakeLdapClient
