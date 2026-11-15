"""Планировщик: одна итерация цикла без ожидания (перенос bin/scheduler.php)."""

from __future__ import annotations

import time

from llmp import db, paths, scheduler
from llmp.settings import AppSettings


def _prepare():
    paths.ensure_directories()
    c = db.connect(migrate_schema=True)
    c.execute("INSERT INTO web_sessions (id, data, updated_at) VALUES ('old', '{}', 0), ('new', '{}', ?)", (int(time.time()),))
    return c


def test_iteration_writes_heartbeat_and_runs_hourly_tasks(var_dir):
    """Пульс записан, часовые задачи (ротация, истёкшие сессии) выполнены и повторяются только через час."""
    c = _prepare()
    last = scheduler.run_once(0, now=10_000)
    assert last == 10_000
    assert int((paths.data() / "scheduler.heartbeat").read_text()) >= int(time.time()) - 5
    assert [r[0] for r in c.execute("SELECT id FROM web_sessions")] == ["new"]
    c.execute("INSERT INTO web_sessions (id, data, updated_at) VALUES ('old2', '{}', 0)")
    assert scheduler.run_once(last, now=10_000 + 60) == 10_000, "раньше часа часовые задачи не запускаются"
    assert c.execute("SELECT COUNT(*) FROM web_sessions WHERE id = 'old2'").fetchone()[0] == 1
    c.close()


def test_failing_task_does_not_stop_others(var_dir, monkeypatch, capsys):
    """Сбой почты не отменяет часовые задачи и пульс; ошибка уходит в stderr."""
    c = _prepare()
    AppSettings(c).set("mail_enabled", "1")

    def boom(*_a, **_k):
        raise RuntimeError("smtp down")

    monkeypatch.setattr(scheduler.MailQueue, "process", boom)
    scheduler.run_once(0, now=10_000)
    assert "scheduler: mail: RuntimeError: smtp down" in capsys.readouterr().err
    assert (paths.data() / "scheduler.heartbeat").is_file()
    assert [r[0] for r in c.execute("SELECT id FROM web_sessions")] == ["new"]
    c.close()
