"""Телеметрия: журнал того, что ушло во внешние нейросети (llm_outbound), независимый от удаления диалогов пользователем.

Пишется сразу после обращения к LLM (успех или ошибка): кто, какая модель, сколько токенов, сколько длилось и
точный текст сообщения в том виде, в каком он ушёл (обезличенный; «без обезличивания» — как ушёл, с флагом).
Текст хранится сжатым (llmp.compact.pack). Сбой записи телеметрии никогда не ломает отправку — только диагностика.
Срок хранения — llm_chat_telemetry_retention_days (0 — бессрочно), зачистку раз в час делает планировщик.

При записи текст попадает в полнотекстовый индекс (llm_outbound_fts, без копии текста) и проверяется правилами DLP
(llmp/chat/dlp.py): совпадения — в llm_outbound_dlp, наибольшая важность — в llm_outbound.dlp_severity. Поэтому поиск и
фильтр по правилу в админке работают по индексам и не распаковывают каждую запись."""

from __future__ import annotations

import sqlite3
import time

from .. import compact
from ..db import now_iso, transaction
from ..logs import DiagnosticLogger
from . import dlp
from .conversations import iso_days_ago
from .core import ChatUser

KINDS = ("chat", "summary", "project_state")
REVIEWS = {"incident": "Инцидент", "ok": "Норма"}
RESCAN_BATCH = 5000
PURGE_BATCH = 2000
_RESCAN_KEY = "dlp_rescan"


def _text(blob) -> str:
    try:
        return compact.unpack(blob) if blob is not None else ""
    except RuntimeError:
        return ""


class Telemetry:
    def __init__(self, conn: sqlite3.Connection):
        self.conn = conn

    # ---- запись ----
    def record(self, user: ChatUser, kind: str, *, provider_id: int | None, provider_name: str | None, model_id: int | None,
               model_name: str | None, outcome: str = "ok", text: str | None = None, conversation_id: int | None = None,
               project_id: int | None = None, fallback_from: str | None = None, unanonymized: bool = False,
               attachment_types: str | None = None, attachment_count: int = 0, input_tokens: int | None = None,
               output_tokens: int | None = None, tokens: int = 0, duration_ms: int = 0) -> int | None:
        """id записи или None, если записать не удалось (причина — в diagnostic_log)."""
        try:
            cur = self.conn.execute(
                "INSERT INTO llm_outbound (created_at, owner_key, username, domain, conversation_id, project_id, provider_id, provider_name, "
                "model_id, model_name, fallback_from, kind, text_z, text_chars, unanonymized, attachment_types, attachment_count, "
                "input_tokens, output_tokens, tokens, duration_ms, outcome) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                (now_iso(), user.owner_key(), user.username, user.domain_fqdn, conversation_id, project_id, provider_id, provider_name,
                 model_id, model_name, fallback_from, kind, compact.pack(text) if text else None, len(text or ""), int(unanonymized),
                 attachment_types, attachment_count, input_tokens, output_tokens, tokens, duration_ms, outcome))
            record_id = int(cur.lastrowid)
        except Exception as e:  # noqa: BLE001 — телеметрия не должна мешать пользователю
            self._diag("TELEMETRY_WRITE_FAILED", e, kind)
            return None
        if text:
            try:
                self._index(record_id, text, dlp.load_rules(self.conn), fts=True)
            except Exception as e:  # noqa: BLE001 — запись уже есть; без индекса её найдёт перепроверка
                self._diag("TELEMETRY_INDEX_FAILED", e, kind)
        return record_id

    def _diag(self, code: str, e: Exception, kind: str) -> None:
        try:
            DiagnosticLogger(self.conn).error("telemetry", code, f"{type(e).__name__}: {e}", {"kind": kind})
        except Exception:  # noqa: BLE001
            pass

    def _index(self, record_id: int, text: str, rules: list, *, fts: bool) -> None:
        """Поисковый индекс (fts, только если записи в нём ещё нет — текст не меняется) и результаты правил DLP одной записи;
        прежние результаты правил заменяются."""
        with transaction(self.conn):
            if fts and self.conn.execute("UPDATE llm_outbound SET fts_indexed = 1 WHERE id = ? AND fts_indexed = 0", (record_id,)).rowcount:
                self.conn.execute("INSERT INTO llm_outbound_fts (rowid, text) VALUES (?, ?)", (record_id, dlp.fold(text)))
            self.conn.execute("DELETE FROM llm_outbound_dlp WHERE outbound_id = ?", (record_id,))
            found = dlp.scan(rules, text)
            self.conn.executemany("INSERT INTO llm_outbound_dlp (outbound_id, rule_id, hits) VALUES (?, ?, ?)",
                                  [(record_id, rule.id, n) for rule, n in found])
            self.conn.execute("UPDATE llm_outbound SET dlp_severity = ? WHERE id = ?", (max((r.severity for r, _ in found), default=0), record_id))

    def _reindex(self, first_id: int, last_id: int, *, fts: bool, limit: int | None = None) -> int | None:
        """Записи с first_id по last_id (не больше limit); возвращает id, с которого продолжать, или None — диапазон пройден."""
        rules = dlp.load_rules(self.conn)
        rows = self.conn.execute("SELECT id, text_z FROM llm_outbound WHERE id BETWEEN ? AND ? AND text_z IS NOT NULL ORDER BY id LIMIT ?",
                                 (first_id, last_id, limit or -1)).fetchall()
        for r in rows:
            self._index(r["id"], _text(r["text_z"]), rules, fts=fts)
        return rows[-1]["id"] + 1 if limit and len(rows) == limit else None

    def reindex_all(self) -> None:
        """Однократно при обновлении: записи, сделанные до появления поиска и DLP."""
        self._reindex(0, 2 ** 62, fts=True)

    # ---- перепроверка правилами (после их правки) — порциями в планировщике ----
    def rescan_start(self, days: int) -> int:
        """Поставить перепроверку записей за days дней (0 — все); возвращает число записей к проверке."""
        since = iso_days_ago(days) if days > 0 else ""
        lo, hi, n = self.conn.execute("SELECT MIN(id), MAX(id), COUNT(*) FROM llm_outbound WHERE created_at >= ? AND text_z IS NOT NULL",
                                      (since,)).fetchone()
        if not n:
            self.conn.execute("DELETE FROM app_settings WHERE key = ?", (_RESCAN_KEY,))
            return 0
        prev = self.conn.execute("SELECT value FROM app_settings WHERE key = ?", (_RESCAN_KEY,)).fetchone()
        if prev is not None:   # уже идёт перепроверка — объединить диапазоны, чтобы не потерять её остаток
            plo, phi = (int(x) for x in prev[0].split(":"))
            lo, hi = min(lo, plo), max(hi, phi)
        self.conn.execute("INSERT INTO app_settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value",
                          (_RESCAN_KEY, f"{lo}:{hi}"))
        return self.rescan_remaining()

    def rescan_step(self, batch: int = RESCAN_BATCH, budget_s: float = 20.0) -> None:
        """Порции по batch записей, каждая — одной транзакцией (запись чата ждёт не дольше порции), пока не выйдет budget_s."""
        started = time.monotonic()
        while time.monotonic() - started < budget_s:
            row = self.conn.execute("SELECT value FROM app_settings WHERE key = ?", (_RESCAN_KEY,)).fetchone()
            if row is None:
                return
            lo, hi = (int(x) for x in row[0].split(":"))
            with transaction(self.conn):
                nxt = self._reindex(lo, hi, fts=False, limit=batch)
                if nxt is None:
                    self.conn.execute("DELETE FROM app_settings WHERE key = ?", (_RESCAN_KEY,))
                    return
                self.conn.execute("UPDATE app_settings SET value = ? WHERE key = ?", (f"{nxt}:{hi}", _RESCAN_KEY))

    def rule_changed(self, rule_id: int, enabled: bool, rescan_days: int = 7) -> int:
        """После правки, выключения или удаления правила: его прежние срабатывания снимаются сразу, важность записей
        пересчитывается; включённое правило заново проверяет записи за rescan_days дней (в планировщике)."""
        affected = "SELECT outbound_id FROM llm_outbound_dlp WHERE rule_id = ?"
        with transaction(self.conn):
            ids = [r[0] for r in self.conn.execute(affected, (rule_id,))]
            self.conn.execute("DELETE FROM llm_outbound_dlp WHERE rule_id = ?", (rule_id,))
            for i in range(0, len(ids), 500):
                chunk = ids[i:i + 500]
                self.conn.execute(
                    "UPDATE llm_outbound SET dlp_severity = COALESCE((SELECT MAX(r.severity) FROM llm_outbound_dlp d JOIN dlp_rules r "
                    f"ON r.id = d.rule_id WHERE d.outbound_id = llm_outbound.id), 0) WHERE id IN ({','.join('?' * len(chunk))})", chunk)
        return self.rescan_start(rescan_days) if enabled else 0

    def rule_hits(self, days: int = 30) -> dict[int, int]:
        return {r[0]: r[1] for r in self.conn.execute(
            "SELECT d.rule_id, COUNT(*) FROM llm_outbound_dlp d JOIN llm_outbound o ON o.id = d.outbound_id WHERE o.created_at >= ? GROUP BY d.rule_id",
            (iso_days_ago(days),))}

    def rescan_remaining(self) -> int:
        row = self.conn.execute("SELECT value FROM app_settings WHERE key = ?", (_RESCAN_KEY,)).fetchone()
        if row is None:
            return 0
        lo, hi = (int(x) for x in row[0].split(":"))
        return int(self.conn.execute("SELECT COUNT(*) FROM llm_outbound WHERE id BETWEEN ? AND ? AND text_z IS NOT NULL", (lo, hi)).fetchone()[0])

    # ---- разбор администратором ----
    def review(self, ids: list[int], status: str, admin: str) -> int:
        if status not in (*REVIEWS, "") or not ids:
            return 0
        marks = ",".join("?" * len(ids))
        return self.conn.execute(f"UPDATE llm_outbound SET review = ?, reviewed_by = ?, reviewed_at = ? WHERE id IN ({marks})",
                                 (status, admin if status else None, now_iso() if status else None, *ids)).rowcount

    def attach_conversation(self, record_id: int | None, conversation_id: int) -> None:
        """Новый диалог создаётся после ответа — его id дописывается к уже сделанной записи."""
        if record_id is None:
            return
        try:
            self.conn.execute("UPDATE llm_outbound SET conversation_id = ? WHERE id = ?", (conversation_id, record_id))
        except Exception:  # noqa: BLE001
            pass

    def purge(self, days: int) -> int:
        if days <= 0:
            return 0
        bound, total = iso_days_ago(days), 0
        while True:
            # порциями: сокращение срока на большом журнале не держит блокировку записи чата минутами
            rows = self.conn.execute("SELECT id, fts_indexed, CAST(text_z AS BLOB) FROM llm_outbound WHERE created_at < ? ORDER BY id LIMIT ?",
                                     (bound, PURGE_BATCH)).fetchall()
            if not rows:
                return total
            ids = [r[0] for r in rows]
            marks = ",".join("?" * len(ids))
            with transaction(self.conn):
                # индекс без копии текста: строку из него убирает команда 'delete' с тем же текстом, что был добавлен
                self.conn.executemany("INSERT INTO llm_outbound_fts (llm_outbound_fts, rowid, text) VALUES ('delete', ?, ?)",
                                      [(r[0], dlp.fold(_text(r[2]))) for r in rows if r[1]])
                self.conn.execute(f"DELETE FROM llm_outbound_dlp WHERE outbound_id IN ({marks})", ids)
                total += self.conn.execute(f"DELETE FROM llm_outbound WHERE id IN ({marks})", ids).rowcount

    # ---- чтение (админка) ----
    def _where(self, f: dict) -> tuple[str, list]:
        where, params = ["o.created_at >= ?", "o.created_at < ?"], [f["since"], f["until"]]
        if f.get("owner_key"):
            # по началу имени: «ivanov» найдёт ivanov@corp.local; диапазон, а не LIKE, — работает индекс по owner_key
            where.append("o.owner_key >= ? AND o.owner_key < ?")
            params += [f["owner_key"], f["owner_key"] + "\U0010ffff"]
        for col in ("model_name", "kind"):
            if f.get(col):
                where.append(f"o.{col} = ?")
                params.append(f[col])
        if f.get("unanonymized"):
            where.append("o.unanonymized = 1")
        if f.get("severity"):
            where.append("o.dlp_severity >= ?")
            params.append(int(f["severity"]))
        if f.get("rule_id"):
            where.append("o.id IN (SELECT outbound_id FROM llm_outbound_dlp WHERE rule_id = ?)")
            params.append(int(f["rule_id"]))
        review = f.get("review")
        if review == "todo":
            where.append("o.dlp_severity > 0 AND o.review = ''")
        elif review in REVIEWS:
            where.append("o.review = ?")
            params.append(review)
        if f.get("q"):
            match = dlp.fts_query(f["q"])
            if match is None:
                where.append("0")
            else:
                where.append("o.id IN (SELECT rowid FROM llm_outbound_fts WHERE llm_outbound_fts MATCH ?)")
                params.append(match)
        return " AND ".join(where), params

    def query(self, f: dict, limit: int = 50, offset: int = 0) -> dict:
        """Страница ленты: строки с текстом и срабатываниями правил (id, название, важность, число совпадений)."""
        w, p = self._where(f)
        total = self.conn.execute(f"SELECT COUNT(*) FROM llm_outbound o WHERE {w}", p).fetchone()[0]
        rows = [dict(r) for r in self.conn.execute(
            f"SELECT o.*, CAST(o.text_z AS BLOB) AS text_blob FROM llm_outbound o WHERE {w} ORDER BY o.id DESC LIMIT ? OFFSET ?",
            [*p, limit, offset])]
        hits = self._hits([r["id"] for r in rows])
        for r in rows:
            r.pop("text_z")
            r["text"] = _text(r.pop("text_blob"))
            r["dlp"] = hits.get(r["id"], [])
        return {"rows": rows, "total": int(total)}

    def counts(self, f: dict) -> dict:
        """Счётчики быстрых фильтров ленты при текущих поиске, пользователе, модели и типе (без важности, разбора, правила)."""
        base = {**f, "severity": 0, "review": "", "unanonymized": False, "rule_id": 0}
        w, p = self._where(base)
        r = self.conn.execute(
            f"SELECT COUNT(*) AS requests, COALESCE(SUM(o.dlp_severity > 0), 0) AS flagged, COALESCE(SUM(o.dlp_severity = 3), 0) AS critical, "
            f"COALESCE(SUM(o.dlp_severity > 0 AND o.review = ''), 0) AS todo, COALESCE(SUM(o.review = 'incident'), 0) AS incidents, "
            f"COALESCE(SUM(o.unanonymized), 0) AS unanonymized FROM llm_outbound o WHERE {w}", p).fetchone()
        return dict(r)

    def _hits(self, ids: list[int]) -> dict[int, list[dict]]:
        out: dict[int, list[dict]] = {}
        if not ids:
            return out
        for r in self.conn.execute(
                f"SELECT d.outbound_id, d.rule_id, d.hits, r.name, r.severity FROM llm_outbound_dlp d JOIN dlp_rules r ON r.id = d.rule_id "
                f"WHERE d.outbound_id IN ({','.join('?' * len(ids))}) ORDER BY r.severity DESC, r.position", ids):
            out.setdefault(r["outbound_id"], []).append(dict(r))
        return out

    def iter_export(self, f: dict, limit: int = 50000):
        w, p = self._where(f)
        rules = {r[0]: r[1] for r in self.conn.execute("SELECT id, name FROM dlp_rules")}
        batch: list[dict] = []
        def flush():
            hits = self._hits([b["id"] for b in batch])
            for b in batch:
                b["dlp_rules"] = "; ".join(rules.get(h["rule_id"], str(h["rule_id"])) for h in hits.get(b["id"], []))
                yield b
        for r in self.conn.execute(f"SELECT o.*, CAST(o.text_z AS BLOB) AS text_blob FROM llm_outbound o WHERE {w} ORDER BY o.id DESC LIMIT ?", [*p, limit]):
            row = dict(r)
            row.pop("text_z")
            row["text"] = _text(row.pop("text_blob"))
            batch.append(row)
            if len(batch) == 500:
                yield from flush()
                batch = []
        yield from flush()

    def get(self, record_id: int) -> dict | None:
        r = self.conn.execute("SELECT *, CAST(text_z AS BLOB) AS text_blob FROM llm_outbound WHERE id = ?", (record_id,)).fetchone()
        if r is None:
            return None
        row = dict(r)
        row.pop("text_z")
        row["text"] = _text(row.pop("text_blob"))
        row["dlp"] = self._hits([record_id]).get(record_id, [])
        return row

    def overview(self, since: str, until: str) -> dict:
        p = (since, until)
        w = "created_at >= ? AND created_at < ?"
        t = self.conn.execute(
            f"SELECT COUNT(*) AS requests, COALESCE(SUM(tokens), 0) AS tokens, COUNT(DISTINCT owner_key) AS users, "
            f"COALESCE(SUM(unanonymized), 0) AS unanonymized, COALESCE(SUM(outcome <> 'ok'), 0) AS errors FROM llm_outbound WHERE {w}", p).fetchone()
        top = lambda col, order: [dict(r) for r in self.conn.execute(  # noqa: E731
            f"SELECT {col} AS name, COUNT(*) AS requests, COALESCE(SUM(tokens), 0) AS tokens FROM llm_outbound WHERE {w} "
            f"GROUP BY {col} ORDER BY {order} DESC LIMIT 10", p)]
        d = self.conn.execute(
            f"SELECT COALESCE(SUM(dlp_severity > 0), 0) AS flagged, COALESCE(SUM(dlp_severity = 3), 0) AS critical, "
            f"COALESCE(SUM(dlp_severity > 0 AND review = ''), 0) AS todo, COALESCE(SUM(review = 'incident'), 0) AS incidents "
            f"FROM llm_outbound WHERE {w}", p).fetchone()
        by_rule = [dict(r) for r in self.conn.execute(
            f"SELECT r.id, r.name, r.severity, COUNT(*) AS records, COUNT(DISTINCT o.owner_key) AS users "
            f"FROM llm_outbound_dlp d JOIN llm_outbound o ON o.id = d.outbound_id JOIN dlp_rules r ON r.id = d.rule_id "
            f"WHERE o.created_at >= ? AND o.created_at < ? GROUP BY r.id ORDER BY r.severity DESC, records DESC", p)]
        top_dlp = [dict(r) for r in self.conn.execute(
            f"SELECT owner_key AS name, COUNT(*) AS records, COALESCE(SUM(dlp_severity = 3), 0) AS critical, "
            f"COALESCE(SUM(dlp_severity > 0 AND review = ''), 0) AS todo FROM llm_outbound "
            f"WHERE {w} AND dlp_severity > 0 GROUP BY owner_key ORDER BY critical DESC, records DESC LIMIT 10", p)]
        return {"totals": {**dict(t), **dict(d)}, "top_tokens": top("owner_key", "tokens"), "top_requests": top("owner_key", "requests"),
                "top_models": top("model_name", "requests"), "by_rule": by_rule, "top_dlp": top_dlp}

    def model_names(self) -> list[str]:
        return [r[0] for r in self.conn.execute("SELECT DISTINCT model_name FROM llm_outbound WHERE model_name IS NOT NULL ORDER BY 1")]

    def user_conversations(self, owner_key: str, limit: int = 300) -> list[dict]:
        rows = self.conn.execute(
            "SELECT c.id, c.title, c.created_at, c.updated_at, p.name AS project, "
            "(SELECT COUNT(*) FROM llm_messages m WHERE m.conversation_id = c.id) AS messages, "
            "(SELECT COALESCE(SUM(m.tokens), 0) FROM llm_messages m WHERE m.conversation_id = c.id) AS tokens "
            "FROM llm_conversations c LEFT JOIN llm_projects p ON p.id = c.project_id WHERE c.owner_key = ? "
            "ORDER BY c.updated_at DESC, c.id DESC LIMIT ?", (owner_key, limit)).fetchall()
        return [dict(r) for r in rows]

    def conversation(self, conversation_id: int) -> dict | None:
        r = self.conn.execute(
            "SELECT c.id, c.owner_key, c.title, c.created_at, c.updated_at, p.name AS project FROM llm_conversations c "
            "LEFT JOIN llm_projects p ON p.id = c.project_id WHERE c.id = ?", (conversation_id,)).fetchone()
        return dict(r) if r is not None else None


def elapsed_ms(started: float) -> int:
    return int((time.monotonic() - started) * 1000)
