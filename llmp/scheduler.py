"""Фоновые задачи (`python -m llmp.scheduler`, перенос bin/scheduler.php): раз в минуту — очередь писем; раз в час —
ротация журналов, удаление истёкших веб-сессий и
телеметрии старше срока хранения. Пульс — файл scheduler.heartbeat в каталоге данных (healthcheck
контейнера). Останавливается по SIGTERM/SIGINT между задачами."""

from __future__ import annotations

import signal
import sys
import threading
import time

from . import db, paths
from .chat.settings import ChatSettings, php_int
from .chat.telemetry import Telemetry
from .logs import LogRotation
from .mail import MailQueue, MailSettings
from .security import SessionStore
from .settings import AppSettings

INTERVAL_S = 60
HOURLY_S = 3600


def _task(name: str, fn) -> None:
    # задачи независимы: сбой одной не отменяет остальные и heartbeat
    try:
        fn()
    except Exception as e:  # noqa: BLE001
        print(f"scheduler: {name}: {type(e).__name__}: {e}", file=sys.stderr)


def run_once(last_hourly: float, now: float | None = None) -> float:
    """Одна итерация цикла; возвращает время последнего часового прогона."""
    now = time.time() if now is None else now
    try:
        paths.ensure_directories()
        conn = db.connect()
        try:
            _task("mail", lambda: MailQueue(conn).process(MailSettings(AppSettings(conn))))
            if now - last_hourly >= HOURLY_S:
                last_hourly = now
                _task("log rotation", lambda: LogRotation(conn).rotate_if_needed())
                _task("sessions", lambda: SessionStore(conn).purge_expired())
                _task("telemetry", lambda: Telemetry(conn).purge(php_int(ChatSettings(AppSettings(conn)).get("telemetry_retention_days"))))
            try:
                (paths.data() / "scheduler.heartbeat").write_text(str(int(time.time())))
            except OSError:
                pass
        finally:
            conn.close()
    except Exception as e:  # noqa: BLE001
        print(f"scheduler: {type(e).__name__}: {e}", file=sys.stderr)
    return last_hourly


def main() -> None:
    stop = threading.Event()
    for sig in (signal.SIGTERM, signal.SIGINT):
        signal.signal(sig, lambda *_: stop.set())
    paths.ensure_directories()
    db.connect(migrate_schema=True).close()   # миграции один раз при старте процесса
    last_hourly = 0.0
    while not stop.is_set():
        last_hourly = run_once(last_hourly)
        stop.wait(INTERVAL_S)


if __name__ == "__main__":
    main()
