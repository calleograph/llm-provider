"""Раздел «Доступ к чату» (перенос public/admin/sections/chat_access.php): кому разрешён LLM-чат (группы и отдельные
пользователи домена) и единая матрица прав на нейросети."""

from __future__ import annotations

import re

from ....chat.settings import php_int
from ....domain import DomainGroupRepository as Groups
from ....ldap.client import LdapException
from ....llm.providers import php_empty
from ..base import FORM_STALE, AdminPage

TRIM = " \t\n\r\0\x0b"
ACTIONS = ("save_subjects", "verify_groups", "save_permissions")
_BRACKETS = re.compile(r"\[([^\]]*)\]")


def php_array(page: AdminPage, name: str) -> dict:
    """$_POST[name] для полей вида name[a][b] / name[a][] — вложенные словари (ключ '' дописывается по порядку, как в PHP)."""
    out: dict = {}
    if page.form is None:
        return out
    for key, val in page.form.multi_items():
        if not isinstance(val, str) or not key.startswith(name + "["):
            continue
        parts = _BRACKETS.findall(key[len(name):])
        node = out
        for i, part in enumerate(parts):
            k = str(len(node)) if part == "" else part
            if i == len(parts) - 1:
                node[k] = val
            else:
                if not isinstance(node.get(k), dict):
                    node[k] = {}
                node = node[k]
    return out


def _rows(value) -> list:
    return list(value.values()) if isinstance(value, dict) else []


def _f(row: dict, key: str) -> str:
    v = row.get(key, "")
    return v.strip(TRIM) if isinstance(v, str) else ""


def handle(page: AdminPage) -> str:
    page.page_title = "Доступ к чату"
    m = page.module
    domains, groups, access, usage, user_providers, events = m.domains, m.groups, m.access, m.usage, m.user_providers, m.events
    admin, ip = page.admin, page.ip
    verify_results: dict[int, list] = {}
    posted_id = php_int(page.post("id", "0"))

    if page.is_post(*ACTIONS):
        if not page.csrf_ok():
            page.flash_error = FORM_STALE
        elif page.action == "save_subjects":
            existing = domains.find(posted_id)
            if existing is None:
                page.flash_error = "Подключение не найдено."
            else:
                dname = existing.config.name
                errors: list[str] = []
                changes = 0
                for group_key, row in php_array(page, "groups").items():
                    group_id = php_int(group_key)
                    current = groups.find(group_id)
                    if current is None or int(current["ldap_config_id"]) != posted_id or not isinstance(row, dict):
                        continue
                    if not php_empty(row.get("delete")):
                        groups.delete(group_id)
                        events.admin(admin, ip, "chat_access_delete", True, {"domain": dname, "subject": current["display_name"], "ref": current["group_ref"]})
                        changes += 1
                        continue
                    display_name, group_ref = _f(row, "display_name"), _f(row, "group_ref")
                    subject_type = current.get("subject_type") or Groups.SUBJECT_GROUP
                    error = Groups.validate_display_name(display_name) or (
                        Groups.validate_sam_account_name(group_ref) if subject_type == Groups.SUBJECT_USER else Groups.validate_ref(group_ref))
                    if error is None and groups.ref_exists(posted_id, group_ref, group_id):
                        error = "такая запись уже добавлена"
                    if error is not None:
                        errors.append(f"«{display_name or current['display_name']}»: {error}")
                        continue
                    enabled = not php_empty(row.get("enabled"))
                    if display_name != current["display_name"] or group_ref != current["group_ref"] or enabled != bool(current["is_enabled"]):
                        groups.update(group_id, display_name, group_ref, enabled)
                        events.admin(admin, ip, "chat_access_update", True, {"domain": dname, "subject": display_name, "ref": group_ref, "enabled": enabled})
                        changes += 1
                    new_category = php_int(row.get("limit_category_id", 0))
                    new_category = new_category if new_category > 0 else None
                    current_category = int(current["limit_category_id"]) if current["limit_category_id"] is not None else None
                    if new_category != current_category:
                        groups.set_limit_category(group_id, new_category)
                        changes += 1
                    allow = not php_empty(row.get("allow_unanonymized"))
                    if allow != bool(current["allow_unanonymized"]):
                        groups.set_allow_unanonymized(group_id, allow)
                        events.admin(admin, ip, "chat_access_unanonymized_change", True, {"domain": dname, "subject": display_name, "allowed": allow})
                        changes += 1

                for row in _rows(php_array(page, "new_groups")):
                    if not isinstance(row, dict):
                        continue
                    display_name, group_ref = _f(row, "display_name"), _f(row, "group_ref")
                    if display_name == "" and group_ref == "":
                        continue
                    error = Groups.validate_display_name(display_name) or Groups.validate_ref(group_ref)
                    if error is None and groups.ref_exists(posted_id, group_ref):
                        error = "такая запись уже добавлена"
                    if error is not None:
                        errors.append(f"Новая группа «{display_name or group_ref}»: {error}")
                        continue
                    new_id = groups.create(posted_id, display_name, group_ref, not php_empty(row.get("enabled")), admin, Groups.SUBJECT_GROUP)
                    if php_int(row.get("limit_category_id", 0)) > 0:
                        groups.set_limit_category(new_id, php_int(row["limit_category_id"]))
                    if not php_empty(row.get("allow_unanonymized")):
                        groups.set_allow_unanonymized(new_id, True)
                    events.admin(admin, ip, "chat_access_create", True, {"domain": dname, "subject": display_name, "ref": group_ref, "type": "group"})
                    changes += 1

                for row in _rows(php_array(page, "new_users")):
                    if not isinstance(row, dict):
                        continue
                    sam = _f(row, "sam_account_name")
                    if sam == "":
                        continue
                    error = Groups.validate_sam_account_name(sam)
                    if error is None and groups.ref_exists(posted_id, sam):
                        error = "такой пользователь уже добавлен"
                    if error is not None:
                        errors.append(f"Новый пользователь «{sam}»: {error}")
                        continue
                    new_id = groups.create(posted_id, sam, sam, not php_empty(row.get("enabled")), admin, Groups.SUBJECT_USER)
                    if php_int(row.get("limit_category_id", 0)) > 0:
                        groups.set_limit_category(new_id, php_int(row["limit_category_id"]))
                    if not php_empty(row.get("allow_unanonymized")):
                        groups.set_allow_unanonymized(new_id, True)
                    events.admin(admin, ip, "chat_access_create", True, {"domain": dname, "subject": sam, "type": "user"})
                    changes += 1

                if errors:
                    page.flash_error = "Часть строк не сохранена: " + "; ".join(errors) + "."
                if changes > 0:
                    page.flash_success = f"Доступ к чату для «{dname}» сохранён (изменений: {changes})."
                elif not errors:
                    page.flash_success = "Изменений нет."
        elif page.action == "verify_groups":
            existing = domains.find(posted_id)
            if existing is None:
                page.flash_error = "Подключение не найдено."
            else:
                dname = existing.config.name
                try:
                    verify_results[posted_id] = m.authenticator.verify_groups(existing)
                    missing = [r for r in verify_results[posted_id] if not r["ok"]]
                    events.admin(admin, ip, "chat_access_verify", not missing,
                                 {"domain": dname, "subjects": len(verify_results[posted_id]), "not_found": len(missing)})
                except LdapException as e:
                    page.flash_error = str(e) if e.error_code == "SERVICE_ACCOUNT_MISSING" else (
                        f"Не удалось проверить группы/пользователей: ошибка LDAPS ({e.error_code}). Подробности — в журнале диагностики.")
                    events.admin(admin, ip, "chat_access_verify", False, {"domain": dname, "error": e.error_code})
                    m.diagnostics.error("ldap_bind", e.error_code, str(e), {"config": dname}, ip)
                except Exception as e:  # noqa: BLE001
                    page.flash_error = "Не удалось проверить: " + str(e)
                    m.diagnostics.error("ldap_connect", type(e).__name__, str(e), {"config": dname}, ip)
        elif page.action == "save_permissions":
            names = m.providers.names()
            label = lambda ids: [names.get(i, str(i)) for i in ids] or ["—"]  # noqa: E731
            posted = php_array(page, "perm")
            changed_groups = changed_users = 0
            for group in groups.all():
                gid = int(group["id"])
                diff = access.set_group_providers(gid, [php_int(v) for v in _rows(posted.get(str(gid)))])
                if not diff["added"] and not diff["removed"]:
                    continue
                if (group.get("subject_type") or Groups.SUBJECT_GROUP) == Groups.SUBJECT_USER:
                    changed_users += 1
                else:
                    changed_groups += 1
                events.admin(admin, ip, "llm_permission_change", True, {
                    "domain": group["domain_name"], "subject": group["display_name"], "added": label(diff["added"]), "removed": label(diff["removed"]),
                })

            posted_users = php_array(page, "user_perm")
            changed_individual = 0
            for owner_key, provider_ids in posted_users.items():
                before = user_providers.providers_for(owner_key)
                user_providers.set_providers(owner_key, [php_int(v) for v in _rows(provider_ids)] if isinstance(provider_ids, dict) else [php_int(provider_ids)])
                after = user_providers.providers_for(owner_key)
                if before != after:
                    changed_individual += 1
                    events.admin(admin, ip, "llm_user_provider_change", True, {"user": owner_key, "providers": label(after)})
            # пользователи, у которых сняли все галочки, в user_perm не приходят — обнулить их права явно
            for owner_key in list(user_providers.matrix()):
                if owner_key not in posted_users and user_providers.providers_for(owner_key):
                    user_providers.set_providers(owner_key, [])
                    changed_individual += 1
                    events.admin(admin, ip, "llm_user_provider_change", True, {"user": owner_key, "providers": ["—"]})

            # персональное «без обезличивания» — отдельное поле unanon_perm[owner_key]
            posted_unanon = php_array(page, "unanon_perm")
            changed_unanon = 0
            for profile in usage.all_profiles():
                owner_key = str(profile["owner_key"])
                after_flag = not php_empty(posted_unanon.get(owner_key))
                if bool(profile.get("allow_unanonymized")) != after_flag:
                    usage.set_allow_unanonymized(owner_key, after_flag)
                    changed_unanon += 1
                    events.admin(admin, ip, "llm_user_unanonymized_change", True, {"user": owner_key, "allowed": after_flag})

            total = changed_groups + changed_users + changed_individual + changed_unanon
            page.flash_success = (
                f"Права сохранены (изменено: группы — {changed_groups}, отдельные пользователи — {changed_users}, "
                f"индивидуальный доступ вошедших — {changed_individual}, без обезличивания — {changed_unanon})."
            ) if total > 0 else "Изменений нет."

    domain_cards = []
    for d in domains.all():
        subjects = groups.for_domain(d.id())
        is_user = lambda s: (s.get("subject_type") or "group") == Groups.SUBJECT_USER  # noqa: E731
        domain_cards.append({"domain": d, "subjects": subjects,
                             "group_subjects": [s for s in subjects if not is_user(s)],
                             "user_subjects": [s for s in subjects if is_user(s)]})
    return page.render(
        "admin/sections/chat_access.j2", domain_cards=domain_cards, verify_results=verify_results,
        providers=m.providers.all(), matrix=access.matrix(), limit_categories=m.limit_categories.all(),
        all_groups=groups.all(), user_profiles=usage.all_profiles(), user_provider_matrix=user_providers.matrix(),
        subject_user=Groups.SUBJECT_USER,
    )
