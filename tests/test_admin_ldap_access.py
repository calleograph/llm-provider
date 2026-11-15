"""Разделы админки ldap, chat_access, llm_users, llm_limits: handle() напрямую с собранным AdminPage."""

from __future__ import annotations

import pytest

import kit
import kit_admin as ka
import kit_ldap
from llmp import paths, secretbox
from llmp.ldap.client import LdapException
from llmp.web.admin.base import FORM_STALE
from llmp.web.admin.sections import chat_access, ldap, llm_limits, llm_users


@pytest.fixture
def db(conn):
    return conn


def snapshot(conn) -> dict:
    tables = ("ldap_configs", "certificates", "llm_domain_groups", "llm_group_providers", "llm_user_providers",
              "llm_users", "llm_limit_categories", "app_settings", "llm_conversations", "audit_log")
    return {t: [tuple(r) for r in conn.execute(f"SELECT * FROM {t}")] for t in tables}


def run(conn, mod, action="", form=None, **kw):
    page = ka.page(conn, mod.__name__.rsplit(".", 1)[1], action, form, **kw)
    html = mod.handle(page)
    return page, html


def last_audit(conn) -> dict:
    return ka.audit(conn)[-1]


def set_password(conn, domain_id, username="svc", password="S3cret!"):
    conn.execute("UPDATE ldap_configs SET service_username = ?, service_password_encrypted = ? WHERE id = ?",
                 (username, secretbox.encrypt(password), domain_id))


def ldap_post(domain_id=None, **over):
    form = {"name": "Corp", "ldaps_url": "ldaps://dc.corp.local:636/", "host": "dc.corp.local", "port": "636",
            "protocol_version": "3", "network_timeout": "5", "base_dn": "DC=corp,DC=local", "domain": "corp.local",
            "netbios_domain": "CORP", "service_username": "svc", "service_password": "", "llm_enabled": "1"}
    if domain_id is not None:
        form["id"] = str(domain_id)
    form.update(over)
    return form


# ---- CSRF: все POST-действия ----
CSRF_CASES = [(ldap, a) for a in ldap.LDAP_ACTIONS + ldap.CERT_ACTIONS] + \
             [(chat_access, a) for a in chat_access.ACTIONS] + [(llm_users, a) for a in llm_users.ACTIONS] + \
             [(llm_limits, a) for a in llm_limits.ACTIONS]


@pytest.mark.parametrize("mod,action", CSRF_CASES, ids=lambda v: v if isinstance(v, str) else v.__name__.rsplit(".", 1)[1])
def test_missing_csrf_is_rejected_and_changes_nothing(db, mod, action):
    d = kit.create_domain(db, "Corp", "corp.local")
    set_password(db, d)
    g = kit.create_group(db, d, "G", "CN=G")
    p = kit.create_provider(db, "P1")
    db.execute("INSERT INTO llm_users (owner_key, username, domain_fqdn, first_seen_at, last_seen_at) VALUES ('ivan@corp.local','ivan','corp.local','x','x')")
    cat = db.execute("INSERT INTO llm_limit_categories (name, period, tokens, storage_mb, is_default, created_at, updated_at) "
                     "VALUES ('Base','day',100,10,0,'x','x')").lastrowid
    sess = ka.session()
    sess["pending_cert_pem"] = kit_ldap.pem(kit_ldap.make_cert()[0]).decode()
    form = {**ldap_post(d, host="new.corp.local"), f"groups[{g}][delete]": "1", f"perm[{g}][]": str(p), "owner_key": "ivan@corp.local",
            "limit_tokens": "5", "category": "manual", "period": "day", "tokens": "9", "certificate_pem": sess["pending_cert_pem"]}
    form["id"] = str(cat) if mod is llm_limits else form["id"]
    before = snapshot(db)
    page, _ = run(db, mod, action, form, csrf=False, sess=sess)
    assert page.flash_error == FORM_STALE and page.flash_success is None
    assert snapshot(db) == before
    assert "pending_cert_pem" in sess


# ---- ldap ----
def test_ldap_get_renders_forms(db):
    d = kit.create_domain(db, "Corp", "corp.local")
    _, html = run(db, ldap)
    for needle in ('id="connectionForm"', 'data-reveal="newLdapCard"', 'id="ldap-%d"' % d, 'name="service_password"', 'name="ldaps_url"',
                   'action="index.php?section=ldap&action=preview_certificate"', 'name="certificate_pem"', 'id="testLdap%d"' % d,
                   'id="deleteLdap%d"' % d, 'data-confirm-title="Удалить подключение?"', 'data-target="service_password-%d"' % d):
        assert needle in html


def test_ldap_create(db):
    page, html = run(db, ldap, "create_ldap", ldap_post(service_password="pw1"))
    assert page.flash_success == "Подключение «Corp» добавлено."
    row = db.execute("SELECT * FROM ldap_configs").fetchone()
    assert row["is_active"] == 1 and row["host"] == "dc.corp.local" and row["service_username"] == "svc"
    assert secretbox.decrypt(row["service_password_encrypted"]) == "pw1"
    a = last_audit(db)
    assert a["action"] == "ldap_create" and a["success"] == 1 and a["actor"] == ka.ADMIN
    assert a["details"] == f"id={row['id']}; name=Corp; host=dc.corp.local; llm_enabled=1; service_account=svc"


def test_ldap_create_validation_keeps_form_open(db):
    page, html = run(db, ldap, "create_ldap", ldap_post(ldaps_url="ldap://x", name=""))
    assert page.flash_error == "Проверьте выделенные поля формы нового подключения."
    assert db.execute("SELECT COUNT(*) FROM ldap_configs").fetchone()[0] == 0
    assert 'id="newLdapCard">' in html and "LDAPS URL должен начинаться с ldaps://" in html and "has-error" in html


def test_ldap_update_host_change_clears_stored_password(db):
    d = kit.create_domain(db, "Corp", "corp.local")
    set_password(db, d)
    page, _ = run(db, ldap, "update_ldap", ldap_post(d, host="evil.example.org"))
    assert page.flash_success == "Подключение «Corp» сохранено."
    row = db.execute("SELECT host, service_password_encrypted FROM ldap_configs WHERE id = ?", (d,)).fetchone()
    assert row["host"] == "evil.example.org" and row["service_password_encrypted"] is None
    a = last_audit(db)
    assert a["action"] == "ldap_update" and "changed=host" in a["details"] and "service_auth_change=cleared" in a["details"]


def test_ldap_update_same_target_keeps_password_and_new_password_replaces(db):
    d = kit.create_domain(db, "Corp", "corp.local")
    set_password(db, d)
    run(db, ldap, "update_ldap", ldap_post(d, name="Corp2"))
    assert db.execute("SELECT service_password_encrypted FROM ldap_configs").fetchone()[0] is not None
    assert "service_auth_change=kept" in last_audit(db)["details"]
    run(db, ldap, "update_ldap", ldap_post(d, host="dc2.corp.local", service_password="newpw"))
    assert secretbox.decrypt(db.execute("SELECT service_password_encrypted FROM ldap_configs").fetchone()[0]) == "newpw"
    assert "service_auth_change=replaced" in last_audit(db)["details"]
    run(db, ldap, "update_ldap", ldap_post(d, host="dc2.corp.local", clear_service_password="1"))
    assert db.execute("SELECT service_password_encrypted FROM ldap_configs").fetchone()[0] is None


def test_ldap_set_active_and_delete(db):
    d1 = kit.create_domain(db, "A", "a.example.com")
    d2 = kit.create_domain(db, "B", "b.example.com")
    page, _ = run(db, ldap, "set_active_ldap", {"id": str(d1)})
    assert page.flash_success.startswith("Подключение назначено активным")
    assert last_audit(db)["action"] == "ldap_activate"
    page, _ = run(db, ldap, "delete_ldap", {"id": str(d1)})
    assert page.flash_error == "Нельзя удалить активную конфигурацию."
    assert last_audit(db)["success"] == 0
    page, _ = run(db, ldap, "delete_ldap", {"id": str(d2)})
    assert page.flash_success.startswith("Подключение удалено")
    assert db.execute("SELECT COUNT(*) FROM ldap_configs").fetchone()[0] == 1
    assert last_audit(db)["details"] == f"id={d2}; name=B"


def test_ldap_test_uses_service_account(db, monkeypatch):
    d = kit.create_domain(db, "Corp", "corp.local")
    set_password(db, d)
    seen = {}

    def fake_check(self, config, user, password):
        seen.update(user=user, password=password)
        return {"dns_resolved": True, "tcp_reachable": True, "tls_valid": True, "bind_tested": True, "bind_successful": True,
                "steps": [{"label": "DNS-шаг", "ok": True, "detail": "ok"}]}

    monkeypatch.setattr(ldap.ConnectionDiagnostics, "check", fake_check)
    page, html = run(db, ldap, "test_ldap", {"id": str(d)})
    assert seen == {"user": "svc", "password": "S3cret!"}
    assert "DNS-шаг" in html and "llm-result" in html
    assert last_audit(db)["details"] == f"id={d}; name=Corp; tls=1; bind_requested=1; bind=success"
    diag = db.execute("SELECT * FROM diagnostic_log").fetchone()
    assert diag["source"] == "connection_test" and "bind_ok=1" in diag["message"]


def test_certificate_preview_confirm_stores_and_rebuilds_bundle(db):
    cert, _ = kit_ldap.make_cert("Corp Root CA")
    sess = ka.session()
    page, html = run(db, ldap, "preview_certificate", {"cert_type": "root", "certificate": ka.upload("root.der", kit_ldap.der(cert))}, sess=sess)
    assert page.flash_error is None and sess["pending_cert_type"] == "root"
    assert "Подтверждение загрузки сертификата" in html and "Corp Root CA" in html
    page, html = run(db, ldap, "confirm_certificate", {}, sess=sess)
    assert page.flash_success == "Сертификат сохранён и включён в CA bundle."
    assert "pending_cert_pem" not in sess
    row = db.execute("SELECT * FROM certificates").fetchone()
    assert row["type"] == "root" and row["uploaded_by"] == ka.ADMIN
    bundle = (paths.certs() / "ca-bundle.pem").read_text()
    assert "BEGIN CERTIFICATE" in bundle
    a = last_audit(db)
    assert a["action"] == "certificate_upload" and a["details"] == "fp=" + row["fingerprint_sha256"]
    # повторное подтверждение без ожидающего сертификата
    page, _ = run(db, ldap, "confirm_certificate", {}, sess=sess)
    assert page.flash_error == "Нет сертификата, ожидающего подтверждения."
    # удаление — bundle исчезает (fail-closed)
    page, _ = run(db, ldap, "delete_certificate", {"id": str(row["id"])}, sess=sess)
    assert page.flash_success == "Сертификат удалён." and not (paths.certs() / "ca-bundle.pem").exists()
    assert last_audit(db)["action"] == "certificate_delete" and last_audit(db)["details"] == f"id={row['id']}"


def test_certificate_pem_preview_errors_and_cancel(db):
    sess = ka.session()
    page, _ = run(db, ldap, "preview_certificate_pem", {"certificate_pem": "  "}, sess=sess)
    assert page.flash_error == "Вставьте содержимое сертификата в формате PEM."
    leaf, _ = kit_ldap.make_cert("Leaf", ca=False)
    page, _ = run(db, ldap, "preview_certificate_pem", {"certificate_pem": kit_ldap.pem(leaf).decode()}, sess=sess)
    assert "CA-сертификатом" in page.flash_error and "pending_cert_pem" not in sess
    page, _ = run(db, ldap, "preview_certificate", {"cert_type": "root"}, sess=sess)
    assert page.flash_error == "Файл не выбран или произошла ошибка загрузки."
    ca, _ = kit_ldap.make_cert()
    run(db, ldap, "preview_certificate_pem", {"certificate_pem": kit_ldap.pem(ca).decode(), "cert_type": "x"}, sess=sess)
    assert sess["pending_cert_type"] == "intermediate"
    run(db, ldap, "cancel_certificate", {}, sess=sess)
    assert "pending_cert_pem" not in sess and db.execute("SELECT COUNT(*) FROM certificates").fetchone()[0] == 0


# ---- chat_access ----
def test_chat_access_get_renders(db):
    d = kit.create_domain(db, "Corp", "corp.local")
    g = kit.create_group(db, d, "G", "CN=G")
    p = kit.create_provider(db, "P1")
    db.execute("INSERT INTO llm_users (owner_key, username, domain_fqdn, first_seen_at, last_seen_at) VALUES ('ivan@corp.local','ivan','corp.local','x','x')")
    _, html = run(db, chat_access)
    for needle in (f'id="domain-{d}"', f'name="groups[{g}][display_name]"', f'data-add-row="groupTemplate{d}"', f'data-add-row-target="userRows{d}"',
                   'name="new_users[__INDEX__][sam_account_name]"', f'name="perm[{g}][]" value="{p}"', 'name="user_perm[ivan@corp.local][]"',
                   'name="unanon_perm[ivan@corp.local]"', f'form="verifyGroups{d}"', "data-remove-row"):
        assert needle in html


def test_chat_access_save_subjects(db):
    d = kit.create_domain(db, "Corp", "corp.local")
    g = kit.create_group(db, d, "G", "CN=G")
    gdel = kit.create_group(db, d, "Old", "CN=Old")
    cat = db.execute("INSERT INTO llm_limit_categories (name, period, tokens, storage_mb, is_default, created_at, updated_at) "
                     "VALUES ('Base','day',100,10,0,'x','x')").lastrowid
    form = [("id", str(d)),
            (f"groups[{g}][display_name]", "G2"), (f"groups[{g}][group_ref]", "CN=G"), (f"groups[{g}][enabled]", "1"),
            (f"groups[{g}][limit_category_id]", str(cat)), (f"groups[{g}][allow_unanonymized]", "1"),
            (f"groups[{gdel}][display_name]", "Old"), (f"groups[{gdel}][group_ref]", "CN=Old"), (f"groups[{gdel}][delete]", "1"),
            ("new_groups[0][display_name]", "New"), ("new_groups[0][group_ref]", "CN=New"), ("new_groups[0][enabled]", "1"),
            ("new_groups[1][display_name]", "Bad"), ("new_groups[1][group_ref]", "CN=G"),
            ("new_users[0][sam_account_name]", "ivanov"), ("new_users[0][enabled]", "1"), ("new_users[0][limit_category_id]", str(cat))]
    page, _ = run(db, chat_access, "save_subjects", form)
    rows = {r["display_name"]: dict(r) for r in db.execute("SELECT * FROM llm_domain_groups")}
    assert set(rows) == {"G2", "New", "ivanov"}
    assert rows["G2"]["limit_category_id"] == cat and rows["G2"]["allow_unanonymized"] == 1
    assert rows["ivanov"]["subject_type"] == "user" and rows["ivanov"]["limit_category_id"] == cat
    assert page.flash_success == "Доступ к чату для «Corp» сохранён (изменений: 6)."
    assert page.flash_error == "Часть строк не сохранена: Новая группа «Bad»: такая запись уже добавлена."
    actions = [a["action"] for a in ka.audit(db)]
    assert actions == ["chat_access_update", "chat_access_unanonymized_change", "chat_access_delete", "chat_access_create", "chat_access_create"]
    page, _ = run(db, chat_access, "save_subjects", {"id": "999"})
    assert page.flash_error == "Подключение не найдено."


def test_chat_access_verify_groups(db):
    d = kit.create_domain(db, "Corp", "corp.local")
    kit.create_group(db, d, "G", "CN=G")
    page = ka.page(db, "chat_access", "verify_groups", {"id": str(d)})

    class Auth:
        def verify_groups(self, domain):
            return [{"id": 1, "display_name": "G", "group_ref": "CN=G", "ok": False, "dn": None}]

    page.module.authenticator = Auth()
    html = chat_access.handle(page)
    assert "не найдено (или CN неоднозначен)" in html
    a = last_audit(db)
    assert a["action"] == "chat_access_verify" and a["success"] == 0 and a["details"] == "domain=Corp; subjects=1; not_found=1"

    page = ka.page(db, "chat_access", "verify_groups", {"id": str(d)})
    page.module.authenticator = type("A", (), {"verify_groups": lambda self, dom: (_ for _ in ()).throw(LdapException("TLS_ERROR", "x"))})()
    chat_access.handle(page)
    assert "ошибка LDAPS (TLS_ERROR)" in page.flash_error
    assert last_audit(db)["details"] == "domain=Corp; error=TLS_ERROR"


def test_chat_access_verify_without_service_account_real_authenticator(db):
    d = kit.create_domain(db, "Corp", "corp.local")
    page, _ = run(db, chat_access, "verify_groups", {"id": str(d)})
    assert page.flash_error == "Для проверки групп задайте сервисную учётную запись домена."


def test_chat_access_save_permissions(db):
    d = kit.create_domain(db, "Corp", "corp.local")
    g = kit.create_group(db, d, "G", "CN=G")
    p1, p2 = kit.create_provider(db, "P1"), kit.create_provider(db, "P2")
    db.execute("INSERT INTO llm_users (owner_key, username, domain_fqdn, first_seen_at, last_seen_at) VALUES ('ivan@corp.local','ivan','corp.local','x','x')")
    db.execute("INSERT INTO llm_users (owner_key, username, domain_fqdn, first_seen_at, last_seen_at) VALUES ('olga@corp.local','olga','corp.local','x','x')")
    db.execute("INSERT INTO llm_user_providers (owner_key, provider_id) VALUES ('olga@corp.local', ?)", (p2,))
    form = [(f"perm[{g}][]", str(p1)), (f"perm[{g}][]", str(p2)), ("user_perm[ivan@corp.local][]", str(p1)), ("unanon_perm[ivan@corp.local]", "1")]
    page, _ = run(db, chat_access, "save_permissions", form)
    assert sorted(r[0] for r in db.execute("SELECT provider_id FROM llm_group_providers WHERE group_id = ?", (g,))) == [p1, p2]
    assert [tuple(r) for r in db.execute("SELECT owner_key, provider_id FROM llm_user_providers")] == [("ivan@corp.local", p1)]
    assert db.execute("SELECT allow_unanonymized FROM llm_users WHERE owner_key = 'ivan@corp.local'").fetchone()[0] == 1
    assert page.flash_success == ("Права сохранены (изменено: группы — 1, отдельные пользователи — 0, "
                                  "индивидуальный доступ вошедших — 2, без обезличивания — 1).")
    details = {a["action"] + ":" + a["details"] for a in ka.audit(db)}
    assert "llm_permission_change:domain=Corp; subject=G; added=P1,P2; removed=—" in details
    assert "llm_user_provider_change:user=olga@corp.local; providers=—" in details
    page, _ = run(db, chat_access, "save_permissions", form)
    assert page.flash_success == "Изменений нет."


# ---- llm_users ----
def add_user(db, owner="ivan@corp.local"):
    u, dom = owner.split("@")
    db.execute("INSERT INTO llm_users (owner_key, username, domain_fqdn, first_seen_at, last_seen_at) VALUES (?, ?, ?, '2026-01-01T10:00:00Z', '2026-01-01T10:00:00Z')",
               (owner, u, dom))


def test_llm_users_get_renders(db):
    add_user(db)
    _, html = run(db, llm_users)
    for needle in ('id="retention_days"', 'name="project_sources_max_chars"', "data-limit-form", "data-limit-category", "data-limit-manual-fields",
                   'name="owner_key" value="ivan@corp.local"', "2026-01-01 10:00", 'class="field-code"', "Пользователи (1)",
                   'data-confirm-title="Очистить всю историю пользователя?"'):
        assert needle in html


def test_llm_users_save_defaults(db):
    form = {"limit_period": "day", "limit_tokens": "1000", "retention_days": "30", "project_sources_max_chars": "40000",
            "project_sources_max_files": "4", "project_instructions_max_blocks": "5", "project_instruction_max_chars": "2000",
            "inactivity_content_purge_days": "0"}
    page, _ = run(db, llm_users, "save_defaults", form)
    assert page.flash_success.startswith("Общие параметры сохранены")
    assert db.execute("SELECT value FROM app_settings WHERE key = 'llm_chat_retention_days'").fetchone()[0] == "30"
    assert last_audit(db)["details"] == "period=day; limit=1000; retention_days=30; sources_max=40000"
    page, _ = run(db, llm_users, "save_defaults", {**form, "project_sources_max_files": "99"})
    assert page.flash_error == "Параметры не сохранены: Допустимый диапазон: 1–50."


def test_llm_users_limit_block_history(db):
    add_user(db)
    cat = db.execute("INSERT INTO llm_limit_categories (name, period, tokens, storage_mb, is_default, created_at, updated_at) "
                     "VALUES ('Base','day',100,10,0,'x','x')").lastrowid
    o = "ivan@corp.local"
    page, _ = run(db, llm_users, "save_user_limit", {"owner_key": o, "category": "manual", "period": "week", "tokens": "500"})
    assert page.flash_success == f"Лимит пользователя {o} сохранён."
    assert tuple(db.execute("SELECT limit_period, limit_tokens FROM llm_users").fetchone()) == ("week", 500)
    assert last_audit(db)["details"] == f"user={o}; period=week; limit=500"
    run(db, llm_users, "save_user_limit", {"owner_key": o, "category": str(cat)})
    assert db.execute("SELECT limit_category_id FROM llm_users").fetchone()[0] == cat
    page, _ = run(db, llm_users, "save_user_limit", {"owner_key": o, "category": "999"})
    assert page.flash_error == "Категория не найдена." and page.flash_success is None
    page, _ = run(db, llm_users, "save_user_limit", {"owner_key": o, "category": "manual", "period": "year"})
    assert page.flash_error == "Недопустимый период."
    run(db, llm_users, "save_user_limit", {"owner_key": o, "category": "inherit"})
    assert db.execute("SELECT limit_category_id FROM llm_users").fetchone()[0] is None

    page, html = run(db, llm_users, "toggle_block", {"owner_key": o})
    assert page.flash_success == f"Пользователь {o} заблокирован." and "Разблокировать" in html
    assert last_audit(db)["details"] == f"user={o}; blocked=1"

    db.execute("INSERT INTO llm_conversations (owner_key, title, provider_id, created_at, updated_at) VALUES (?, 't', NULL, 'x', 'x')", (o,))
    page, _ = run(db, llm_users, "clear_user_history", {"owner_key": o})
    assert page.flash_success == f"История пользователя {o} очищена (1 диалогов)."
    assert db.execute("SELECT COUNT(*) FROM llm_conversations").fetchone()[0] == 0
    assert last_audit(db)["action"] == "llm_user_history_clear"

    page, _ = run(db, llm_users, "toggle_block", {"owner_key": "nobody@x"})
    assert page.flash_error == "Пользователь не найден."


# ---- llm_limits ----
def test_llm_limits_crud(db):
    _, html = run(db, llm_limits)
    assert 'data-reveal="newCategoryCard"' in html and "data-storage-unlimited" in html and 'id="name-new"' in html
    page, _ = run(db, llm_limits, "create_category", {"name": "Base", "period": "day", "tokens": "1000", "storage_mb": "20", "is_default": "1"})
    assert page.flash_success == "Категория «Base» добавлена."
    cid = db.execute("SELECT id FROM llm_limit_categories WHERE name = 'Base'").fetchone()[0]
    assert last_audit(db)["details"] == f"id={cid}; name=Base; period=day; storage_mb=20; is_default=1"  # tokens отсекает LlmEventLog, как в PHP
    page, html = run(db, llm_limits, "create_category", {"name": "Base", "period": "day", "tokens": "0", "storage_mb": "0"})
    assert page.flash_error == "Проверьте выделенные поля формы."
    assert "Категория с таким названием уже есть." in html and 'id="newCategoryCard">' in html
    page, _ = run(db, llm_limits, "update_category", {"id": str(cid), "name": "Base2", "period": "none", "unlimited_storage": "1", "is_default": "1"})
    assert page.flash_success == "Категория «Base2» сохранена."
    assert tuple(db.execute("SELECT name, period, tokens, storage_mb FROM llm_limit_categories WHERE id = ?", (cid,)).fetchone()) == ("Base2", "none", None, None)
    assert "period=none; storage_mb=без ограничения" in last_audit(db)["details"]
    page, _ = run(db, llm_limits, "delete_category", {"id": str(cid)})
    assert page.flash_error.startswith("Нельзя удалить категорию по умолчанию")
    other = db.execute("INSERT INTO llm_limit_categories (name, period, tokens, storage_mb, is_default, created_at, updated_at) "
                       "VALUES ('Ext','day',100,10,0,'x','x')").lastrowid
    page, _ = run(db, llm_limits, "delete_category", {"id": str(other)})
    assert page.flash_success.startswith("Категория «Ext» удалена.")
    assert last_audit(db)["details"] == f"id={other}; name=Ext"


def test_host_field_accepts_pasted_url_and_fills_missing_address(db):
    """В поле «Хост» вставили ldaps://…:636/ — сохраняется имя контроллера; пустой URL собирается из хоста."""
    from llmp.web.admin.sections.ldap import _host_of
    assert _host_of("ldaps://ad.corp.example.com") == "ad.corp.example.com"
    assert _host_of("ldaps://dc1.corp.local:636/") == "dc1.corp.local"
    assert _host_of("dc1.corp.local:636") == "dc1.corp.local"
    assert _host_of("10.0.0.5") == "10.0.0.5"
    from llmp.ldap.config import validate_fields
    bad = {"name": "x", "host": "ldaps://x", "base_dn": "DC=x", "domain": "x", "ldaps_url": "ldaps://x", "port": 636,
           "protocol_version": 3, "network_timeout": 8}
    assert "host" in validate_fields(bad)
