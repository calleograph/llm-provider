"""Раздел «Категории лимитов» (перенос public/admin/sections/llm_limits.php): именованные категории токенов и хранения."""

from __future__ import annotations

import re

from ....chat import UsageLimiter
from ....chat.settings import php_int
from ....llm.providers import php_empty
from ..base import FORM_STALE, AdminPage

TRIM = " \t\n\r\0\x0b"
PERIOD_LABELS = {"day": "В день", "week": "В неделю", "month": "В месяц", UsageLimiter.PERIOD_NONE: "Без лимита"}
ACTIONS = ("create_category", "update_category", "delete_category")


def category_form(page: AdminPage) -> dict:
    return {
        "name": page.post("name").strip(TRIM),
        "period": page.post("period", "day"),
        "tokens": max(0, php_int(page.post("tokens", "0"))),
        "storage_mb": max(0, php_int(page.post("storage_mb", "0"))),
        "unlimited_storage": not php_empty(page.post("unlimited_storage")),
        "is_default": not php_empty(page.post("is_default")),
    }


def category_validate(data: dict) -> dict[str, str]:
    errors = {}
    if data["name"] == "" or len(data["name"]) > 80 or re.search(r"[\x00-\x1f]", data["name"]):
        errors["name"] = "Укажите название (до 80 символов)."
    if data["period"] not in UsageLimiter.PERIODS:
        errors["period"] = "Недопустимый период."
    if data["period"] != UsageLimiter.PERIOD_NONE and data["tokens"] <= 0:
        errors["tokens"] = "Укажите положительное число токенов."
    if not data["unlimited_storage"] and data["storage_mb"] <= 0:
        errors["storage_mb"] = "Укажите положительный объём (МБ) либо отметьте «без ограничения»."
    return errors


def handle(page: AdminPage) -> str:
    page.page_title = "Категории лимитов"
    categories, events = page.module.limit_categories, page.module.events
    admin, ip = page.admin, page.ip
    field_errors: dict[str, str] = {}
    form_values: dict | None = None
    form_category_id: int | None = None   # 0 — новая категория

    if page.is_post(*ACTIONS):
        if not page.csrf_ok():
            page.flash_error = FORM_STALE
        else:
            posted_id = php_int(page.post("id", "0"))
            if page.action in ("create_category", "update_category"):
                is_create = page.action == "create_category"
                data = category_form(page)
                field_errors = category_validate(data)
                if "name" not in field_errors and categories.name_exists(data["name"], None if is_create else posted_id):
                    field_errors["name"] = "Категория с таким названием уже есть."
                tokens = None if data["period"] == UsageLimiter.PERIOD_NONE else data["tokens"]
                storage_mb = None if data["unlimited_storage"] else data["storage_mb"]
                audit = {"name": data["name"], "period": data["period"], "tokens": "—" if tokens is None else tokens,
                         "storage_mb": "без ограничения" if storage_mb is None else storage_mb, "is_default": data["is_default"]}
                if field_errors:
                    page.flash_error = "Проверьте выделенные поля формы."
                    form_values, form_category_id = data, (0 if is_create else posted_id)
                elif is_create:
                    new_id = categories.create(data["name"], data["period"], tokens, storage_mb, data["is_default"])
                    events.admin(admin, ip, "llm_limit_category_create", True, {"id": new_id, **audit})
                    page.flash_success = f"Категория «{data['name']}» добавлена."
                else:
                    categories.update(posted_id, data["name"], data["period"], tokens, storage_mb, data["is_default"])
                    events.admin(admin, ip, "llm_limit_category_update", True, {"id": posted_id, **audit})
                    page.flash_success = f"Категория «{data['name']}» сохранена."
            else:
                category = categories.find(posted_id)
                if category is None:
                    page.flash_error = "Категория не найдена."
                elif not categories.delete(posted_id):
                    page.flash_error = "Нельзя удалить категорию по умолчанию — сначала назначьте по умолчанию другую категорию."
                else:
                    events.admin(admin, ip, "llm_limit_category_delete", True, {"id": posted_id, "name": category["name"]})
                    page.flash_success = (f"Категория «{category['name']}» удалена. Пользователи и группы, использовавшие её, "
                                          "вернулись к категории по умолчанию.")

    rows = categories.all()

    def val(card_id: int, field: str, default: str = "") -> str:
        if form_values is not None and form_category_id == card_id and field in form_values:
            v = form_values[field]
            return ("1" if v else "") if isinstance(v, bool) else str(v)
        row = next((r for r in rows if r["id"] == card_id), None)
        if row is None:
            return default
        if field == "unlimited_storage":
            return "1" if row["storage_mb"] is None else ""
        v = row.get(field)
        return ("1" if v else "") if isinstance(v, bool) else (default if v is None else str(v))

    def errors_for(card_id: int) -> dict:
        return field_errors if form_category_id == card_id else {}

    return page.render("admin/sections/llm_limits.j2", rows=rows, val=val, errors_for=errors_for,
                       form_category_id=form_category_id, period_labels=PERIOD_LABELS)
