"""Экспорт и восстановление настроек LLM-чата (перенос src/Llm/Backup/ConfigBackupService.php): домены и группы,
нейросети, права «группа → нейросеть», общие параметры чата — config.json внутри ZIP-архива.

Секреты (пароли служебных учёток LDAP, API-ключи, дополнительные HTTP-заголовки) в архив не попадают — архив
переносится между серверами и не должен быть носителем секретов. Импорт — upsert по бизнес-ключам (name/domain/
group_ref), а не по id. В отличие от версии 1.x весь импорт идёт в одной транзакции: ошибка посередине откатывает
всё (вложенные транзакции репозиториев становятся точками сохранения)."""

from __future__ import annotations

import io
import json
import re
import sqlite3
import time
import zipfile

from .auth import admin_users, set_admin_users
from .chat.settings import ChatSettings, php_int
from .db import now_iso, transaction
from .domain import DomainGroupRepository, LlmDomainRepository
from .ldap.config import LdapConfigRepository, validate_fields
from .llm.access import AccessPolicy
from .llm.adapters import AdapterRegistry
from .llm.providers import ProviderRepository, ProviderValidator, php_strval

FORMAT = "llm_config_backup"
VERSION = 1
MAX_CONFIG_BYTES = 16 * 1024 * 1024   # config.json больше не бывает; защита от «zip-бомбы»


class ConfigBackupException(RuntimeError):
    pass


class ConfigBackupService:
    def __init__(self, conn: sqlite3.Connection, ldap_configs: LdapConfigRepository, llm_domains: LlmDomainRepository,
                 groups: DomainGroupRepository, providers: ProviderRepository, access: AccessPolicy,
                 chat_settings: ChatSettings, adapters: AdapterRegistry):
        self.conn = conn
        self.ldap_configs = ldap_configs
        self.llm_domains = llm_domains
        self.groups = groups
        self.providers = providers
        self.access = access
        self.chat_settings = chat_settings
        self.adapters = adapters

    @classmethod
    def from_module(cls, module) -> "ConfigBackupService":
        """Аналог LlmModule::backup() PHP."""
        return cls(module.conn, module.ldap_configs, module.domains, module.groups, module.providers, module.access,
                   module.chat_settings, module.adapters)

    # ---- экспорт ----
    def export_zip(self) -> bytes:
        data = self.build_export_data()
        buf = io.BytesIO()
        with zipfile.ZipFile(buf, "w", zipfile.ZIP_DEFLATED) as z:
            z.writestr("config.json", json.dumps(data, ensure_ascii=False, indent=4))
            z.comment = ("LLM chat config backup, exported_at=" + data["exported_at"]).encode()
        return buf.getvalue()

    def build_export_data(self) -> dict:
        domains = []
        for d in self.llm_domains.all():
            c = d.config
            domains.append({
                "name": c.name, "ldaps_url": c.ldaps_url, "host": c.host, "port": c.port, "base_dn": c.base_dn,
                "domain": c.domain, "netbios_domain": c.netbios_domain, "admin_group_dn": c.admin_group_dn,
                "protocol_version": c.protocol_version, "starttls": c.start_tls, "network_timeout": c.network_timeout,
                "ca_bundle_file": c.ca_bundle_file, "llm_enabled": d.llm_enabled, "service_username": d.service_username,
                "admin_users": admin_users(self.conn, c.id),
                "groups": [{
                    "display_name": str(g["display_name"]), "group_ref": str(g["group_ref"]), "is_enabled": bool(g["is_enabled"]),
                    "allow_unanonymized": bool(g.get("allow_unanonymized") or False),
                } for g in self.groups.for_domain(c.id)],
            })

        providers, provider_name_by_id = [], {}
        for r in self.providers.all():
            provider_name_by_id[int(r["id"])] = str(r["name"])
            providers.append({
                "name": str(r["name"]), "provider_type": str(r["provider_type"]), "base_url": str(r["base_url"]),
                "endpoint": str(r["endpoint"]), "model": str(r["model"]),
                "api_version": str(r["api_version"]) if r["api_version"] is not None else "",
                "connect_timeout": int(r["connect_timeout"]), "response_timeout": int(r["response_timeout"]),
                "max_tokens": int(r["max_tokens"]),
                "routing_provider": str(r["routing_provider"]) if r["routing_provider"] is not None else "",
                "sort_by_price": bool(r.get("sort_by_price") or False),
                "max_price_per_mtoken": float(r["max_price_per_mtoken"]) if r.get("max_price_per_mtoken") is not None else None,
                "allow_fallbacks": bool(r["allow_fallbacks"]) if r.get("allow_fallbacks") is not None else True,
                "use_corporate_ca": bool(r["use_corporate_ca"]), "is_enabled": bool(r["is_enabled"]),
                "is_default": bool(r.get("is_default") or False),
            })

        group_key_by_id = {int(g["id"]): (str(g["domain_fqdn"]), str(g["group_ref"])) for g in self.groups.all()}
        access_matrix = []
        for group_id, provider_ids in self.access.matrix().items():
            if group_id not in group_key_by_id:
                continue
            for pid in provider_ids:
                if pid in provider_name_by_id:
                    fqdn, ref = group_key_by_id[group_id]
                    access_matrix.append({"domain_fqdn": fqdn, "group_ref": ref, "provider_name": provider_name_by_id[pid]})

        return {
            "format": FORMAT, "version": VERSION, "exported_at": now_iso(),
            "domains": domains, "providers": providers, "access_matrix": access_matrix,
            "chat_settings": self.chat_settings.all(),
        }

    # ---- импорт ----
    def import_zip(self, content: bytes, actor: str) -> dict:
        try:
            with zipfile.ZipFile(io.BytesIO(content)) as z:
                try:
                    info = z.getinfo("config.json")
                except KeyError:
                    raise ConfigBackupException("В архиве нет config.json.") from None
                if info.file_size > MAX_CONFIG_BYTES:
                    raise ConfigBackupException("config.json повреждён или не является корректным JSON.")
                raw = z.read(info)
        except (zipfile.BadZipFile, OSError, EOFError):
            raise ConfigBackupException("Файл не является корректным ZIP-архивом.") from None
        try:
            data = json.loads(raw.decode("utf-8"))
        except (UnicodeDecodeError, ValueError, RecursionError):
            raise ConfigBackupException("config.json повреждён или не является корректным JSON.") from None
        if not isinstance(data, dict) or data.get("format") != FORMAT:
            raise ConfigBackupException("Это не архив резервной копии настроек LLM-чата.")
        return self.apply_import(data, actor)

    def apply_import(self, data: dict, actor: str) -> dict:
        try:
            with transaction(self.conn):
                return self._apply(data, actor)
        except ConfigBackupException:
            raise
        except Exception as exc:  # noqa: BLE001
            raise ConfigBackupException(f"Восстановление прервано из-за ошибки (изменения не применены): {exc}") from exc

    @staticmethod
    def _list(v) -> list:
        """(array) PHP: словарь — его значения, скаляр — список из одного элемента."""
        if v is None:
            return []
        if isinstance(v, dict):
            return list(v.values())
        return v if isinstance(v, list) else [v]

    def _apply(self, data: dict, actor: str) -> dict:
        stats = {"domains_created": 0, "domains_updated": 0, "groups_created": 0, "groups_updated": 0,
                 "providers_created": 0, "providers_updated": 0, "access_links": 0, "warnings": []}
        warn = stats["warnings"].append

        for dd in self._list(data.get("domains")):
            if not isinstance(dd, dict) or dd.get("name") is None or dd.get("domain") is None:
                warn("Пропущена запись домена без имени.")
                continue
            name = str(dd["name"])
            existing = next((c for c in self.ldap_configs.all() if c.name == name), None)
            ca = dd.get("ca_bundle_file")
            payload = {
                "name": name, "ldaps_url": str(dd.get("ldaps_url") or ""), "host": str(dd.get("host") or ""),
                "port": php_int(dd.get("port", 636)), "base_dn": str(dd.get("base_dn") or ""),
                "domain": str(dd.get("domain") or ""), "netbios_domain": str(dd.get("netbios_domain") or ""),
                "admin_group_dn": str(dd.get("admin_group_dn") or ""), "protocol_version": php_int(dd.get("protocol_version", 3)),
                "starttls": bool(dd.get("starttls") or False), "network_timeout": php_int(dd.get("network_timeout", 8)),
                "ca_bundle_file": str(ca) if ca is not None else None,
            }
            errors = validate_fields(payload)
            if errors:
                warn(f"Домен «{name}»: {' '.join(errors.values())} Пропущен.")
                continue
            if existing is not None:
                self.ldap_configs.update(existing.id, payload)
                domain_id = existing.id
                stats["domains_updated"] += 1
            else:
                domain_id = self.ldap_configs.create(payload, False)
                stats["domains_created"] += 1
            self.llm_domains.save_llm_settings(domain_id, bool(dd.get("llm_enabled") or False), str(dd.get("service_username") or ""), None, False)
            if isinstance(dd.get("admin_users"), list):
                set_admin_users(self.conn, domain_id, [str(u) for u in dd["admin_users"] if re.fullmatch(r"[A-Za-z0-9._-]{1,64}", str(u))])

            for gd in self._list(dd.get("groups")):
                if not isinstance(gd, dict) or gd.get("group_ref") is None:
                    continue
                ref = str(gd["group_ref"])
                display = str(gd["display_name"]) if gd.get("display_name") is not None else ref
                err = DomainGroupRepository.validate_display_name(display) or DomainGroupRepository.validate_ref(ref)
                if err is not None:
                    warn(f"Группа «{display}» ({name}): {err} Пропущена.")
                    continue
                found = next((g for g in self.groups.for_domain(domain_id) if str(g["group_ref"]).lower() == ref.lower()), None)
                enabled = bool(gd.get("is_enabled", True))
                allow = bool(gd.get("allow_unanonymized") or False)
                if found is not None:
                    self.groups.update(int(found["id"]), display, ref, enabled)
                    self.groups.set_allow_unanonymized(int(found["id"]), allow)
                    stats["groups_updated"] += 1
                else:
                    new_id = self.groups.create(domain_id, display, ref, enabled, actor)
                    if allow:
                        self.groups.set_allow_unanonymized(new_id, True)
                    stats["groups_created"] += 1

        provider_id_by_name = {str(r["name"]): int(r["id"]) for r in self.providers.all()}
        for pd in self._list(data.get("providers")):
            if not isinstance(pd, dict) or pd.get("name") is None:
                warn("Пропущена запись нейросети без имени.")
                continue
            normalized = ProviderValidator.normalize({k: ("1" if v else "") if isinstance(v, bool) else v for k, v in pd.items()})
            errors = ProviderValidator.validate(normalized, self.adapters)
            if errors:
                warn(f"Нейросеть «{pd['name']}»: {' '.join(errors.values())} Пропущена.")
                continue
            pname = normalized["name"]
            if pname in provider_id_by_name:
                pid = provider_id_by_name[pname]
                self.providers.update(pid, normalized, None, False)
                stats["providers_updated"] += 1
            else:
                pid = self.providers.create(normalized, "", None)
                provider_id_by_name[pname] = pid
                stats["providers_created"] += 1
            # is_default не проходит через normalize()/update() — отдельная операция с единственностью
            if pd.get("is_default"):
                self.providers.set_default(pid)
            warn(f"Нейросеть «{pname}»: API-ключ не восстановлен из архива — введите заново в разделе «Нейросети».")

        group_id_by_key = {f"{str(g['domain_fqdn']).lower()}|{str(g['group_ref']).lower()}": int(g["id"]) for g in self.groups.all()}
        by_group: dict[int, list[int]] = {}
        for link in self._list(data.get("access_matrix")):
            if not isinstance(link, dict) or any(link.get(k) is None for k in ("domain_fqdn", "group_ref", "provider_name")):
                continue
            gid = group_id_by_key.get(f"{str(link['domain_fqdn']).lower()}|{str(link['group_ref']).lower()}")
            pid = provider_id_by_name.get(str(link["provider_name"]))
            if gid is None or pid is None:
                warn(f"Право доступа пропущено: не найден домен/группа/нейросеть ({link['domain_fqdn']} / {link['group_ref']} / {link['provider_name']}).")
                continue
            by_group.setdefault(gid, []).append(pid)
        for gid, pids in by_group.items():
            unique = list(dict.fromkeys(pids))
            self.access.set_group_providers(gid, unique)
            stats["access_links"] += len(unique)

        if isinstance(data.get("chat_settings"), dict):
            values = {str(k): php_strval(v) for k, v in data["chat_settings"].items()}
            errors = ChatSettings.validate(values)
            if not errors:
                self.chat_settings.save(values)
            else:
                warn("Общие параметры чата не восстановлены: " + " ".join(errors.values()))
        return stats


def export_filename() -> str:
    return "llm_config_backup_" + time.strftime("%Y%m%d_%H%M%S", time.gmtime()) + ".zip"
