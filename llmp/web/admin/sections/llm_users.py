"""Раздел «Пользователи чата» (перенос public/admin/sections/llm_users.php): хранение, устаревший лимит по умолчанию,
лимит/блокировка/очистка истории отдельного пользователя."""

from __future__ import annotations

import hashlib

from ....chat import UsageLimiter
from ....chat.settings import ChatSettings, php_int
from ..base import FORM_STALE, AdminPage

PERIOD_LABELS = {"none": "Без лимита", "day": "В день", "week": "В неделю", "month": "В месяц"}
ACTIONS = ("save_defaults", "save_user_limit", "toggle_block", "clear_user_history")
_DEFAULT_FIELDS = ("retention_days", "project_sources_max_chars", "project_sources_max_files", "project_instructions_max_blocks",
                   "project_instruction_max_chars", "inactivity_content_purge_days")


def number_format(n: float, decimals: int = 0) -> str:
    """number_format($n, $decimals, ',', ' ') PHP."""
    return f"{n:,.{decimals}f}".replace(",", " ").replace(".", ",")


def handle(page: AdminPage) -> str:
    page.page_title = "Пользователи чата"
    m = page.module
    usage, chat_settings, events = m.usage, m.chat_settings, m.events
    admin, ip = page.admin, page.ip

    if page.is_post(*ACTIONS):
        if not page.csrf_ok():
            page.flash_error = FORM_STALE
        elif page.action == "save_defaults":
            values = {"limit_period": page.post("limit_period", "none"), "limit_tokens": str(php_int(page.post("limit_tokens", "0")))}
            values.update({f: str(php_int(page.post(f, "0"))) for f in _DEFAULT_FIELDS})
            errors = ChatSettings.validate(values)
            if errors:
                page.flash_error = "Параметры не сохранены: " + " ".join(errors.values())
            else:
                chat_settings.save(values)
                events.admin(admin, ip, "llm_limit_default_change", True, {
                    "period": values["limit_period"], "limit": values["limit_tokens"],
                    "retention_days": values["retention_days"], "sources_max": values["project_sources_max_chars"],
                })
                page.flash_success = "Общие параметры сохранены (действуют, только пока не заведена ни одна категория лимита)."
        else:
            owner_key = page.post("owner_key")
            if not any(r["owner_key"] == owner_key for r in usage.all_profiles()):
                page.flash_error = "Пользователь не найден."
            elif page.action == "toggle_block":
                block = not usage.is_blocked(owner_key)
                usage.set_blocked(owner_key, block)
                events.admin(admin, ip, "llm_user_block", True, {"user": owner_key, "blocked": block})
                page.flash_success = f"Пользователь {owner_key}" + (" заблокирован." if block else " разблокирован.")
            elif page.action == "clear_user_history":
                deleted = m.conversations.delete_all_for_owner(owner_key)
                events.admin(admin, ip, "llm_user_history_clear", True, {"user": owner_key, "conversations_deleted": deleted})
                page.flash_success = f"История пользователя {owner_key} очищена ({deleted} диалогов)."
            else:
                choice = page.post("category", "inherit")
                if choice == "inherit":
                    usage.set_limit_category(owner_key, None)
                    events.admin(admin, ip, "llm_user_limit_change", True, {"user": owner_key, "category": "как у группы/по умолчанию"})
                elif choice == "manual":
                    period = page.post("period", UsageLimiter.PERIOD_NONE)
                    tokens = max(0, php_int(page.post("tokens", "0")))
                    if period not in UsageLimiter.PERIODS:
                        page.flash_error = "Недопустимый период."
                    else:
                        usage.set_individual_limit(owner_key, period, tokens)
                        events.admin(admin, ip, "llm_user_limit_change", True, {
                            "user": owner_key, "period": period, "limit": "—" if period == UsageLimiter.PERIOD_NONE else tokens,
                        })
                else:
                    category_id = php_int(choice)
                    if m.limit_categories.find(category_id) is None:
                        page.flash_error = "Категория не найдена."
                    else:
                        usage.set_limit_category(owner_key, category_id)
                        events.admin(admin, ip, "llm_user_limit_change", True, {"user": owner_key, "category_id": category_id})
                if page.flash_error is None:
                    page.flash_success = f"Лимит пользователя {owner_key} сохранён."

    rows = []
    for row in usage.all_profiles():
        owner_key = str(row["owner_key"])
        status, storage = row["status"], m.storage_quota.status(owner_key)
        manual = row["limit_period"] is not None
        rows.append({
            "row": row, "status": status, "storage": storage,
            "over": status["limit"] is not None and status["used"] >= status["limit"],
            "storage_over": storage["limit_bytes"] is not None and storage["used_bytes"] >= storage["limit_bytes"],
            "form_id": "limit-" + hashlib.md5(owner_key.encode()).hexdigest(),
            "select_value": "manual" if manual else (str(int(row["limit_category_id"])) if row["limit_category_id"] is not None else "inherit"),
        })
    return page.render("admin/sections/llm_users.j2", s=chat_settings.get, rows=rows, categories=m.limit_categories.all(),
                       period_labels=PERIOD_LABELS, number_format=number_format)
