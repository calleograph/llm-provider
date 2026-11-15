"""Вход в админку и в чат: сессия администратора (перенос tests/AdminSessionTest), доменный администратор, вход
пользователя чата с проверкой разрешённых групп, репозитории доменов и групп. AD подменяется FakeLdapClient."""

from __future__ import annotations

import time

import pytest

import kit
from kit_ldap import FakeDirectory, fake_client_factory
from llmp import auth, domain as domain_mod
from llmp.auth import AdminSession, DomainAdminAuthenticator, LocalAdminRepository
from llmp.domain import ChatAuthenticator, DomainGroupRepository, LlmDomainRepository
from llmp.ldap import LdapErrorCode, LdapException
from llmp.logs import DiagnosticLogger
from llmp.security import Session, SessionStore


# ---- AdminSessionTest ----
def test_password_reset_ends_open_local_sessions(conn):
    """Сброс пароля завершает уже открытую сессию локального администратора."""
    admins = LocalAdminRepository(conn)
    admins.create("admin", "first-password-123")
    now = int(time.time())
    s = Session("x" * 64, {"admin_username": "admin", "admin_method": "local", "admin_last_activity": now,
                           "admin_login_at": now, "admin_stamp": admins.credential_stamp()})
    assert AdminSession.is_logged_in(s, None, admins.credential_stamp())
    admins.change_password("second-password-456")
    assert not AdminSession.is_logged_in(s, None, admins.credential_stamp()), "после сброса пароля прежняя сессия недействительна"
    assert dict(s) == {} and s.destroyed


def test_session_has_an_absolute_lifetime():
    """Активность не продлевает сессию бесконечно."""
    now = int(time.time())
    s = Session("x" * 64, {"admin_username": "ivanov", "admin_method": "domain", "admin_last_activity": now,
                           "admin_login_at": now - AdminSession.MAX_LIFETIME_SECONDS - 1, "admin_stamp": None})
    assert not AdminSession.is_logged_in(s)


# ---- дополнительно ----
def test_login_regenerates_session_and_idle_timeout_applies(conn):
    """Вход выдаёт новый идентификатор (прежний удаляется); бездействие дольше тайм-аута завершает сессию."""
    store = SessionStore(conn)
    s = Session(None, {"csrf_token": "t"})
    old = store.save(s)
    s = store.load(old)
    AdminSession.login(s, "admin", "local", "stamp")
    new = store.save(s)
    assert new != old and store.load(old) == {} and store.load(new)["admin_username"] == "admin"
    s = store.load(new)
    assert AdminSession.is_logged_in(s, None, "stamp") and AdminSession.username(s) == "admin" and AdminSession.method(s) == "local"
    s["admin_last_activity"] = int(time.time()) - 3601
    assert not AdminSession.is_logged_in(s, 3600, "stamp")


def test_local_admin_password_and_stamp(conn):
    """Пароль проверяется (Argon2id), имя сравнивается точно, второй администратор не создаётся, отпечаток — sha256 хеша."""
    import hashlib
    admins = LocalAdminRepository(conn)
    assert not admins.exists() and admins.credential_stamp() is None
    admins.create("админ", "pw-1234567890")
    assert admins.verify_password("админ", "pw-1234567890") and not admins.verify_password("админ", "wrong")
    assert not admins.verify_password("admin", "pw-1234567890")
    with pytest.raises(RuntimeError, match="уже создан"):
        admins.create("other", "x")
    assert admins.credential_stamp() == hashlib.sha256(admins.get()["password_hash"].encode()).hexdigest()
    assert len(LocalAdminRepository.generate_strong_password()) == 28


# ---- AD через подделку ----
@pytest.fixture
def ad(monkeypatch, tmp_path):
    directory = FakeDirectory()
    factory = fake_client_factory(directory)
    monkeypatch.setattr(auth, "LdapClient", factory)
    monkeypatch.setattr(domain_mod, "LdapClient", factory)
    return directory


class Bundle:
    def current_bundle_path(self):
        return "/nonexistent/ca-bundle.pem"


def test_domain_admin_must_be_member_of_admin_group(conn, ad):
    """Доменный админ: пароль верный и состоит в группе администраторов (вкл. вложенные) — вход; иначе — нет."""
    did = kit.create_domain(conn, "Corp", "corp.local")
    conn.execute("UPDATE ldap_configs SET admin_group_dn = 'CN=Admins,DC=corp,DC=local' WHERE id = ?", (did,))
    cfg = LlmDomainRepository(conn).find(did).config
    ad.passwords.update({"boss@corp.local": "pw", "user@corp.local": "pw"})
    ad.users.update({"boss": "CN=Boss,DC=corp,DC=local", "user": "CN=User,DC=corp,DC=local"})
    ad.members["CN=Admins,DC=corp,DC=local"] = {"CN=Boss,DC=corp,DC=local"}
    authn = DomainAdminAuthenticator(cfg, Bundle())
    assert authn.authenticate(" boss ", "pw") is True
    assert authn.authenticate("user", "pw") is False
    with pytest.raises(LdapException) as e:
        authn.authenticate("boss", "bad")
    assert e.value.error_code == LdapErrorCode.INVALID_CREDENTIALS
    assert all(c.closed for c in ad.clients)


def _chat(conn):
    did = kit.create_domain(conn, "Corp", "corp.local")
    groups = DomainGroupRepository(conn)
    domains = LlmDomainRepository(conn)
    return did, groups, domains, ChatAuthenticator(groups, domains, Bundle(), DiagnosticLogger(conn))


def test_chat_login_returns_matched_groups(conn, ad):
    """Совпадение по CN, по DN и по пользователю («группа из одного»); ненайденная группа — в unresolved и в журнале."""
    did, groups, domains, chat = _chat(conn)
    g_cn = groups.create(did, "Аналитики", "Analysts", True, "t")
    g_dn = groups.create(did, "Юристы", "CN=Lawyers,DC=corp,DC=local", True, "t")
    g_user = groups.create(did, "Лично Иванов", "Ivanov", True, "t", DomainGroupRepository.SUBJECT_USER)
    groups.create(did, "Пропавшая", "Ghost", True, "t")
    groups.create(did, "Выключенная", "Analysts2", False, "t")
    ad.passwords["ivanov@corp.local"] = "pw"
    ad.users["ivanov"] = "CN=Ivanov,DC=corp,DC=local"
    ad.groups_by_cn["Analysts"] = "CN=Analysts,DC=corp,DC=local"
    ad.members["CN=Analysts,DC=corp,DC=local"] = {"CN=Ivanov,DC=corp,DC=local"}
    ad.members["CN=Lawyers,DC=corp,DC=local"] = {"CN=Ivanov,DC=corp,DC=local"}
    r = chat.authenticate(domains.find(did), "ivanov", "pw")
    assert r == {"user_dn": "CN=Ivanov,DC=corp,DC=local", "groups": {g_cn: "Аналитики", g_dn: "Юристы", g_user: "Лично Иванов"},
                 "unresolved": ["Пропавшая"], "lookup": "user"}
    row = conn.execute("SELECT * FROM diagnostic_log").fetchone()
    assert row["source"] == "chat_auth" and row["event_code"] == "group_not_resolved"


def test_chat_login_uses_service_account_and_falls_back_to_user(conn, ad):
    """Сервисная учётка — поиск под ней; её отказ не блокирует вход: группы проверяются правами пользователя."""
    did, groups, domains, chat = _chat(conn)
    groups.create(did, "Все", "CN=All,DC=corp,DC=local", True, "t")
    domains.save_llm_settings(did, True, "svc", "svc-pw", False)
    ad.passwords.update({"ivanov@corp.local": "pw", "svc@corp.local": "svc-pw"})
    ad.users["ivanov"] = "CN=Ivanov,DC=corp,DC=local"
    ad.members["CN=All,DC=corp,DC=local"] = {"CN=Ivanov,DC=corp,DC=local"}
    d = domains.find(did)
    assert d.has_service_account() and domains.service_password(did) == "svc-pw"
    assert chat.authenticate(d, "ivanov", "pw")["lookup"] == "service"
    ad.passwords["svc@corp.local"] = "changed"
    r = chat.authenticate(d, "ivanov", "pw")
    assert r["lookup"] == "user" and list(r["groups"].values()) == ["Все"]
    assert conn.execute("SELECT event_code FROM diagnostic_log").fetchone()[0] == "service_account_bind_failed"
    with pytest.raises(LdapException):
        chat.authenticate(d, "ivanov", "wrong")
    assert all(c.closed for c in ad.clients)


def test_verify_groups_requires_service_account(conn, ad):
    """Проверка групп из админки — только под сервисной учёткой; DN, CN и пользователь проверяются по-своему."""
    did, groups, domains, chat = _chat(conn)
    groups.create(did, "DN", "CN=All,DC=corp,DC=local", True, "t")
    groups.create(did, "CN", "Missing", True, "t")
    groups.create(did, "Пользователь", "ivanov", True, "t", DomainGroupRepository.SUBJECT_USER)
    with pytest.raises(LdapException) as e:
        chat.verify_groups(domains.find(did))
    assert e.value.error_code == "SERVICE_ACCOUNT_MISSING"
    domains.save_llm_settings(did, True, "svc@other.local", "svc-pw", False)
    ad.passwords["svc@other.local"] = "svc-pw"
    ad.users["ivanov"] = "CN=Ivanov,DC=corp,DC=local"
    ad.members["CN=All,DC=corp,DC=local"] = set()
    result = {r["display_name"]: (r["ok"], r["dn"]) for r in chat.verify_groups(domains.find(did))}
    assert result == {"DN": (True, "CN=All,DC=corp,DC=local"), "CN": (False, None), "Пользователь": (True, "CN=Ivanov,DC=corp,DC=local")}


def test_domain_repositories(conn):
    """Настройки модуля домена, очистка пароля сервисной учётки, проверки ссылок на группы."""
    did = kit.create_domain(conn, "Corp", "corp.local")
    kit.create_domain(conn, "Off", "off.example.com", llm_enabled=False)
    domains = LlmDomainRepository(conn)
    assert [d.config.name for d in domains.enabled_for_chat()] == ["Corp"]
    domains.save_llm_settings(did, True, "svc", "pw", False)
    domains.save_llm_settings(did, True, "svc", None, False)
    assert domains.service_password(did) == "pw"
    domains.save_llm_settings(did, True, "", "ignored", False)
    d = domains.find(did)
    assert d.service_username is None and not d.has_service_password and domains.service_password(did) is None
    groups = DomainGroupRepository(conn)
    gid = groups.create(did, "A", "CN=A,DC=corp", True, "t")
    assert groups.ref_exists(did, "cn=a,dc=corp") and not groups.ref_exists(did, "cn=a,dc=corp", gid)
    assert groups.all()[0]["domain_fqdn"] == "corp.local"
    assert DomainGroupRepository.validate_ref("=bad") and DomainGroupRepository.validate_ref("CN=Ok,DC=x") is None
    assert DomainGroupRepository.validate_sam_account_name("iv@nov") and DomainGroupRepository.validate_sam_account_name("i.van-ov_1") is None
    assert DomainGroupRepository.validate_display_name("a\x01") and DomainGroupRepository.validate_display_name("Группа") is None
    assert ChatAuthenticator.service_principal("svc", "corp.local") == "svc@corp.local"
