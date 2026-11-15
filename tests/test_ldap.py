"""LDAP-слой: кэш маркера связи, классификация ошибок bind, безопасность TLS-настроек, экранирование фильтров,
проверка формы конфигурации, сообщения об ошибках, диагностика цепочки сертификатов
(перенос tests/AdLinkStatusCacheTest, LdapBindErrorClassificationTest, LdapClientConstantSafetyTest, LdapEscapingTest,
LdapConfigValidatorTest, ErrorMessagesTest, ConnectionDiagnosticsChainTest)."""

from __future__ import annotations

import json
import ssl
import time

import pytest

from kit_ldap import make_cert, pem
from llmp import errors
from llmp.certs import CaBundleBuilder, CertificateRepository
from llmp.db import now_iso
from llmp.ldap import LdapClient, LdapConnectionConfig, LdapErrorCode, LdapException, classify_bind_error, escape_filter
from llmp.ldap import client as client_mod
from llmp.ldap import config as ldap_config
from llmp.ldap import diagnostics as diag_mod
from llmp.ldap.diagnostics import ConnectionDiagnostics, format_dn
from llmp.ldap.status import AdLinkStatusCache
from llmp.settings import AppSettings


# ---- AdLinkStatusCacheTest ----
def test_empty_cache_returns_none(conn):
    """Пустой кэш — None."""
    assert AdLinkStatusCache(AppSettings(conn)).get() is None


def test_cached_value_is_returned_before_expiry(conn):
    """Сохранённый успех возвращается до истечения TTL."""
    cache = AdLinkStatusCache(AppSettings(conn))
    cache.set(True)
    result = cache.get()
    assert result is not None and result["ok"] is True


def test_cached_false_value_is_returned_as_false(conn):
    """Сохранённая неудача возвращается как False."""
    cache = AdLinkStatusCache(AppSettings(conn))
    cache.set(False)
    result = cache.get()
    assert result is not None and result["ok"] is False


def test_malformed_cache_value_is_treated_as_empty(conn):
    """Повреждённое значение — как пустой кэш."""
    settings = AppSettings(conn)
    settings.set("ad_link_status_cache", "not-json-at-all")
    assert AdLinkStatusCache(settings).get() is None


def test_successful_result_survives_ten_seconds(conn):
    """Успех живёт 60 с — реже дёргаем контроллер домена."""
    settings = AppSettings(conn)
    settings.set("ad_link_status_cache", json.dumps({"ok": True, "checked_at": int(time.time()) - 10}))
    result = AdLinkStatusCache(settings).get()
    assert result is not None and result["ok"] is True


def test_failed_result_expires_after_five_seconds(conn):
    """Неудача кэшируется только 5 с — кратковременный сбой не держит маркер красным."""
    settings = AppSettings(conn)
    settings.set("ad_link_status_cache", json.dumps({"ok": False, "checked_at": int(time.time()) - 10}))
    assert AdLinkStatusCache(settings).get() is None


def test_failed_result_is_still_returned_within_five_seconds(conn):
    """В пределах 5 с неудача возвращается."""
    settings = AppSettings(conn)
    settings.set("ad_link_status_cache", json.dumps({"ok": False, "checked_at": int(time.time()) - 2}))
    result = AdLinkStatusCache(settings).get()
    assert result is not None and result["ok"] is False


def test_cache_reads_value_written_by_php(conn):
    """Формат json_encode PHP ({"ok":true,"checked_at":…}) читается без изменений."""
    settings = AppSettings(conn)
    settings.set("ad_link_status_cache", '{"ok":true,"checked_at":%d}' % int(time.time()))
    assert AdLinkStatusCache(settings).get()["ok"] is True


# ---- LdapBindErrorClassificationTest ----
DIAG = "80090308: LdapErr: DSID-0C0903AA, comment: AcceptSecurityContext error, data {}, v4f7c"


def test_data_773_is_classified_as_password_must_change():
    """data 773 — требуется смена пароля."""
    assert classify_bind_error(49, DIAG.format("773")) == LdapErrorCode.PASSWORD_MUST_CHANGE


def test_data_532_is_classified_as_password_expired_not_must_change():
    """data 532 — пароль истёк, не «сменить при входе»."""
    assert classify_bind_error(49, DIAG.format("532")) == LdapErrorCode.PASSWORD_EXPIRED


def test_data_775_is_classified_as_account_locked():
    """data 775 — учётная запись заблокирована."""
    assert classify_bind_error(49, DIAG.format("775")) == LdapErrorCode.ACCOUNT_LOCKED


def test_data_52f_is_classified_as_account_restriction_not_invalid_credentials():
    """data 52f (Protected Users) — ограничение входа, а не неверный пароль."""
    diag = "80090308: LdapErr: DSID-0C090559, comment: AcceptSecurityContext error, data 52f, v65f4"
    assert classify_bind_error(49, diag) == LdapErrorCode.ACCOUNT_RESTRICTION


def test_plain_invalid_credentials_without_data_code():
    """data 52e — обычный неверный пароль."""
    assert classify_bind_error(49, DIAG.format("52e")) == LdapErrorCode.INVALID_CREDENTIALS


def test_must_change_is_not_confused_with_locked_or_disabled():
    """data 773 не путается с блокировкой, отключением, истечением и неверным паролем."""
    result = LdapClient.classify_bind_error(49, "data 773")
    assert result not in (LdapErrorCode.ACCOUNT_LOCKED, LdapErrorCode.ACCOUNT_DISABLED, LdapErrorCode.PASSWORD_EXPIRED,
                          LdapErrorCode.INVALID_CREDENTIALS)
    assert result == LdapErrorCode.PASSWORD_MUST_CHANGE


def test_other_errno_classification():
    """533 — отключена; 52/-1/81 — сервер недоступен; 50 — нет прав; прочее — unknown."""
    assert classify_bind_error(49, "data 533") == LdapErrorCode.ACCOUNT_DISABLED
    assert {classify_bind_error(n, "") for n in (52, -1, 81)} == {LdapErrorCode.SERVER_UNAVAILABLE}
    assert classify_bind_error(50, "") == LdapErrorCode.INSUFFICIENT_ACCESS
    assert classify_bind_error(1, "") == LdapErrorCode.UNKNOWN


# ---- поддельный слой ldap3 ----
def _config(**over) -> LdapConnectionConfig:
    base = dict(id=1, name="AD", is_active=True, ldaps_url="ldaps://dc1.corp.local:636/", host="dc1.corp.local", port=636,
                base_dn="DC=corp,DC=local", domain="corp.local", netbios_domain="CORP", admin_group_dn="CN=Admins,DC=corp,DC=local",
                protocol_version=3, start_tls=False, network_timeout=7, ca_bundle_file=None)
    return LdapConnectionConfig(**{**base, **over})


class FakeConnection:
    """Вместо ldap3.Connection: запоминает параметры и запросы, отвечает заданными результатами."""

    instances: list["FakeConnection"] = []
    bind_result: dict = {"result": 0}
    bind_raises: Exception | None = None
    search_results: list = []   # [(result_dict, response_list)] по очереди

    def __init__(self, server, **kwargs):
        self.server, self.kwargs = server, kwargs
        self.searches, self.binds, self.unbound = [], [], False
        self.result, self.response = None, None
        self.user = self.password = self.authentication = None
        FakeConnection.instances.append(self)

    def bind(self, read_server_info=True):
        self.binds.append((self.authentication, self.user, self.password))
        if FakeConnection.bind_raises:
            raise FakeConnection.bind_raises
        self.result = FakeConnection.bind_result
        return self.result["result"] == 0

    def search(self, base, flt, search_scope=None, attributes=None):
        self.searches.append((base, flt, search_scope, attributes))
        self.result, self.response = FakeConnection.search_results.pop(0)
        return bool(self.response)

    def unbind(self):
        self.unbound = True


@pytest.fixture
def fake_ldap(monkeypatch, tmp_path):
    FakeConnection.instances, FakeConnection.search_results = [], []
    FakeConnection.bind_result, FakeConnection.bind_raises = {"result": 0}, None
    monkeypatch.setattr(client_mod, "Connection", FakeConnection)
    bundle = tmp_path / "ca-bundle.pem"
    bundle.write_bytes(pem(make_cert()[0]))
    return str(bundle)


def _entry(dn):
    return {"type": "searchResEntry", "dn": dn, "attributes": {}}


# ---- LdapClientConstantSafetyTest (эквивалент для ldap3) ----
def test_tls_settings_are_never_weakened(fake_ldap):
    """Аналог проверки защищённых TLS-констант: LDAPS с CERT_REQUIRED по нашему CA bundle, реферралы выключены,
    таймауты из конфигурации — у ldap3 нет «необязательных» TLS-опций, поэтому проверяем сами параметры соединения."""
    c = LdapClient(_config(), fake_ldap)
    c.connect()
    conn = FakeConnection.instances[-1]
    assert conn.server.ssl is True and conn.server.port == 636 and conn.server.host == "dc1.corp.local"
    assert conn.server.tls.validate == ssl.CERT_REQUIRED and conn.server.tls.ca_certs_file == fake_ldap
    assert conn.kwargs["auto_referrals"] is False and conn.kwargs["receive_timeout"] == 7 and conn.server.connect_timeout == 7


def test_missing_ca_bundle_or_plain_ldap_fails_closed(fake_ldap, tmp_path):
    """Без CA bundle или по открытому ldap:// без StartTLS — LdapException(TLS_ERROR), а не тихое подключение без проверки."""
    with pytest.raises(LdapException) as e:
        LdapClient(_config(), str(tmp_path / "missing.pem")).connect()
    assert e.value.error_code == LdapErrorCode.TLS_ERROR
    with pytest.raises(LdapException) as e:
        LdapClient(_config(ldaps_url="ldap://dc1.corp.local:389/"), fake_ldap).connect()
    assert e.value.error_code == LdapErrorCode.TLS_ERROR
    assert FakeConnection.instances == []


def test_bind_error_from_ad_is_classified(fake_ldap):
    """Ответ AD 49 с data 775 превращается в ACCOUNT_LOCKED; пароль не остаётся в объекте соединения."""
    FakeConnection.bind_result = {"result": 49, "message": DIAG.format("775")}
    c = LdapClient(_config(), fake_ldap)
    c.connect()
    with pytest.raises(LdapException) as e:
        c.bind("ivanov@corp.local", "secret")
    assert e.value.error_code == LdapErrorCode.ACCOUNT_LOCKED
    assert FakeConnection.instances[-1].password is None


def test_bind_transport_errors_and_empty_password(fake_ldap):
    """Сбой TLS — TLS_ERROR, таймаут — TIMEOUT; DN с пустым паролем не отправляется (это был бы анонимный bind)."""
    from ldap3.core.exceptions import LDAPSocketOpenError
    c = LdapClient(_config(), fake_ldap)
    c.connect()
    FakeConnection.bind_raises = LDAPSocketOpenError("socket ssl wrapping error: [SSL: CERTIFICATE_VERIFY_FAILED]")
    with pytest.raises(LdapException) as e:
        c.bind("", "")
    assert e.value.error_code == LdapErrorCode.TLS_ERROR
    FakeConnection.bind_raises = LDAPSocketOpenError("timed out")
    with pytest.raises(LdapException) as e:
        c.bind("", "")
    assert e.value.error_code == LdapErrorCode.TIMEOUT
    binds = len(FakeConnection.instances[-1].binds)
    with pytest.raises(LdapException) as e:
        c.bind("ivanov@corp.local", "")
    assert e.value.error_code == LdapErrorCode.INVALID_CREDENTIALS and len(FakeConnection.instances[-1].binds) == binds


def test_searches_escape_values_and_use_in_chain_rule(fake_ldap):
    """Поиск пользователя/группы экранирует значение; членство — правило 1.2.840.113556.1.4.1941 на чтении DN группы."""
    from ldap3 import BASE, SUBTREE
    ok = {"result": 0}
    FakeConnection.search_results = [
        (ok, [_entry("CN=Ivanov,DC=corp,DC=local"), {"type": "searchResRef", "uri": ["ldap://x"]}]),
        (ok, [_entry("CN=G,DC=corp,DC=local")]),
        (ok, [_entry("CN=G,DC=corp,DC=local")]),
        ({"result": 32, "description": "noSuchObject"}, []),
    ]
    c = LdapClient(_config(), fake_ldap)
    c.connect()
    assert c.find_user_dn_by_sam_account_name("iv*)(") == "CN=Ivanov,DC=corp,DC=local"
    assert c.find_group_dn_by_cn("G(1)") == "CN=G,DC=corp,DC=local"
    assert c.is_member_of_group_recursive("CN=Iv\\, A,DC=corp", "CN=G,DC=corp,DC=local") is True
    assert c.group_exists("CN=Missing,DC=corp,DC=local") is False
    s = FakeConnection.instances[-1].searches
    assert s[0][:3] == ("DC=corp,DC=local", "(&(objectClass=user)(objectCategory=person)(sAMAccountName=iv\\2a\\29\\28))", SUBTREE)
    assert s[1][1] == "(&(objectClass=group)(cn=G\\281\\29))"
    assert s[2][:3] == ("CN=G,DC=corp,DC=local", "(member:1.2.840.113556.1.4.1941:=CN=Iv\\5c, A,DC=corp)", BASE)
    c.close()
    assert FakeConnection.instances[-1].unbound


def test_ambiguous_user_search_is_an_error_and_ambiguous_group_is_none(fake_ldap):
    """Несколько пользователей с одним sAMAccountName — ошибка; несколько групп с одним CN — «не найдена»."""
    ok = {"result": 0}
    FakeConnection.search_results = [(ok, [_entry("CN=a"), _entry("CN=b")]), (ok, [_entry("CN=a"), _entry("CN=b")])]
    c = LdapClient(_config(), fake_ldap)
    c.connect()
    with pytest.raises(LdapException):
        c.find_user_dn_by_sam_account_name("dup")
    assert c.find_group_dn_by_cn("dup") is None


def test_extensible_filter_is_accepted_by_ldap3():
    """Фильтр вложенного членства с экранированным DN разбирается ldap3 (иначе запрос не ушёл бы на сервер)."""
    from ldap3.operation.search import parse_filter
    flt = f"(member:1.2.840.113556.1.4.1941:={escape_filter('CN=Iv\\, A(1)*,DC=corp')})"
    assert parse_filter(flt, None, False, None, None, True) is not None


# ---- LdapEscapingTest ----
def test_wildcard_is_escaped():
    """«*» → \\2a."""
    assert escape_filter("*") == "\\2a"


def test_parentheses_are_escaped():
    """Скобки не остаются в значении."""
    escaped = escape_filter("admin)(|(objectClass=*")
    assert "(" not in escaped and ")" not in escaped


def test_backslash_is_escaped():
    """Обратная косая → \\5c."""
    assert escape_filter("a\\b") == "a\\5cb"


def test_null_byte_is_escaped():
    """NUL не остаётся в значении."""
    assert "\0" not in escape_filter("a\0b")


def test_filter_injection_attempt_cannot_break_out_of_filter():
    """Попытка закрыть фильтр и добавить свой — в итоге только скобки шаблона."""
    flt = f"(&(objectClass=user)(sAMAccountName={escape_filter('*)(uid=*))(|(uid=*')}))"
    assert flt.count("(") == flt.count(")") == 3


# ---- LdapConfigValidatorTest ----
def _valid() -> dict:
    return {
        "name": "Production AD", "ldaps_url": "ldaps://dc1.corp.local:636/", "host": "dc1.corp.local", "port": 636,
        "base_dn": "DC=corp,DC=local", "domain": "corp.local", "netbios_domain": "CORP",
        "admin_group_dn": "CN=Отдел ИТ,OU=Отдел ИТ,OU=АУП,OU=CORP,DC=corp,DC=local", "protocol_version": 3, "network_timeout": 8,
    }


def test_valid_config_passes():
    """Корректные данные — без ошибок."""
    assert ldap_config.validate(_valid()) is None


def test_rejects_empty_name():
    """Пустое имя — ошибка."""
    assert ldap_config.validate({**_valid(), "name": ""}) is not None


def test_rejects_non_ldaps_url():
    """ldap:// не принимается."""
    assert ldap_config.validate({**_valid(), "ldaps_url": "ldap://dc1.corp.local:389/"}) is not None


def test_rejects_invalid_port():
    """Порт вне 1–65535 — ошибка."""
    assert ldap_config.validate({**_valid(), "port": 0}) is not None
    assert ldap_config.validate({**_valid(), "port": 70000}) is not None


def test_rejects_invalid_protocol_version():
    """Версии протокола кроме 2 и 3 — ошибка."""
    assert ldap_config.validate({**_valid(), "protocol_version": 1}) is not None
    assert ldap_config.validate({**_valid(), "protocol_version": 4}) is not None


def test_accepts_protocol_version_2_and_3():
    """Версии 2 и 3 допустимы."""
    assert ldap_config.validate({**_valid(), "protocol_version": 2}) is None
    assert ldap_config.validate({**_valid(), "protocol_version": 3}) is None


def test_rejects_missing_base_dn():
    """Пустой Base DN — ошибка."""
    assert ldap_config.validate({**_valid(), "base_dn": ""}) is not None


def test_rejects_invalid_network_timeout():
    """Таймаут вне 1–120 — ошибка."""
    assert ldap_config.validate({**_valid(), "network_timeout": 0}) is not None
    assert ldap_config.validate({**_valid(), "network_timeout": 500}) is not None


def test_validate_fields_returns_empty_dict_for_valid_data():
    """Корректные данные — пустой словарь ошибок."""
    assert ldap_config.validate_fields(_valid()) == {}


def test_validate_fields_identifies_specific_field_for_empty_name():
    """Ошибка привязана к полю name, а не к host."""
    errs = ldap_config.validate_fields({**_valid(), "name": ""})
    assert "name" in errs and "host" not in errs


def test_validate_fields_identifies_specific_field_for_bad_port():
    """Плохой порт — ровно одна ошибка, у поля port."""
    errs = ldap_config.validate_fields({**_valid(), "port": 0})
    assert list(errs) == ["port"]


def test_validate_fields_reports_multiple_errors_simultaneously():
    """Несколько ошибок сразу."""
    errs = ldap_config.validate_fields({**_valid(), "name": "", "host": "", "port": -1})
    assert {"name", "host", "port"} <= set(errs)


def test_validate_returns_first_error_from_validate_fields():
    """validate() — первая ошибка validate_fields()."""
    data = {**_valid(), "name": "", "host": ""}
    assert ldap_config.validate(data) == next(iter(ldap_config.validate_fields(data).values()))


def test_config_repository_keeps_exactly_one_active(conn):
    """Репозиторий: новая активная снимает флаг с прежней, активную удалить нельзя."""
    repo = ldap_config.LdapConfigRepository(conn)
    data = {**_valid(), "starttls": False, "ca_bundle_file": None}
    a = repo.create(data, make_active=True)
    b = repo.create({**data, "name": "Second"}, make_active=True)
    assert repo.find_active().id == b and not repo.find_by_id(a).is_active
    repo.set_active(a)
    assert repo.find_active().id == a
    with pytest.raises(RuntimeError, match="активную"):
        repo.delete(a)
    repo.update(b, {**data, "name": "Renamed", "network_timeout": 9})
    assert repo.find_by_id(b).network_timeout == 9 and repo.name_exists("Renamed") and not repo.name_exists("Renamed", b)
    repo.delete(b)
    assert [c.name for c in repo.all()] == ["Production AD"]


# ---- ErrorMessagesTest ----
def test_password_must_change_has_dedicated_user_facing_message():
    """У «сменить пароль при входе» есть своё сообщение."""
    assert errors.for_code(LdapErrorCode.PASSWORD_MUST_CHANGE)


def test_password_must_change_message_does_not_leak_ldap_internals():
    """Сообщение без кодов AD и слова LDAP."""
    msg = errors.for_code(LdapErrorCode.PASSWORD_MUST_CHANGE)
    assert "773" not in msg and "LDAP" not in msg and "DSID" not in msg


def test_password_must_change_is_distinct_from_password_expired():
    """Отличается от «пароль истёк»."""
    assert errors.for_code(LdapErrorCode.PASSWORD_MUST_CHANGE) != errors.for_code(LdapErrorCode.PASSWORD_EXPIRED)


def test_unknown_code_falls_back_to_generic_message():
    """Неизвестный код — общее сообщение."""
    assert errors.for_code("some_unmapped_code")


def test_account_restriction_has_distinct_message():
    """Ограничение входа отличается от недоступности сервера и неверного пароля."""
    msg = errors.for_code(LdapErrorCode.ACCOUNT_RESTRICTION)
    assert msg != errors.for_code(LdapErrorCode.SERVER_UNAVAILABLE)
    assert msg != errors.for_code(LdapErrorCode.INVALID_CREDENTIALS)


# ---- ConnectionDiagnosticsChainTest ----
def _repo(conn, subjects: list[str]) -> CertificateRepository:
    for i, subject in enumerate(subjects):
        conn.execute(
            "INSERT INTO certificates (filename, type, subject, issuer, serial, fingerprint_sha256, valid_from, valid_to, uploaded_by, uploaded_at) "
            "VALUES (?, 'root', ?, ?, ?, ?, '2024-01-01 00:00:00 UTC', '2030-01-01 00:00:00 UTC', 'admin', ?)",
            (f"cert{i}.pem", subject, subject, str(i), str(i).ljust(64, "0"), now_iso()),
        )
    return CertificateRepository(conn)


def test_issuer_not_found_when_only_root_ca_uploaded_but_cert_signed_by_intermediate(conn):
    """Загружен только Root CA, сервер подписан Intermediate — издатель не найден."""
    repo = _repo(conn, ["CN=CORP-RootCA"])
    d = ConnectionDiagnostics(CaBundleBuilder(repo), repo)
    assert d._issuer_is_among_uploaded_certificates("DC=local, DC=corp, CN=CORP-IssuingCA") is False


def test_issuer_found_when_both_root_and_intermediate_uploaded(conn):
    """Загружены Root и Intermediate — издатель найден."""
    repo = _repo(conn, ["CN=CORP-RootCA", "DC=local, DC=corp, CN=CORP-IssuingCA"])
    d = ConnectionDiagnostics(CaBundleBuilder(repo), repo)
    assert d._issuer_is_among_uploaded_certificates("DC=local, DC=corp, CN=CORP-IssuingCA") is True


def test_returns_true_when_no_certificate_repository_provided(conn):
    """Без репозитория — без ложной тревоги."""
    d = ConnectionDiagnostics(CaBundleBuilder(_repo(conn, [])))
    assert d._issuer_is_among_uploaded_certificates("CN=anything") is True


def test_issuer_matches_despite_different_multi_valued_rdn_formatting(conn):
    """Нормализация находит совпадение при другом порядке и разделителе «+»."""
    repo = _repo(conn, ["DC=local, DC=corp, CN=CORP-IssuingCA"])
    d = ConnectionDiagnostics(CaBundleBuilder(repo), repo)
    assert d._issuer_is_among_uploaded_certificates("CN=CORP-IssuingCA, DC=corp+DC=local") is True


def test_format_dn_produces_keyed_segments_for_multi_valued_rdn():
    """Многозначный RDN — ключ у каждого значения."""
    assert format_dn({"DC": ["local", "corp"], "CN": "CORP-IssuingCA"}) == "DC=local, DC=corp, CN=CORP-IssuingCA"


# ---- ConnectionDiagnostics.check: путь шагов без сети ----
def test_check_stops_at_dns_failure(monkeypatch, conn):
    """Имя не разрешается — один шаг DNS и выход."""
    monkeypatch.setattr(diag_mod, "resolve", lambda host: host)
    r = ConnectionDiagnostics(CaBundleBuilder(_repo(conn, []))).check(_config(), None, None)
    assert r["dns_resolved"] is False and len(r["steps"]) == 1


def test_check_reports_tls_ok_when_anonymous_bind_refused_by_policy_and_runs_test_bind(monkeypatch, conn, tmp_path):
    """Анонимный bind отклонён политикой (не TLS) — TLS пройден; тестовый bind показывает понятное сообщение."""
    from kit_ldap import FakeDirectory, fake_client_factory

    class Builder:
        def current_bundle_path(self):
            return str(tmp_path / "b.pem")

    directory = FakeDirectory()
    directory.bind_errors[""] = LdapErrorCode.INSUFFICIENT_ACCESS
    directory.bind_errors["ivanov@corp.local"] = LdapErrorCode.ACCOUNT_LOCKED
    monkeypatch.setattr(diag_mod, "resolve", lambda host: "10.0.0.5")
    monkeypatch.setattr(diag_mod.socket, "create_connection", lambda *a, **k: type("S", (), {"close": lambda self: None})())
    monkeypatch.setattr(ConnectionDiagnostics, "_read_peer_certificate", staticmethod(lambda *a: None))
    monkeypatch.setattr(diag_mod, "LdapClient", fake_client_factory(directory))
    r = ConnectionDiagnostics(Builder()).check(_config(), "ivanov", "pw")
    assert r["tls_valid"] and r["chain_valid"] and r["bind_tested"] and r["bind_successful"] is False
    assert r["bind_error_code"] == LdapErrorCode.ACCOUNT_LOCKED
    assert r["steps"][-1]["detail"] == errors.for_code(LdapErrorCode.ACCOUNT_LOCKED)
    assert directory.clients[0].closed
