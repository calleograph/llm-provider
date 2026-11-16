"""Раздел «Подключения LLM API» (перенос public/admin/sections/llm_providers.php)."""

from __future__ import annotations

import json
import re

from ....chat.settings import ChatSettings, php_int
from ....llm.providers import PHP_WS, ProviderValidator
from ....prompt import STYLES
from ..base import FORM_STALE, AdminPage

ACTIONS = ("create_provider", "update_provider", "delete_provider", "test_provider", "toggle_provider", "set_default_provider", "save_chat_settings")
_COMPARED = ("name", "provider_type", "base_url", "endpoint", "model", "api_version", "routing_provider", "sort_by_price", "max_price_per_mtoken",
             "allow_fallbacks", "connect_timeout", "response_timeout", "max_tokens", "use_corporate_ca", "is_enabled")


def php_str(v) -> str:
    """(string) PHP: null → '', bool → '1'/'', float без «.0»."""
    if v is None or v is False:
        return ""
    if v is True:
        return "1"
    if isinstance(v, float):
        return str(int(v)) if v.is_integer() and abs(v) < 1e15 else repr(v)
    return str(v)


def handle(page: AdminPage) -> str:
    m = page.module
    providers, registry, events, chat = m.providers, m.adapters, m.events, m.chat_settings
    admin, ip = page.admin, page.ip
    field_errors: dict[str, str] = {}
    form_values: dict | None = None
    form_provider_id: int | None = None   # 0 — новая нейросеть
    test_results: dict[int, dict] = {}
    posted_id = php_int(page.post("id", "0"))

    if page.is_post(*ACTIONS):
        if not page.csrf_ok():
            page.flash_error = FORM_STALE
        elif page.action in ("create_provider", "update_provider"):
            is_create = page.action == "create_provider"
            existing = None if is_create else providers.find(posted_id)
            if not is_create and existing is None:
                page.flash_error = "Подключение не найдено."
            else:
                data = ProviderValidator.normalize(dict(page.form))
                field_errors = ProviderValidator.validate(data, registry)
                if "name" not in field_errors and providers.name_exists(data["name"], None if is_create else posted_id):
                    field_errors["name"] = "Нейросеть с таким названием уже есть."
                api_key = page.post("api_key").strip(PHP_WS)
                if api_key != "" and (len(api_key.encode()) > 4096 or re.search(r"[\x00-\x20\x7f]", api_key)):
                    field_errors["api_key"] = "Ключ содержит недопустимые символы."
                headers_raw = page.post("extra_headers")
                headers: dict | None | bool = False   # не менять
                if page.post("clear_extra_headers") not in ("", "0"):
                    headers = None
                elif headers_raw.strip(PHP_WS) != "":
                    parsed, header_error = ProviderValidator.parse_headers(headers_raw)
                    if header_error is not None:
                        field_errors["extra_headers"] = header_error
                    else:
                        headers = parsed

                if field_errors:
                    page.flash_error = "Проверьте выделенные поля формы."
                    form_values = data
                    form_provider_id = 0 if is_create else posted_id
                elif is_create:
                    new_id = providers.create(data, api_key, None if headers is False else headers)
                    events.admin(admin, ip, "llm_provider_create", True, {
                        "id": new_id, "name": data["name"], "type": data["provider_type"], "base_url": data["base_url"],
                        "model": data["model"], "enabled": data["is_enabled"], "key_changed": api_key != "",
                        "extra_header_count": len(headers) if isinstance(headers, dict) else 0,
                    })
                    page.flash_success = f"Нейросеть «{data['name']}» добавлена. Проверьте подключение и назначьте права в разделе «Доступ к чату»."
                else:
                    providers.update(posted_id, data, api_key if api_key != "" else None, headers)
                    changed = [f for f in _COMPARED if php_str(existing[f]) != php_str((1 if data[f] else 0) if isinstance(data[f], bool) else data[f])]
                    events.admin(admin, ip, "llm_provider_update", True, {
                        "id": posted_id, "name": data["name"], "changed": changed or ["—"],
                        "key_changed": api_key != "", "headers_changed": headers is not False,
                    })
                    page.flash_success = f"Нейросеть «{data['name']}» сохранена." + (
                        " Адрес изменился — сохранённый ключ API сброшен, введите его заново."
                        if api_key == "" and php_str(existing["base_url"]) != data["base_url"] else "")
                del api_key, headers_raw, headers
        elif page.action == "save_chat_settings":
            style = page.post("style_default")
            values = {
                "system_prompt": page.post("system_prompt").replace("\r\n", "\n").strip(PHP_WS),
                "history_max_chars": str(php_int(page.post("history_max_chars", "0"))),
                "max_message_chars": str(php_int(page.post("max_message_chars", "0"))),
                "chat_token_limit": str(php_int(page.post("chat_token_limit", "0"))),
                "style_default": style if style in STYLES else "balanced",
                "styles_allowed": ",".join(k for k in STYLES if k in page.post_list("styles_allowed")),
                "summary_model_id": str(max(0, php_int(page.post("summary_model_id", "0")))),
            }
            if values["style_default"] not in values["styles_allowed"].split(","):
                values["styles_allowed"] = (values["styles_allowed"] + "," + values["style_default"]).strip(",")
            settings_errors = ChatSettings.validate(values)
            if settings_errors:
                page.flash_error = "Общие параметры не сохранены: " + " ".join(settings_errors.values())
            else:
                chat.save(values)
                events.admin(admin, ip, "llm_chat_settings_update", True, {
                    "history_max_chars": values["history_max_chars"], "max_msg_chars": values["max_message_chars"],
                    "chat_token_limit": values["chat_token_limit"],
                    "system_instruction_chars": len(values["system_prompt"]),
                })
                page.flash_success = "Общие параметры чата сохранены."
        else:
            existing = providers.find(posted_id)
            if existing is None:
                page.flash_error = "Подключение не найдено."
            elif page.action == "toggle_provider":
                enable = not existing["is_enabled"]
                providers.set_enabled(posted_id, enable)
                events.admin(admin, ip, "llm_provider_toggle", True, {"id": posted_id, "name": existing["name"], "enabled": enable})
                page.flash_success = f"Нейросеть «{existing['name']}» " + ("включена." if enable else "отключена.")
            elif page.action == "set_default_provider":
                if existing["is_default"]:
                    providers.clear_default()
                    events.admin(admin, ip, "llm_provider_default_clear", True, {"id": posted_id, "name": existing["name"]})
                    page.flash_success = f"Нейросеть «{existing['name']}» больше не открывается по умолчанию — первой в списке встаёт модель с наименьшим приоритетом."
                else:
                    providers.set_default(posted_id)
                    events.admin(admin, ip, "llm_provider_default_set", True, {"id": posted_id, "name": existing["name"]})
                    page.flash_success = f"Нейросеть «{existing['name']}» теперь открывается по умолчанию (для пользователей, которым она доступна)."
            elif page.action == "delete_provider":
                providers.delete(posted_id)
                events.admin(admin, ip, "llm_provider_delete", True, {"id": posted_id, "name": existing["name"]})
                page.flash_success = f"Нейросеть «{existing['name']}» удалена вместе с назначенными правами."
            else:   # test_provider
                r = test_results[posted_id] = m.gateway.test_connection(posted_id)
                events.admin(admin, ip, "llm_provider_test", r["ok"], {"id": posted_id, "name": existing["name"], "duration_ms": r["duration_ms"]})

    type_defaults = {t: {**a.defaults(), "label": a.label} for t, a in registry.all().items()}

    def val(row: dict | None, field: str, default: str = "") -> str:
        card_id = int(row["id"]) if row is not None else 0
        if form_values is not None and form_provider_id == card_id and field in form_values:
            v = form_values[field]
            return ("1" if v else "") if isinstance(v, bool) else str(v)
        if row is None:
            return default
        return php_str(row.get(field) if row.get(field) is not None else default)

    page.page_title = "Подключения LLM API"
    return page.render(
        "admin/sections/llm_providers.j2",
        rows=providers.all(), type_defaults=type_defaults,
        defaults_json=json.dumps(type_defaults, ensure_ascii=False, separators=(",", ":")),
        val=val, field_errors=field_errors, form_provider_id=form_provider_id, test_results=test_results,
        chat=chat, styles=STYLES, allowed_styles=chat.get("styles_allowed").split(","), models=m.models.all(),
    )
