"""Оболочка админки (вход, первый запуск, выход, диспетчер разделов) и разделы status/services/access/system/
maintenance/log, резервная копия конфигурации LLM-чата."""

from __future__ import annotations

import io
import json
import re
import sys
import time
import zipfile

import pytest
from fastapi.testclient import TestClient

from llmp import cli, db, paths
from llmp.backup_config import ConfigBackupException, ConfigBackupService
from llmp.module import LlmModule
from llmp.web.app import create_app

import kit

PASSWORD = "Very-Long-Admin-Pass-1"


def csrf_of(html: str) -> str:
    return re.search(r'name="csrf" value="([^"]+)"', html).group(1)


@pytest.fixture
def client() -> TestClient:
    return TestClient(create_app(paths.database()))


def setup_admin(c: TestClient, password: str = PASSWORD):
    tok = csrf_of(c.get("/admin/setup.php").text)
    return c.post("/admin/setup.php", data={"csrf": tok, "password": password, "password_confirm": password}, follow_redirects=False)


def login(c: TestClient, password: str = PASSWORD, username: str = "admin"):
    tok = csrf_of(c.get("/admin/index.php").text)
    return c.post("/admin/index.php?action=login", data={"csrf": tok, "method": "local", "username": username, "password": password},
                  follow_redirects=False)


@pytest.fixture
def admin(client) -> TestClient:
    setup_admin(client)
    return client


def conn():
    return db.connect(paths.database())


# ---- первый запуск и вход ----
def test_first_run_redirects_to_setup(client):
    for url in ("/admin/", "/admin/index.php"):
        r = client.get(url, follow_redirects=False)
        assert r.status_code == 302 and r.headers["location"] == "setup.php"
    assert client.get("/admin", follow_redirects=False).headers["location"] == "admin/"


def test_setup_validates_and_creates_admin(client):
    tok = csrf_of(client.get("/admin/setup.php").text)
    r = client.post("/admin/setup.php", data={"csrf": tok, "password": "short", "password_confirm": "short"})
    assert "не менее 14 символов" in r.text
    r = client.post("/admin/setup.php", data={"csrf": tok, "password": PASSWORD, "password_confirm": PASSWORD + "x"})
    assert "не совпадают" in r.text
    r = setup_admin(client)
    assert r.status_code == 302 and r.headers["location"] == "index.php?section=status&first_setup=1"
    assert paths.installed_marker().is_file()
    page = client.get("/admin/index.php?section=status&first_setup=1")
    assert page.status_code == 200 and "Аварийный доступ создан" in page.text and 'id="settingsSearchIndex"' in page.text
    # повторно форма недоступна
    assert client.get("/admin/setup.php", follow_redirects=False).headers["location"] == "index.php"


def test_login_over_plain_http_without_secure_cookie(admin):
    fresh = TestClient(admin.app)   # http://testserver, без куки
    r = login(fresh)
    assert r.status_code == 302 and r.headers["location"] == "index.php"
    cookie = r.headers["set-cookie"]
    assert "ADPWD_SESSION=" in cookie and "secure" not in cookie.lower()
    page = fresh.get("/admin/index.php")
    assert page.status_code == 200 and "Сеанс администратора" in page.text and "https" not in page.text.lower().split("<main")[1][:2000]
    c = conn()
    assert c.execute("SELECT success, details FROM audit_log WHERE action = 'admin_login' ORDER BY id DESC").fetchone()[:] == (1, "method=local")


def test_login_cookie_secure_behind_https_proxy(admin):
    fresh = TestClient(admin.app)
    tok = csrf_of(fresh.get("/admin/index.php").text)
    r = fresh.post("/admin/index.php?action=login", data={"csrf": tok, "method": "local", "username": "admin", "password": PASSWORD},
                   headers={"X-Forwarded-Proto": "https"}, follow_redirects=False)
    assert "secure" in r.headers["set-cookie"].lower()


def test_wrong_password_is_rate_limited(admin):
    fresh = TestClient(admin.app)
    tok = csrf_of(fresh.get("/admin/index.php").text)
    texts = []
    for _ in range(6):
        r = fresh.post("/admin/index.php?action=login", data={"csrf": tok, "method": "local", "username": "admin", "password": "wrong"})
        texts.append(r.text)
    assert all("Неверный логин или пароль." in t for t in texts[:5])
    assert "Слишком много попыток входа" in texts[5] and "повтор через" in texts[5]
    # даже верный пароль не проходит, пока действует задержка
    r = fresh.post("/admin/index.php?action=login", data={"csrf": tok, "method": "local", "username": "admin", "password": PASSWORD},
                   follow_redirects=False)
    assert r.status_code == 200 and "Слишком много попыток входа" in r.text


def test_stale_csrf_on_login(admin):
    fresh = TestClient(admin.app)
    fresh.get("/admin/index.php")
    r = fresh.post("/admin/index.php?action=login", data={"csrf": "x", "method": "local", "username": "admin", "password": PASSWORD})
    assert "Форма устарела" in r.text


def test_logout_via_get(admin):
    r = admin.get("/admin/index.php?action=logout", follow_redirects=False)
    assert r.status_code == 302 and r.headers["location"] == "index.php"
    assert "Вход в администрирование" in admin.get("/admin/index.php").text
    assert conn().execute("SELECT COUNT(*) FROM audit_log WHERE action = 'admin_logout'").fetchone()[0] == 1


def test_idle_timeout_ends_session(admin):
    c = conn()
    c.execute("UPDATE web_sessions SET data = json_set(data, '$.admin_last_activity', 1)")
    page = admin.get("/admin/index.php")
    assert "Вход в администрирование" in page.text
    # форма входа после таймаута рабочая: токен сохранён в новой сессии
    assert login(admin).status_code == 302


# ---- пароль локального администратора ----
def test_password_change_keeps_own_session_and_reset_ends_others(admin):
    other = TestClient(admin.app)
    assert login(other).status_code == 302
    tok = csrf_of(admin.get("/admin/index.php?section=access").text)
    new = "Another-Long-Password-42"
    r = admin.post("/admin/index.php?section=access&action=change_local_admin_password",
                   data={"csrf": tok, "new_local_password": new, "confirm_local_password": new})
    assert "Пароль локального администратора изменён." in r.text
    assert "Сеанс администратора" in admin.get("/admin/index.php").text
    assert "Вход в администрирование" in other.get("/admin/index.php").text   # прежний отпечаток пароля
    assert login(other, new).status_code == 302

    sys_stdout = sys.stdout
    sys.stdout = io.StringIO()
    try:
        cli.admin_reset()
    finally:
        sys.stdout = sys_stdout
    assert "Вход в администрирование" in admin.get("/admin/index.php").text
    assert "Вход в администрирование" in other.get("/admin/index.php").text


def test_password_change_validation(admin):
    tok = csrf_of(admin.get("/admin/index.php?section=access").text)
    r = admin.post("/admin/index.php?section=access&action=change_local_admin_password",
                   data={"csrf": tok, "new_local_password": "short", "confirm_local_password": "short"})
    assert "не короче 16 символов" in r.text


# ---- разделы ----
@pytest.mark.parametrize("section", ["status", "services", "access", "system", "maintenance", "log", "updates"])
def test_sections_render(admin, section):
    r = admin.get(f"/admin/index.php?section={section}")
    assert r.status_code == 200
    assert f'href="index.php?section={section}" class="active"' in r.text


@pytest.mark.parametrize("tab", ["all", "operations", "audit", "diagnostics"])
def test_log_tabs_render(admin, tab):
    r = admin.get(f"/admin/index.php?section=log&tab={tab}")
    assert r.status_code == 200 and "Всего записей" in r.text


def test_missing_section_placeholder(admin, monkeypatch):
    import llmp.web.admin.routes as routes
    monkeypatch.setattr(routes, "SECTIONS", routes.SECTIONS + ("nonexistent",))
    monkeypatch.setitem(routes.SECTION_LABELS, "nonexistent", "Не перенесён")
    r = admin.get("/admin/index.php?section=nonexistent")
    assert r.status_code == 200 and "Раздел переносится" in r.text


def test_section_exception_gives_500_page(admin, monkeypatch):
    from llmp.web.admin.sections import status
    monkeypatch.setattr(status, "handle", lambda page: 1 / 0)
    r = admin.get("/admin/index.php?section=status")
    assert r.status_code == 500 and "непредвиденная ошибка" in r.text
    assert conn().execute("SELECT COUNT(*) FROM diagnostic_log WHERE event_code = 'ZeroDivisionError'").fetchone()[0] == 1


def test_log_export_has_no_injected_lines(admin):
    c = conn()
    c.execute("INSERT INTO audit_log (created_at, actor, ip, action, details, success) VALUES ('2026-01-01T00:00:00+00:00', ?, '1.2.3.4', 'x', ?, 1)",
              ("evil\nactor", "a\tb\r\n2026-01-01\tforged\tline"))
    c.execute("INSERT INTO app_log (created_at, ip, username, operation, result) VALUES ('2026-01-01T00:00:00+00:00', '1.1.1.1', ?, 'OP', ?)",
              ("u\nx", "SUCCESS\nforged"))
    c.execute("INSERT INTO diagnostic_log (created_at, level, source, event_code, message, context, ip) VALUES ('2026-01-01T00:00:00+00:00', 'error', 'tls', ?, ?, NULL, NULL)",
              ("c\td", "m\nn"))
    assert admin.get("/admin/index.php?section=log&action=export&export_tab=all").status_code == 403   # без токена — нет
    tok = re.search(r"action=export&amp;csrf=([0-9a-f]+)|action=export&csrf=([0-9a-f]+)", admin.get("/admin/index.php?section=log").text)
    tok = tok.group(1) or tok.group(2)                                                  # токен — в ссылке «Скачать .txt»
    for tab, cols in (("audit", 6), ("operations", 5), ("diagnostics", 7), ("all", 7)):
        r = admin.get(f"/admin/index.php?section=log&action=export&csrf={tok}&export_tab={tab}")
        assert r.status_code == 200 and r.headers["content-type"].startswith("text/plain")
        assert "attachment" in r.headers["content-disposition"]
        lines = r.text.split("\n")
        assert lines[-1] == ""
        assert lines[:-1] and all(len(line.split("\t")) == cols for line in lines[:-1]) and "\r" not in r.text


def test_maintenance_helper_request(admin, monkeypatch, tmp_path):
    monkeypatch.setenv("UPDATER_DIR", str(tmp_path / "upd"))
    (tmp_path / "upd" / "inbox").mkdir(parents=True)
    tok = csrf_of(admin.get("/admin/index.php?section=maintenance").text)
    r = admin.post("/admin/index.php?section=maintenance&action=helper_request", data={"csrf": tok, "helper_action": "rm -rf"})
    assert "Недопустимое действие." in r.text
    r = admin.post("/admin/index.php?section=maintenance&action=helper_request", data={"csrf": tok, "helper_action": "backup"})
    assert "передан помощнику" in r.text
    content = (tmp_path / "upd" / "inbox" / "request.txt").read_text()
    assert re.fullmatch(r"action=backup\nid=[0-9a-f]{16}\n", content)
    r = admin.post("/admin/index.php?section=maintenance&action=helper_request", data={"csrf": tok, "helper_action": "check"})
    assert "ещё не забран" in r.text


def test_maintenance_quick_check_cached(admin):
    admin.get("/admin/index.php?section=maintenance")
    c = conn()
    cached = json.loads(c.execute("SELECT value FROM app_settings WHERE key = 'maintenance_db_quick_check'").fetchone()[0])
    assert cached["ok"] is True
    c.execute("UPDATE app_settings SET value = ? WHERE key = 'maintenance_db_quick_check'", (json.dumps({"ok": False, "at": cached["at"]}),))
    assert "Ошибка целостности" in admin.get("/admin/index.php?section=maintenance").text   # из кэша, без повторной проверки


def test_system_settings_actions(admin):
    tok = csrf_of(admin.get("/admin/index.php?section=system").text)
    r = admin.post("/admin/index.php?section=system&action=update_admin_idle_timeout", data={"csrf": tok, "admin_idle_timeout_minutes": "2"})
    assert "Таймаут — от 5 до 1440 минут." in r.text
    r = admin.post("/admin/index.php?section=system&action=update_admin_idle_timeout", data={"csrf": tok, "admin_idle_timeout_minutes": "30"})
    assert "обновлён" in r.text
    r = admin.post("/admin/index.php?section=system&action=update_trusted_proxies", data={"csrf": tok, "trusted_proxies": "10.0.0.1, bad"})
    assert "Некорректный адрес или подсеть: bad" in r.text
    r = admin.post("/admin/index.php?section=system&action=update_trusted_proxies", data={"csrf": tok, "trusted_proxies": "10.0.0.1, 10.0.0.0/24"})
    assert "10.0.0.1, 10.0.0.0/24" in r.text


def test_site_theme_setting_and_html_attribute(admin):
    # по умолчанию «Стекло»; тема администратора попадает в data-site-theme на <html> всех страниц, неизвестная — отклоняется
    page = admin.get("/admin/index.php?section=system").text
    assert '<html lang="ru" data-site-theme="glass">' in page and "data-theme-switch" in page   # одна кнопка солнце/луна
    tok = csrf_of(page)
    r = admin.post("/admin/index.php?section=system&action=update_site_theme", data={"csrf": tok, "site_theme": "amber"})
    assert "Неизвестная тема оформления." in r.text and 'data-site-theme="glass"' in r.text
    r = admin.post("/admin/index.php?section=system&action=update_site_theme", data={"csrf": "stale", "site_theme": "dark"})
    assert 'data-site-theme="glass"' in r.text
    r = admin.post("/admin/index.php?section=system&action=update_site_theme", data={"csrf": tok, "site_theme": "dark"})
    assert "Тема по умолчанию обновлена." in r.text and 'data-site-theme="dark"' in r.text
    assert '<option value="dark" selected>' in r.text
    assert 'data-site-theme="dark"' in TestClient(admin.app).get("/index.php").text        # вход в чат — без сеанса
    c = conn()
    assert c.execute("SELECT value FROM app_settings WHERE key = 'site_theme'").fetchone()[0] == "dark"
    c.execute("UPDATE app_settings SET value = 'bogus' WHERE key = 'site_theme'")       # испорченное значение — снова «Стекло»
    c.commit()
    assert 'data-site-theme="glass"' in admin.get("/admin/index.php?section=status").text


def test_config_export_import_via_pages(admin):
    c = conn()
    d = kit.create_domain(c, "Corp", "corp.example")
    g = kit.create_group(c, d, "Users", "CN=Users,DC=corp,DC=example")
    p = kit.create_provider(c, "Polza")
    c.execute("INSERT INTO llm_group_providers (group_id, provider_id) VALUES (?, ?)", (g, p))
    assert admin.get("/admin/index.php?section=system&action=export_llm_config&csrf=bad").status_code == 403
    tok = csrf_of(admin.get("/admin/index.php?section=system").text)
    r = admin.get(f"/admin/index.php?section=system&action=export_llm_config&csrf={tok}")
    assert r.status_code == 200 and r.headers["content-type"] == "application/zip"
    data = json.loads(zipfile.ZipFile(io.BytesIO(r.content)).read("config.json"))
    assert data["format"] == "llm_config_backup" and data["version"] == 1
    assert "sk-test" not in r.content.decode("latin-1") and "api_key" not in json.dumps(data)
    r = admin.post("/admin/index.php?section=system&action=import_llm_config", data={"csrf": tok},
                   files={"archive": ("b.zip", r.content, "application/zip")})
    assert "Восстановлено: доменов создано 0, обновлено 1" in r.text and "API-ключ не восстановлен" in r.text


# ---- ConfigBackupService ----
def _seeded():
    c = kit.database()
    d = kit.create_domain(c, "Corp", "corp.example")
    g = kit.create_group(c, d, "Users", "CN=Users,DC=corp,DC=example")
    p = kit.create_provider(c, "Polza")
    c.execute("INSERT INTO llm_group_providers (group_id, provider_id) VALUES (?, ?)", (g, p))
    c.execute("INSERT INTO app_settings (key, value) VALUES ('llm_chat_history_max_chars', '5000')")
    return c


def test_backup_round_trip_to_empty_db():
    src = ConfigBackupService.from_module(LlmModule(_seeded()))
    archive = src.export_zip()
    dst_conn = kit.database()
    stats = ConfigBackupService.from_module(LlmModule(dst_conn)).import_zip(archive, "admin")
    assert (stats["domains_created"], stats["groups_created"], stats["providers_created"], stats["access_links"]) == (1, 1, 1, 1)
    again = ConfigBackupService.from_module(LlmModule(dst_conn)).build_export_data()
    orig = src.build_export_data()
    for k in ("domains", "providers", "access_matrix", "chat_settings"):
        assert again[k] == orig[k]
    assert dst_conn.execute("SELECT api_key_encrypted FROM llm_providers").fetchone()[0] is None


def test_import_failure_rolls_back_everything(monkeypatch):
    archive = ConfigBackupService.from_module(LlmModule(_seeded())).export_zip()
    dst = kit.database()
    svc = ConfigBackupService.from_module(LlmModule(dst))

    def boom(*a, **k):
        raise RuntimeError("сбой")
    monkeypatch.setattr(svc.access, "set_group_providers", boom)   # после доменов, групп и нейросетей
    with pytest.raises(ConfigBackupException, match="изменения не применены"):
        svc.import_zip(archive, "admin")
    for table in ("ldap_configs", "llm_domain_groups", "llm_providers", "llm_models"):
        assert dst.execute(f"SELECT COUNT(*) FROM {table}").fetchone()[0] == 0, table
    assert not dst.in_transaction


@pytest.mark.parametrize("content,msg", [
    (b"not a zip", "корректным ZIP"),
    (None, "нет config.json"),
    ("{bad", "повреждён"),
    (json.dumps({"format": "other"}), "Это не архив"),
])
def test_import_rejects_bad_archives(content, msg):
    if content is None or isinstance(content, str):
        buf = io.BytesIO()
        with zipfile.ZipFile(buf, "w") as z:
            z.writestr("other.txt" if content is None else "config.json", content or "x")
        content = buf.getvalue()
    with pytest.raises(ConfigBackupException, match=msg):
        ConfigBackupService.from_module(LlmModule(kit.database())).import_zip(content, "admin")


def test_import_tolerates_missing_ca_bundle_file_and_bad_records():
    data = {"format": "llm_config_backup", "version": 1, "domains": [
        {"name": "D", "domain": "d.example", "ldaps_url": "ldaps://dc.d.example", "host": "dc.d.example", "base_dn": "DC=d,DC=example"},
        {"name": "Bad", "domain": "b", "ldaps_url": "ldap://x", "host": "x", "base_dn": "DC=b"},
        "junk",
    ], "providers": [{"name": "P", "provider_type": "nope"}]}
    stats = ConfigBackupService.from_module(LlmModule(kit.database())).apply_import(data, "admin")
    assert stats["domains_created"] == 1 and stats["providers_created"] == 0
    assert any("Bad" in w for w in stats["warnings"]) and any("«P»" in w for w in stats["warnings"])


def test_login_form_defaults_to_local_admin_without_ad(admin):
    """Без подключения AD доменный вход невозможен — форма сразу предлагает локального администратора."""
    from fastapi.testclient import TestClient
    html = TestClient(admin.app).get("/admin/index.php").text
    assert '<option value="local" selected>' in html


def test_updates_section_check_update_and_progress(admin, monkeypatch, tmp_path):
    """«Обновление»: проверка показывает доступную версию и изменения, «Обновить» кладёт запрос помощнику, окно-терминал
    дочитывает журнал частями с этапом «N из M», после успешного обновления прежняя «доступна версия» сбрасывается."""
    x = tmp_path / "upd"
    (x / "inbox").mkdir(parents=True)
    monkeypatch.setenv("UPDATER_DIR", str(x))
    monkeypatch.setenv("APP_VERSION", "2.0.0")
    now = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())
    (x / "helper.json").write_text(json.dumps({"at": now, "pid": 1}))
    page = admin.get("/admin/index.php?section=updates").text
    assert "Помощник обновлений" in page and "проверка ещё не выполнялась" in page and 'href="index.php?section=updates"' in page
    tok = csrf_of(page)
    r = admin.post("/admin/index.php?section=updates&action=helper_request", data={"csrf": tok, "helper_action": "update"})
    assert "передан помощнику" in r.text and 'data-running="1"' in r.text
    assert (x / "inbox" / "request.txt").read_text().startswith("action=update\n")
    assert admin.get("/admin/index.php?section=updates&action=log").json()["state"] == "pending"
    (x / "inbox" / "request.txt").unlink()
    (x / "last-output.txt").write_text("Канал: релизы (теги vX.Y.Z)\nУстановлено: 2.0.0 · e41e33be597c (v2.0.0)\n"
                                       "Доступно:    2.1.0 · 85ee5bd00000 (v2.1.0)\nНовые коммиты (2):\n  85ee5bd Аудит и DLP\n  70014b2 Проверка утечек\n")
    (x / "status.json").write_text(json.dumps({"state": "done", "action": "check", "at": "2026-11-15T19:00:00Z", "message": ""}))
    page = admin.get("/admin/index.php?section=updates").text
    assert "Обновить проект до 2.1.0" in page and "версия 2.0.0 → 2.1.0" in page and "Что нового: 2 изменения" in page and "Аудит и DLP" in page
    (x / "status.json").write_text(json.dumps({"state": "running", "action": "update", "at": "2026-11-15T19:01:00Z", "message": ""}))
    (x / "live-output.txt").write_text("[1/19] Проверка окружения\n[5/19] Сборка образов\n")
    page = admin.get("/admin/index.php?section=updates").text
    assert 'data-running="1"' in page and "Идёт обновление" in page
    assert re.search(r'value="update">\s*<button class="btn btn-primary" type="submit" disabled', page), "во время обновления кнопки неактивны"
    d = admin.get("/admin/index.php?section=updates&action=log&offset=0").json()
    assert d["running"] and (d["step"], d["steps"], d["step_name"]) == (5, 19, "Сборка образов") and "Сборка образов" in d["text"]
    more = admin.get(f"/admin/index.php?section=updates&action=log&offset={d['offset']}").json()
    assert more["text"] == "" and more["offset"] == d["offset"], "дочитывается только новое"
    (x / "status.json").write_text(json.dumps({"state": "done", "action": "update", "at": "2026-11-15T19:05:00Z", "message": ""}))
    (x / "history.jsonl").write_text('{"at":"2026-11-15T19:05:00Z","action":"update","result":"ok","from":"e41e33be597c","to":"85ee5bd00000","detail":""}\n'
                                     '{"at":"2026-11-15T19:05:01Z","action":"update","result":"ok"}\n')
    page = admin.get("/admin/index.php?section=updates").text
    assert "Обновить проект до 2.1.0" not in page and 'data-running="0"' in page
    assert "e41e33be597c → 85ee5bd00000" in page and page.count("badge badge-active\">успешно") == 1, "в истории — записи update.sh, без дублей помощника"
    maint = admin.get("/admin/index.php?section=maintenance").text
    assert 'value="update"' not in maint, "обновление — только в своём разделе"


def test_admin_menu_icons_are_unique_for_every_section():
    """У каждого пункта меню админки своя пиктограмма — без повторов и без заглушки."""
    from pathlib import Path
    from llmp.web.admin.routes import SECTION_LABELS
    src = (Path(__file__).resolve().parents[1] / "llmp/web/templates/admin/layout.j2").read_text()
    icons = dict(re.findall(r"^\s*'(\w+)': '(.+?)',?$", src, re.M))
    assert set(SECTION_LABELS) <= set(icons), "каждому разделу — своя пиктограмма"
    assert len(set(icons.values())) == len(icons), "пиктограммы не повторяются"
