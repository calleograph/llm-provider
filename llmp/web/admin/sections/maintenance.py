"""Раздел «Обслуживание и состояние» (перенос public/admin/sections/maintenance.php). Действия с контейнерами
выполняет помощник обслуживания на хосте: страница лишь кладёт запрос «ключ=значение» из белого списка в
<UPDATER_DIR>/inbox/request.txt и читает его файлы состояния. Доступа к Docker и root у приложения нет."""

from __future__ import annotations

import calendar
import json
import os
import re
import secrets
import shutil
import sys
import time
from pathlib import Path

import httpx

from .... import paths
from ..base import AdminPage

ACTIONS = {"check": "Проверить обновления", "update": "Обновить", "backup": "Создать резервную копию",
           "verify": "Проверить службы", "diag": "Собрать диагностику"}
DB_CHECK_KEY = "maintenance_db_quick_check"
DB_CHECK_TTL = 600   # PRAGMA quick_check на большой БД читает весь файл — не чаще раза в 10 минут


def exchange_dir() -> Path:
    return Path(os.environ.get("UPDATER_DIR") or "/data/updater")


def _read_json(f: Path):
    try:
        data = json.loads(f.read_text(encoding="utf-8"))
    except (OSError, ValueError):
        return None
    return data or None


def _ready(url_env: str) -> tuple[int, dict]:
    """GET <URL>/readyz: только http:// (внутренняя сеть контейнеров), короткие таймауты."""
    url = os.environ.get(url_env, "").rstrip("/") + "/readyz"
    if not url.startswith("http://"):
        return 0, {}
    try:
        r = httpx.get(url, timeout=httpx.Timeout(4.0, connect=2.0), follow_redirects=False)
    except httpx.HTTPError:
        return 0, {}
    try:
        data = r.json()
    except ValueError:
        data = None
    if isinstance(data, dict):
        data = data.get("detail", data)
    return r.status_code, data if isinstance(data, dict) else {}


def _db_ok(page: AdminPage) -> bool:
    settings = page.module.app_settings
    try:
        cached = json.loads(settings.get(DB_CHECK_KEY) or "")
        if time.time() - int(cached["at"]) < DB_CHECK_TTL:
            return bool(cached["ok"])
    except (ValueError, KeyError, TypeError):
        pass
    ok = page.module.conn.execute("PRAGMA quick_check").fetchone()[0] == "ok"
    settings.set(DB_CHECK_KEY, json.dumps({"ok": ok, "at": int(time.time())}))
    return ok


def _iso_ts(value) -> int:
    try:
        return calendar.timegm(time.strptime(str(value)[:19], "%Y-%m-%dT%H:%M:%S"))
    except ValueError:
        return 0


def _num(v: float) -> str:
    """number_format(v, 1, ',', ' ')."""
    return f"{v:,.1f}".replace(",", " ").replace(".", ",")


def _helper_request(page: AdminPage, inbox: Path) -> None:
    want = page.post("helper_action")
    if not page.csrf_ok():
        page.flash_error = "Форма устарела, обновите страницу."
    elif want not in ACTIONS:
        page.flash_error = "Недопустимое действие."
    elif not inbox.is_dir() or not os.access(inbox, os.W_OK):
        page.flash_error = "Каталог обмена с помощником недоступен."
    elif (inbox / "request.txt").is_file():
        page.flash_error = "Предыдущий запрос ещё не забран помощником."
    else:
        # только белый список «ключ=значение»; помощник на хосте проверит формат ещё раз
        tmp = inbox / f".request.{secrets.token_hex(6)}"
        tmp.write_text(f"action={want}\nid={secrets.token_hex(8)}\n", encoding="utf-8")
        os.replace(tmp, inbox / "request.txt")
        page.module.events.admin(page.admin, page.ip, "maintenance_request", True, {"action": want})
        page.flash_success = f"Запрос «{ACTIONS[want]}» передан помощнику обслуживания."


def handle(page: AdminPage) -> str:
    m = page.module
    exchange = exchange_dir()
    if page.is_post("helper_request"):
        _helper_request(page, exchange / "inbox")

    anon_code, anon = _ready("ANONYMIZER_URL")
    asr_on = os.environ.get("ASR_ENABLED") == "1"
    asr_code, asr = _ready("ASR_URL") if asr_on else (0, {})
    db_ok = _db_ok(page)
    try:
        db_size = paths.database().stat().st_size
    except OSError:
        db_size = 0
    try:
        usage = shutil.disk_usage(paths.data())
        free, total = usage.free, usage.total or 1
    except OSError:
        free, total = 0, 1
    host = _read_json(exchange / "host-status.json")
    helper = _read_json(exchange / "helper.json")
    helper_alive = isinstance(helper, dict) and time.time() - _iso_ts(helper.get("at", "")) < 120
    status = _read_json(exchange / "status.json")
    history = []
    try:
        for line in reversed((exchange / "history.jsonl").read_text(encoding="utf-8").split("\n")):
            if line:
                try:
                    history.append(json.loads(line))
                except ValueError:
                    pass
    except OSError:
        pass
    last_update = next((h for h in history if isinstance(h, dict) and h.get("action") == "update" and h.get("result") == "ok"), None)
    models = m.models.all()
    health = m.model_health.stats()
    cooling = sum(1 for x in models if int((health.get(int(x["id"])) or {}).get("cooldown_until") or 0) > time.time())
    mail_ok = m.mail_settings.is_configured()
    errors = [dict(r) for r in m.conn.execute(
        "SELECT created_at, source, event_code, message FROM diagnostic_log WHERE level = 'error' ORDER BY id DESC LIMIT 15")]
    gb = lambda b: _num(b / 1073741824) + " ГБ"  # noqa: E731
    asr_dict = asr.get("dictation") if isinstance(asr.get("dictation"), dict) else {}

    tiles = [
        ("Приложение (Python)", os.environ.get("APP_VERSION") or "—", "status-ok",
         f"Python {sys.version.split()[0]} · {(os.environ.get('APP_GIT_COMMIT') or '')[:12]}"),
        ("Обезличивание", "Готово" if anon_code == 200 else "Недоступно — чат заблокирован", "status-ok" if anon_code == 200 else "status-bad",
         f"модели за {int(anon.get('load_ms') or 0)} мс · запросов {int(anon.get('requests') or 0)} · отказов {int(anon.get('failures') or 0)}"
         if anon_code == 200 else f"HTTP {anon_code}"),
        ("Распознавание речи", "Выключено" if not asr_on else ("Готово" if asr_code == 200 else "Недоступно"),
         "status-warn" if not asr_on else ("status-ok" if asr_code == 200 else "status-bad"),
         f"{asr.get('active_model') or ''} · очередь {int(asr.get('queue_depth') or 0)} · диктовок {int(asr_dict.get('active') or 0)}"
         if asr_on else "голосовой ввод не установлен"),
        ("Redis", "Не используется" if not asr_on else ("Доступен" if asr.get("redis_ok") else "Нет связи"),
         "status-warn" if not asr_on else ("status-ok" if asr.get("redis_ok") else "status-bad"), ""),
        ("База данных", "В порядке" if db_ok else "Ошибка целостности", "status-ok" if db_ok else "status-bad", f"SQLite · {_num(db_size / 1048576)} МБ"),
        ("Модели", f"{len(models)} настроено", "status-warn" if cooling > 0 else "status-ok", f"{cooling} на паузе после сбоев" if cooling > 0 else "все в работе"),
        ("Почта", "Настроена" if mail_ok else "Не настроена", "status-ok" if mail_ok else "status-warn", ""),
        ("Диск данных", "Свободно " + gb(free), "status-bad" if free / total < 0.1 else "status-ok", "из " + gb(total)),
        ("Последнее обновление", str(last_update["at"])[:16].replace("T", " ") + " UTC" if last_update else "не выполнялось", "",
         f"{last_update.get('from') or ''} → {last_update.get('to') or ''}" if last_update else ""),
    ]

    services = []
    if isinstance(host, dict):
        stats = {str(s.get("name")): s for s in host.get("stats") or [] if isinstance(s, dict)}
        for s in host.get("services") or []:
            st = None
            for n, v in stats.items():
                if f"-{s.get('service')}-" in n:
                    st = v
            services.append({
                "service": str(s.get("service", "")), "status": str(s.get("status", "")),
                "ok": s.get("state") == "running" and s.get("health", "") in ("healthy", ""),
                "cpu": str((st or {}).get("cpu", "—")), "mem": str((st or {}).get("mem", "—")),
                "image": re.sub(r"@sha256:[0-9a-f]+$", "", str(s.get("image", ""))),
            })
    status_text = None
    if isinstance(status, dict):
        state = status.get("state")
        status_text = {
            "class": {"done": "alert-success", "failed": "alert-error"}.get(state, "alert-warn"),
            "text": ACTIONS.get(status.get("action"), str(status.get("action"))) + ": "
                    + {"running": "выполняется", "done": "готово", "failed": "ошибка"}.get(state, str(state)),
            "at": str(status.get("at", ""))[:19].replace("T", " "), "message": str(status.get("message", "")),
        }
    page.page_title = "Обслуживание и состояние"
    return page.render("admin/sections/maintenance.j2", tiles=tiles, host=host if isinstance(host, dict) else None, services=services,
                       actions=ACTIONS, helper_alive=helper_alive, status=status_text,
                       errors=[e | {"when": str(e["created_at"])[:16].replace("T", " "), "short": str(e["message"])[:200]} for e in errors])
