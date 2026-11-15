"""Раздел «Модели и резервирование» (перенос public/admin/sections/llm_models.php)."""

from __future__ import annotations

import sqlite3
import time
from datetime import datetime, timezone

from ....chat.settings import php_int
from ....llm.models import ModelRepository
from ....llm.providers import PHP_WS, php_empty
from ..base import FORM_STALE, AdminPage
from .llm_providers import php_str

ACTIONS = ("save_model", "delete_model", "check_model", "generate_model", "reset_cooldown", "save_fallback_settings")
INCIDENT_ACTIONS = {"retry": "повтор", "retry_without_params": "повтор без параметров", "give_up": "резервных больше нет", "admin_check": "проверка администратора"}


def num(v, decimals: int = 0) -> str:
    """number_format($v, decimals, ',', ' '): округление от нуля, как в PHP."""
    v = float(v)
    q = int(abs(v) * 10 ** decimals + 0.5) / 10 ** decimals
    return ("-" if v < 0 and q else "") + f"{q:,.{decimals}f}".replace(",", " ").replace(".", ",")


def when(iso: str | None) -> str:
    return iso[:16].replace("T", " ") if iso else "—"


def handle(page: AdminPage) -> str:
    m = page.module
    models, health, events, chat = m.models, m.model_health, m.events, m.chat_settings
    admin, ip = page.admin, page.ip
    field_errors: dict[str, str] = {}
    form_values: dict | None = None
    check_results: dict[int, dict] = {}
    posted_id = php_int(page.post("id", "0"))
    edit_id = php_int(page.query("edit", "0"))

    if page.is_post(*ACTIONS):
        if not page.csrf_ok():
            page.flash_error = FORM_STALE
        elif page.action == "save_model":
            t = lambda k: page.post(k).strip(PHP_WS)  # noqa: E731
            data = {
                "provider_id": php_int(page.post("provider_id", "0")),
                "model": t("model"),
                "display_name": t("display_name"),
                "context_window": php_int(page.post("context_window", "0")),
                "max_output_tokens": php_int(page.post("max_output_tokens", "0")),
                "temperature": t("temperature"),
                "priority": php_int(page.post("priority", "100")),
                "supports_streaming": not php_empty(page.post("supports_streaming")),
                "supports_json": not php_empty(page.post("supports_json")),
                "is_enabled": not php_empty(page.post("is_enabled")),
                "fallback_ids": [x for x in (php_int(v) for v in page.post_list("fallback_ids")) if x > 0 and x != posted_id],
                "price_in": t("price_in"),
                "price_out": t("price_out"),
            }
            field_errors = ModelRepository.validate(data)
            if posted_id == 0 and m.providers.find(data["provider_id"]) is None:
                field_errors["provider_id"] = "Выберите подключение."
            if field_errors:
                page.flash_error = "Проверьте выделенные поля."
                form_values = {**data, "id": posted_id}
                edit_id = posted_id
            else:
                try:
                    if posted_id > 0:
                        models.update(posted_id, data)
                    else:
                        posted_id = models.create(data)
                except sqlite3.IntegrityError:
                    page.flash_error = "У этого подключения уже есть модель с таким идентификатором."
                    form_values = {**data, "id": posted_id}
                else:
                    events.admin(admin, ip, "llm_model_save", True, {"model_id": posted_id, "model": data["model"], "fallbacks": len(data["fallback_ids"])})
                    page.flash_success = "Модель сохранена."
                    edit_id = 0
        elif page.action == "delete_model":
            ok = models.delete(posted_id)
            events.admin(admin, ip, "llm_model_delete", ok, {"model_id": posted_id})
            if ok:
                page.flash_success = "Модель удалена."
            else:
                page.flash_error = "Модель по умолчанию удаляется вместе с подключением (раздел «Подключения LLM API»)."
        elif page.action in ("check_model", "generate_model"):
            r = check_results[posted_id] = m.gateway.test_model(posted_id, "ping" if page.action == "check_model" else "generate")
            events.admin(admin, ip, "llm_model_check", r["ok"], {"model_id": posted_id, "mode": page.action, "duration_ms": r["duration_ms"]})
        elif page.action == "reset_cooldown":
            health.reset_cooldown(posted_id)
            events.admin(admin, ip, "llm_model_reset_cooldown", True, {"model_id": posted_id})
            page.flash_success = "Пауза снята — модель снова первая в своей цепочке."
        else:   # save_fallback_settings
            auto = not php_empty(page.post("fallback_auto"))
            chat.save({"fallback_auto": "1" if auto else "0"})
            events.admin(admin, ip, "llm_fallback_settings", True, {"fallback_auto": 1 if auto else 0})
            page.flash_success = "Настройки резервирования сохранены."

    all_models = models.all()
    by_id = {x["id"]: x for x in all_models}
    stats = health.stats()
    editing = form_values if form_values is not None else (by_id.get(edit_id) if edit_id > 0 else None)
    if editing is not None and "price_in_per_mtoken" in editing:
        editing = {**editing, "price_in": editing["price_in_per_mtoken"], "price_out": editing["price_out_per_mtoken"]}

    now = time.time()
    rows = []
    for x in all_models:
        h = stats.get(x["id"], {})
        cool = int(h.get("cooldown_until") or 0) > now
        ok = int(h.get("success_count") or 0)
        if not x["is_enabled"] or not x["provider_enabled"]:
            state = ("Выключена", "status-warn")
        elif cool:
            state = ("Пауза до " + datetime.fromtimestamp(int(h["cooldown_until"]), timezone.utc).strftime("%H:%M") + " UTC", "status-bad")
        elif (h.get("last_error_at") or "") > (h.get("last_success_at") or ""):
            state = ("Последний запрос с ошибкой", "status-warn")
        else:
            state = ("Доступна", "status-ok") if ok > 0 else ("Не проверялась", "status-warn")
        rows.append({"m": x, "h": h, "cool": cool, "ok": ok, "errs": int(h.get("error_count") or 0), "state": state,
                     "fallbacks": " → ".join(by_id[i]["display_name"] if i in by_id else f"#{i}" for i in x["fallback_ids"])})

    incidents = health.incidents(50)
    for i in incidents:
        i["action_label"] = ("переход → " + (i["fallback_name"] or "следующая")) if i["action"] == "fallback" \
            else INCIDENT_ACTIONS.get(i["action"], str(i["action"]))

    page.page_title = "Модели и резервирование"
    return page.render(
        "admin/sections/llm_models.j2",
        rows=rows, all_models=all_models, editing=editing, field_errors=field_errors, check_results=check_results,
        incidents=incidents, providers=m.providers.all(), fallback_auto=chat.get("fallback_auto"),
        f=lambda k, default="": php_str(editing.get(k) if editing and editing.get(k) is not None else default),
        num=num, when=when,
    )
