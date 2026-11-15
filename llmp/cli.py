"""Служебные команды для scripts/*.sh и консоли сервера: `python -m llmp.cli <команда>`.

  admin-reset     создать аварийного администратора admin или задать ему новый пароль; печатает JSON один раз
                  (--text — строкой для человека: «логин …, пароль …»)
  admin-exists    код выхода 0 — администратор уже есть
  verify          целостность БД, готовность обезличивания и ASR (ждёт загрузки моделей до 90 с)
  smoke-anon      проверка обезличивания: ФИО, телефон и пароль должны быть заменены → CLEAN | LEAK
  snapshot PATH   согласованный снимок БД (VACUUM INTO) для резервной копии
  counts PATH T…  число строк в таблицах снимка (JSON) — сверка копии"""

from __future__ import annotations

import json
import os
import secrets
import sqlite3
import sys
import time
import urllib.request

from . import db, passwords, paths

ALPHABET = "ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789"   # без похожих символов


def _conn() -> sqlite3.Connection:
    paths.ensure_directories()
    return db.connect(migrate_schema=True)


def admin_reset(text: bool = False) -> int:
    conn = _conn()
    password = "".join(secrets.choice(ALPHABET) for _ in range(20))
    row = conn.execute("SELECT username FROM local_admin WHERE id = 1").fetchone()
    if row:
        conn.execute("UPDATE local_admin SET password_hash = ?, updated_at = ? WHERE id = 1", (passwords.hash_password(password), db.now_iso()))
        action, username = "reset", row[0]
    else:
        conn.execute("INSERT INTO local_admin (id, username, password_hash, updated_at) VALUES (1, 'admin', ?, ?)", (passwords.hash_password(password), db.now_iso()))
        paths.installed_marker().touch()
        action, username = "created", "admin"
    conn.execute(
        "INSERT INTO audit_log (created_at, actor, ip, action, success, details) VALUES (?, 'console', '127.0.0.1', ?, 1, '{\"source\":\"console\"}')",
        (db.now_iso(), "local_admin_" + action),
    )
    if text:
        print(f"логин {username}, пароль {password} (показан один раз — сохраните; открытые сессии администратора завершены)")
    else:
        print(json.dumps({"action": action, "username": username, "password": password}, ensure_ascii=False))
    return 0


def admin_exists() -> int:
    return 0 if _conn().execute("SELECT 1 FROM local_admin WHERE id = 1").fetchone() else 1


def _get(url: str) -> int:
    try:
        with urllib.request.urlopen(url, timeout=5) as r:
            return r.status
    except urllib.error.HTTPError as e:
        return e.code
    except OSError:
        return 0


def _wait(url: str) -> int:
    code = 0
    for _ in range(30):   # модели загружаются после старта контейнера
        code = _get(url)
        if code == 200:
            return code
        time.sleep(3)
    return code


def verify() -> int:
    ok = _conn().execute("PRAGMA quick_check").fetchone()[0] == "ok"
    print("[ok] БД: целостность в порядке" if ok else "[FAIL] БД: quick_check не пройден")
    a = _wait(os.environ.get("ANONYMIZER_URL", "http://anonymizer:8091").rstrip("/") + "/readyz")
    print("[ok] Обезличивание: модели загружены" if a == 200 else f"[FAIL] Обезличивание: HTTP {a}")
    s = 200
    if os.environ.get("ASR_ENABLED") == "1":
        s = _wait(os.environ.get("ASR_URL", "http://asr:8090").rstrip("/") + "/readyz")
        print("[ok] ASR: модель загружена, Redis доступен" if s == 200 else f"[FAIL] ASR: HTTP {s}")
    return 0 if ok and a == 200 and s == 200 else 1


def smoke_anon() -> int:
    req = urllib.request.Request(
        os.environ.get("ANONYMIZER_URL", "http://anonymizer:8091").rstrip("/") + "/v1/anonymize",
        data=json.dumps({"texts": ["Иванов Иван Иванович, тел. +7 912 345-67-89, пароль: Qwerty!2026xyz"], "profile": "basic"}).encode(),
        headers={"Content-Type": "application/json", "X-Internal-Token": os.environ.get("INTERNAL_API_TOKEN", "")},
    )
    try:
        with urllib.request.urlopen(req, timeout=30) as r:
            text = json.loads(r.read()).get("texts", [""])[0]
    except (OSError, ValueError):
        print("ERROR")
        return 1
    leak = any(x in text for x in ("Иванов", "345-67-89", "Qwerty"))
    print("LEAK" if leak else "CLEAN")
    return 1 if leak else 0


def snapshot(target: str) -> int:
    if os.path.exists(target):
        os.unlink(target)
    db.connect().execute("VACUUM INTO ?", (target,))
    return 0


def counts(path: str, tables: list[str]) -> int:
    c = sqlite3.connect(f"file:{path}?mode=ro", uri=True)
    out = {}
    for t in tables:
        try:
            out[t] = c.execute(f'SELECT COUNT(*) FROM "{t}"').fetchone()[0]
        except sqlite3.Error:
            pass
    print(json.dumps(out))
    return 0


def main(argv: list[str]) -> int:
    cmd, args = (argv[0], argv[1:]) if argv else ("", [])
    commands = {
        "admin-reset": lambda: admin_reset("--text" in args),
        "admin-exists": lambda: admin_exists(),
        "verify": lambda: verify(),
        "smoke-anon": lambda: smoke_anon(),
        "snapshot": lambda: snapshot(args[0]),
        "counts": lambda: counts(args[0], args[1:]),
    }
    if cmd not in commands:
        print(__doc__, file=sys.stderr)
        return 2
    return commands[cmd]()


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
