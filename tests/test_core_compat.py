"""Совместимость с данными PHP-версии: значения в data/php_formats.json созданы PHP-кодом (SecretBox, CompactText,
password_hash) — новый код обязан их читать, иначе после обновления пропадут ключи провайдеров, история и пароль."""

from __future__ import annotations

import base64
import json
from pathlib import Path

import pytest

from llmp import compact, db, passwords, secretbox

PHP = json.loads((Path(__file__).parent / "data" / "php_formats.json").read_text())


def test_secret_encrypted_by_php_decrypts():
    assert secretbox.decrypt(PHP["secret"]) == "sk-проверка-ключа-123"


def test_secret_roundtrip_and_tamper_is_an_error():
    enc = secretbox.encrypt("значение")
    assert secretbox.decrypt(enc) == "значение"
    raw = bytearray(base64.b64decode(enc))
    raw[-1] ^= 1
    with pytest.raises(secretbox.SecretError):
        secretbox.decrypt(base64.b64encode(bytes(raw)).decode())


def test_lost_master_key_after_install_is_an_explicit_error(var_dir, monkeypatch):
    monkeypatch.delenv("APP_MASTER_KEY")
    secretbox.reset_cache()
    (var_dir / "data").mkdir(parents=True)
    (var_dir / "data" / ".installed").touch()
    with pytest.raises(secretbox.SecretError, match="восстановите"):
        secretbox.key()


def test_compact_formats_written_by_php():
    assert compact.unpack(base64.b64decode(PHP["packed"])) == "Сводка: срок — пятница"
    assert compact.unpack(base64.b64decode(PHP["packed_text"])) == "обычный"
    text = compact.decode("", base64.b64decode(PHP["encoded_z"]))
    assert len(text.encode()) == PHP["encoded_len"] and text.startswith("Текст сообщения")
    assert compact.unpack(compact.pack("абв")) == "абв"
    short, z = compact.encode("коротко")
    assert (short, z) == ("коротко", None)


@pytest.mark.parametrize("key", ["argon", "bcrypt"])
def test_password_hashes_made_by_php_verify(key):
    assert passwords.verify_password("first-password-123", PHP[key])
    assert not passwords.verify_password("wrong", PHP[key])


def test_new_hash_is_argon2id():
    h = passwords.hash_password("секрет-пароль-1")
    assert h.startswith("$argon2id$") and passwords.verify_password("секрет-пароль-1", h)


def test_migrate_on_ready_database_takes_no_write_lock(tmp_path):
    """Миграции при старте процесса рядом с работающим экземпляром не ждут его запись."""
    path = tmp_path / "app.sqlite"
    db.connect(path, migrate_schema=True).execute(
        "INSERT INTO llm_providers (name, provider_type, base_url, endpoint, model, created_at, updated_at) VALUES ('p','openai','https://x','/c','m','t','t')"
    )
    db.seed_default_models(db.connect(path))
    writer = db.connect(path)
    writer.execute("BEGIN IMMEDIATE")
    reader = db.connect(path)
    reader.execute("PRAGMA busy_timeout = 500")
    try:
        db.migrate(reader)
    finally:
        writer.execute("ROLLBACK")


def test_schema_matches_php_and_old_database_gets_new_columns(tmp_path):
    path = tmp_path / "old.sqlite"
    old = db.connect(path)
    old.execute("CREATE TABLE llm_users (owner_key TEXT PRIMARY KEY, username TEXT NOT NULL, domain_fqdn TEXT NOT NULL, first_seen_at TEXT NOT NULL, last_seen_at TEXT NOT NULL, limit_period TEXT, limit_tokens INTEGER, is_blocked INTEGER NOT NULL DEFAULT 0)")
    db.migrate(old)
    cols = {r[1] for r in old.execute("PRAGMA table_info(llm_users)")}
    assert {"allow_unanonymized", "limit_category_id", "style", "instructions_z"} <= cols
    assert old.execute("SELECT name, period, tokens FROM llm_limit_categories").fetchone()[:] == ("Базовый", "day", 300000)
    db.migrate(old)   # повторно — без ошибок и дублей
    assert old.execute("SELECT COUNT(*) FROM llm_limit_categories").fetchone()[0] == 1


def test_admin_reset_text_output_and_ends_sessions(var_dir, capsys):
    """Строка для человека из консоли: логин и пароль, которым действительно можно войти."""
    from llmp import cli, paths
    assert cli.main(["admin-reset", "--text"]) == 0
    line = capsys.readouterr().out.strip()
    assert line.startswith("логин admin, пароль ")
    password = line.split("пароль ")[1].split(" ")[0]
    stored = db.connect(paths.database()).execute("SELECT password_hash FROM local_admin").fetchone()[0]
    assert passwords.verify_password(password, stored)
