"""Пошаговая диагностика подключения (кнопка «Проверить подключение»): DNS → TCP → CA bundle → сертификат сервера →
TLS-проверка LDAP-слоем → (необязательно) тестовый bind. Факты и безопасные коды шагов, без паролей и LDAP trace."""

from __future__ import annotations

import re
import socket
import ssl
import time

from cryptography import x509

from .. import errors
from ..certs import format_name, format_timestamp, name_parts
from .client import LdapClient, LdapConnectionConfig, LdapErrorCode, LdapException
from .status import _is_ip, resolve

_TLS_LABEL = "TLS handshake и проверка цепочки сертификатов (ldap3)"
_LINK_DOWN = (LdapErrorCode.TLS_ERROR, LdapErrorCode.SERVER_UNAVAILABLE, LdapErrorCode.TIMEOUT)

format_dn = format_name


def normalize_dn(dn: str) -> str:
    """Устойчиво к регистру, порядку компонентов и разделителям «+»/«,»."""
    return "|".join(sorted(p.strip().lower() for p in re.split(r"[,+]", dn)))


class ConnectionDiagnostics:
    def __init__(self, ca_bundle_builder, certificate_repository=None):
        self.ca_bundle_builder = ca_bundle_builder
        self.certificate_repository = certificate_repository

    def check(self, config: LdapConnectionConfig, test_username: str | None, test_password: str | None) -> dict:
        result = {
            "dns_resolved": False, "resolved_ip": None, "tcp_reachable": False, "tcp_error": None,
            "ca_bundle_ready": False, "ca_bundle_error": None, "peer_certificate": None, "peer_certificate_error": None,
            "tls_valid": False, "chain_valid": False, "tls_error_code": None, "tls_error_message": None,
            "bind_tested": False, "bind_successful": None, "bind_error_code": None, "bind_error_message": None, "steps": [],
        }
        steps = result["steps"]
        host = config.host

        # 1. DNS
        ip = resolve(host)
        result["dns_resolved"] = ip != host or _is_ip(host)
        result["resolved_ip"] = ip if result["dns_resolved"] else None
        steps.append({"label": "DNS-разрешение имени " + host, "ok": result["dns_resolved"],
                      "detail": ("Разрешён в " + ip) if result["dns_resolved"] else "Имя не разрешается в IP-адрес."})
        if not result["dns_resolved"]:
            return result

        # 2. TCP
        timeout = max(1, min(config.network_timeout, 5))
        tcp_label = f"TCP-подключение к {host}:{config.port}"
        try:
            socket.create_connection((host, config.port), timeout=timeout).close()
        except OSError as exc:
            msg = (exc.strerror or str(exc) or "").strip()
            result["tcp_error"] = msg if msg else f"errno {exc.errno or 0}"
            steps.append({"label": tcp_label, "ok": False,
                          "detail": f"Порт недоступен ({result['tcp_error']}). Проверьте firewall/маршрутизацию и что AD слушает LDAPS на этом порту."})
            return result
        result["tcp_reachable"] = True
        steps.append({"label": tcp_label, "ok": True, "detail": "Порт открыт."})

        # 3. CA bundle приложения
        try:
            ca_bundle_path = self.ca_bundle_builder.current_bundle_path()
        except Exception as exc:  # noqa: BLE001
            result["ca_bundle_error"] = str(exc)
            steps.append({"label": "Собственный CA bundle приложения", "ok": False,
                          "detail": "Не загружено ни одного доверенного сертификата — загрузите Root/Intermediate CA в разделе «Сертификаты»."})
            return result
        result["ca_bundle_ready"] = True
        steps.append({"label": "Собственный CA bundle приложения", "ok": True,
                      "detail": "Собран из загруженных сертификатов, используется для проверки TLS."})

        # 4. Сертификат, который предъявляет сервер, — только показать (доверие решает шаг 5)
        peer = self._read_peer_certificate(host, config.port, timeout)
        if peer is not None:
            result["peer_certificate"] = peer
            # подсказка, не проверка: текстовое сравнение DN ненадёжно, итог — только реальный TLS handshake
            if self._issuer_is_among_uploaded_certificates(peer["issuer"]):
                issuer_detail = " Издатель похож на один из загруженных сертификатов."
            else:
                issuer_detail = (f' Издатель ("{peer["issuer"]}") текстуально не совпал ни с одним загруженным Subject — '
                                 "это не обязательно ошибка (см. итоговый результат TLS handshake ниже), но если он окажется "
                                 "отрицательным, скорее всего не хватает Intermediate CA в цепочке.")
            subject = peer["subject"] or "(пусто — обычно для сертификатов контроллеров домена, имя указано в SAN)"
            steps.append({"label": "Сертификат, предъявленный сервером", "ok": not peer["expired"],
                          "detail": f"Subject: {subject}; Issuer: {peer['issuer']}; действует до {peer['valid_to']}"
                                    f"{' — СРОК ИСТЁК' if peer['expired'] else ''}{issuer_detail}"})
        else:
            result["peer_certificate_error"] = "Не удалось прочитать сертификат сервера напрямую (сеть может блокировать «сырой» TLS вне LDAP-порта или сервер требует SNI)."
            steps.append({"label": "Сертификат, предъявленный сервером", "ok": False,
                          "detail": result["peer_certificate_error"] + " Это не обязательно ошибка — LDAP-проверка ниже всё равно выполняется."})

        # 5. TLS глазами LDAP-слоя (анонимный bind форсирует handshake) и необязательный тестовый bind
        client = LdapClient(config, ca_bundle_path)
        try:
            client.connect()
            try:
                client.bind("", "")
                result["tls_valid"] = result["chain_valid"] = True
                steps.append({"label": _TLS_LABEL, "ok": True, "detail": "Сертификат сервера принят нашим CA bundle."})
            except LdapException as exc:
                if exc.error_code in _LINK_DOWN:
                    result["tls_error_code"] = exc.error_code
                    result["tls_error_message"] = errors.for_code(exc.error_code)
                    steps.append({"label": _TLS_LABEL, "ok": False,
                                  "detail": "Сертификат сервера НЕ принят нашим CA bundle, либо TLS handshake не завершился. "
                                            "Сверьте Subject/Issuer сертификата выше с загруженными CA в разделе «Сертификаты» — "
                                            "скорее всего загружен не тот CA либо не хватает Intermediate CA в цепочке."})
                else:
                    # осмысленный LDAP-код ответа — TLS уже пройден
                    result["tls_valid"] = result["chain_valid"] = True
                    steps.append({"label": _TLS_LABEL, "ok": True,
                                  "detail": "Сертификат принят (анонимный bind отклонён политикой AD, что ожидаемо и не является TLS-ошибкой)."})

            if result["tls_valid"] and test_username and test_password:
                result["bind_tested"] = True
                label = f"Тестовый LDAP bind ({test_username})"
                try:
                    client.bind(f"{test_username}@{config.domain}", test_password)
                    result["bind_successful"] = True
                    steps.append({"label": label, "ok": True, "detail": "Учётные данные приняты."})
                except LdapException as exc:
                    result["bind_successful"] = False
                    result["bind_error_code"] = exc.error_code
                    result["bind_error_message"] = errors.for_code(exc.error_code)
                    steps.append({"label": label, "ok": False, "detail": result["bind_error_message"]})
        except Exception:  # noqa: BLE001
            result["tls_valid"] = result["chain_valid"] = False
            steps.append({"label": _TLS_LABEL, "ok": False, "detail": "Непредвиденная ошибка при установке соединения."})
        finally:
            client.close()
        return result

    @staticmethod
    def _read_peer_certificate(host: str, port: int, timeout: int) -> dict | None:
        """Без проверки доверия намеренно: нужно только увидеть сертификат для сравнения с загруженными CA."""
        ctx = ssl.create_default_context()
        ctx.check_hostname = False
        ctx.verify_mode = ssl.CERT_NONE
        try:
            with socket.create_connection((host, port), timeout=timeout) as raw, ctx.wrap_socket(raw, server_hostname=host) as tls:
                der = tls.getpeercert(binary_form=True)
            cert = x509.load_der_x509_certificate(der) if der else None
        except (OSError, ValueError):
            return None
        if cert is None:
            return None
        valid_to = cert.not_valid_after_utc
        return {
            "subject": format_name(name_parts(cert.subject)),
            "issuer": format_name(name_parts(cert.issuer)),
            "valid_from": format_timestamp(cert.not_valid_before_utc),
            "valid_to": format_timestamp(valid_to),
            "expired": 0 < valid_to.timestamp() < time.time(),
        }

    def _issuer_is_among_uploaded_certificates(self, issuer: str) -> bool:
        if self.certificate_repository is None:
            return True   # нет репозитория — без ложной тревоги
        normalized = normalize_dn(issuer)
        return any(normalize_dn(c["subject"]) == normalized for c in self.certificate_repository.all())
