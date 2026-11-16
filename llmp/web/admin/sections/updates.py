"""Раздел «Обновление»: установленная версия и что опубликовано на GitHub, обновление одной кнопкой,
окно хода обновления в виде терминала с этапами, итог и история попыток.

Само приложение ничего не обновляет: запрос «check» или «update» уходит помощнику обслуживания на хосте (scripts/updater.sh,
тот же обмен, что у «Обслуживания»), который запускает штатные scripts/check-updates.sh и scripts/update.sh. Ход работы
помощник пишет построчно в live-output.txt; окно-терминал дочитывает его частями (action=log, JSON) и переживает перезапуск
приложения во время обновления. Итог последней проверки запоминается (app_settings), чтобы не пропадал после других действий."""

from __future__ import annotations

import json
import os
import re
import time

from fastapi.responses import JSONResponse

from ..base import AdminPage
from .maintenance import _helper_request, _iso_ts, _read_json, exchange_dir

CHECK_KEY = "update_last_check"
LOG_CHUNK = 256 * 1024
STUCK_AFTER = 30   # секунд: помощник забирает запрос за ~3 с, дольше — он не может его забрать (права, сбой)
_STEP = re.compile(r"^\[(\d+)/(\d+)\] (.+)$", re.M)


def parse_check(text: str) -> dict:
    """Вывод scripts/check-updates.sh → канал, установлено, доступно (версия · коммит · имя цели), новые коммиты."""
    def field(name: str) -> str:
        m = re.search(rf"^{name}:\s*(.+)$", text, re.M)
        return m.group(1).strip() if m else ""
    available = field("Доступно")
    commits = []
    m = re.search(r"^Новые коммиты \((\d+)\):\n((?:  .*\n?)+)", text, re.M)
    if m:
        commits = [ln.strip() for ln in m.group(2).splitlines() if ln.strip() and not ln.strip().startswith("…")]
    version = re.match(r"([\w.\-]+)", available).group(1) if available else ""
    return {"channel": field("Канал"), "installed": field("Установлено"), "available": available, "version": version,
            "commits": commits[:30], "behind": int(m.group(1)) if m else (1 if available else 0),
            "local_changes": "локальные правки" in text, "diverged": "История на сервере разошлась" in text,
            "env_keys": field("Новые параметры .env.*?").split(":", 1)[-1].strip() if "Новые параметры .env" in text else ""}


def _ago(ts: int) -> str:
    s = max(0, int(time.time()) - ts)
    if s < 90:
        return f"{s} с назад"
    if s < 5400:
        return f"{round(s / 60)} мин назад"
    if s < 172800:
        return f"{round(s / 3600)} ч назад"
    return f"{round(s / 86400)} дн. назад"


def _log_file(x):
    live = x / "live-output.txt"
    return live if live.is_file() else x / "last-output.txt"


def _state(x) -> dict:
    """Состояние для окна-терминала: действие, этап, итог (из status.json и вывода)."""
    status = _read_json(x / "status.json") or {}
    req = x / "inbox" / "request.txt"
    pending = req.is_file()
    try:
        stuck = pending and time.time() - req.stat().st_mtime > STUCK_AFTER
    except OSError:
        stuck = False
    try:
        text = _log_file(x).read_text(encoding="utf-8", errors="replace")
    except OSError:
        text = ""
    steps = _STEP.findall(text) if status.get("action") == "update" else []
    n, total, name = (int(steps[-1][0]), int(steps[-1][1]), steps[-1][2]) if steps else (0, 0, "")
    return {"state": "pending" if pending else status.get("state", ""), "action": status.get("action", ""),
            "at": str(status.get("at", ""))[:19].replace("T", " "), "message": str(status.get("message", "")),
            "step": n, "steps": total, "step_name": name, "running": pending or status.get("state") == "running", "stuck": stuck}


def _log_json(page: AdminPage) -> JSONResponse:
    """Журнал частями: offset — сколько байт клиент уже получил; reset — файл начался заново (новое действие)."""
    x = exchange_dir()
    f = _log_file(x)
    try:
        offset = max(0, int(page.query("offset", "0")))
    except ValueError:
        offset = 0
    text, reset, size = "", False, 0
    try:
        size = f.stat().st_size
        if offset > size:
            offset, reset = 0, True
        with f.open("rb") as fh:
            fh.seek(offset)
            raw = fh.read(LOG_CHUNK)
        text = raw.decode("utf-8", errors="replace")
        offset += len(raw)
    except OSError:
        pass
    return JSONResponse({**_state(x), "text": text, "offset": offset, "reset": reset}, headers={"Cache-Control": "no-store"})


def handle(page: AdminPage):
    page.page_title = "Обновление"
    m = page.module
    x = exchange_dir()
    if page.action == "log":
        return _log_json(page)
    if page.is_post("helper_request") and page.post("helper_action") in ("check", "update"):
        _helper_request(page, x / "inbox")
    elif page.is_post("helper_cancel") and page.csrf_ok():
        # запрос, который помощник не забрал: приложение владеет inbox и может его снять — иначе кнопки заблокированы навсегда
        (x / "inbox" / "request.txt").unlink(missing_ok=True)
        page.module.events.admin(page.admin, page.ip, "maintenance_request_cancel", True, {})
        page.flash_success = "Запрос отменён."

    helper = _read_json(x / "helper.json")
    beat = _iso_ts(helper.get("at", "")) if isinstance(helper, dict) else 0
    helper_alive = bool(beat) and time.time() - beat < 120
    status = _read_json(x / "status.json") or {}
    st = _state(x)

    # итог проверки — из вывода помощника, пока его не перезаписало другое действие
    try:
        check = json.loads(m.app_settings.get(CHECK_KEY) or "{}")
    except ValueError:
        check = {}
    if check:   # запись прежнего формата — недостающие поля по умолчанию
        check = {**parse_check(""), **check}
    if status.get("action") == "check" and status.get("state") in ("done", "failed") and status.get("at") != check.get("at"):
        f = x / "last-output.txt"
        check = {**parse_check(f.read_text(encoding="utf-8", errors="replace") if f.is_file() else ""),
                 "at": status.get("at"), "ok": status.get("state") == "done"}
        m.app_settings.set(CHECK_KEY, json.dumps(check, ensure_ascii=False))
    if status.get("action") == "update" and status.get("state") == "done" and check.get("at", "") < status.get("at", ""):
        check = {}   # после обновления прежняя «доступна версия X» устарела — до следующей проверки
        m.app_settings.set(CHECK_KEY, "{}")

    history, last_ok = [], None
    try:
        for line in reversed((x / "history.jsonl").read_text(encoding="utf-8").splitlines()):
            try:
                h = json.loads(line)
            except ValueError:
                continue
            # обновления — записи самого update.sh (с версиями «откуда → куда»: и из терминала, и отсюда)
            if isinstance(h, dict) and h.get("action") == "update" and "from" in h:
                history.append(h)
                if last_ok is None and h.get("result") == "ok":
                    last_ok = h
            if len(history) >= 15:
                break
    except OSError:
        pass

    commit = (os.environ.get("APP_GIT_COMMIT") or "")[:12]
    return page.render(
        "admin/sections/updates.j2", helper_alive=helper_alive, beat_ago=_ago(beat) if beat else "", st=st, check=check,
        check_ago=_ago(_iso_ts(check.get("at", ""))) if check.get("at") else "", installed=os.environ.get("APP_VERSION") or "—",
        commit="" if commit == "unknown" else commit, history=history, last_ok=last_ok,
        show_term=st["running"] or status.get("action") == "update")
