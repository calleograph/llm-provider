"""Раздел «Почта и уведомления» (перенос public/admin/sections/mail.php)."""

from __future__ import annotations

import re
import time

from ....files.base import php_trim
from ....llm.providers import php_empty
from ....mail import MailSettings, SmtpClient
from ....mail.settings import AUTH, SECURITY, valid_email
from ..base import FORM_STALE, AdminPage

ACTIONS = ("save_mail", "check_mail", "test_mail", "process_queue")
STAGE_NAMES = {"dns": "Имя сервера", "tcp": "TCP-соединение", "greeting": "Приветствие сервера", "ehlo": "EHLO", "tls": "Шифрование", "certificate": "Сертификат",
               "methods": "Способы входа", "auth": "Авторизация", "sender": "Отправитель", "recipient": "Получатель", "data": "Передача письма"}
CODE_NAMES = {"dns": "имя не разрешается", "connect": "нет соединения (порт закрыт или файрвол)", "timeout": "тайм-аут", "closed": "сервер закрыл соединение",
              "certificate": "сертификат не прошёл проверку", "starttls": "STARTTLS недоступен", "protocol": "нарушение протокола", "auth_failed": "логин или пароль отклонены",
              "auth_unsupported": "способ входа не поддерживается", "relay_denied": "пересылка запрещена", "sender_rejected": "отправитель отклонён",
              "recipient_rejected": "получатель отклонён", "temporary": "временная ошибка сервера", "limit": "превышен лимит сервера", "data": "письмо не принято",
              "forbidden_target": "адрес или порт запрещён"}
STATUS_NAMES = {"queued": "ждёт", "sending": "отправляется", "sent": "отправлено", "failed": "не отправлено"}


def handle(page: AdminPage) -> str:
    m = page.module
    settings, queue, events = m.mail_settings, m.mail_queue, m.events
    admin, ip = page.admin, page.ip
    field_errors: dict[str, str] = {}
    form_values: dict | None = None
    check_result: dict | None = None

    if page.is_post(*ACTIONS):
        if not page.csrf_ok():
            page.flash_error = FORM_STALE
        elif page.action == "save_mail":
            values = {k: "0" if php_empty(page.post(k)) else "1" for k in ("enabled", "verify_cert", "alerts_enabled")}
            for k in ("host", "port", "security", "auth", "username", "from_address", "from_name", "timeout", "helo", "alert_recipients"):
                values[k] = php_trim(page.post(k))
            field_errors = MailSettings.validate(values)
            password = page.post("password")
            if re.search(r"[\r\n\0]", password):
                field_errors["password"] = "Недопустимые символы."
            if field_errors:
                page.flash_error = "Проверьте выделенные поля."
                form_values = values
            else:
                settings.save(values, password if password != "" else None)
                events.admin(admin, ip, "mail_settings_update", True, {"host": values["host"], "port": values["port"], "security": values["security"],
                                                                       "verify_cert": values["verify_cert"], "password": "replaced" if password != "" else "kept"})
                page.flash_success = "Настройки почты сохранены. Проверьте соединение."
            del password
        elif page.action == "check_mail":
            check_result = SmtpClient(settings).check()
            events.admin(admin, ip, "mail_check", check_result["ok"], {"code": check_result.get("code") or "—"})
        elif page.action == "test_mail":
            to = php_trim(page.post("to"))
            if not valid_email(to):
                page.flash_error = "Укажите адрес получателя."
            else:
                check_result = SmtpClient(settings).check(
                    to, "Проверка почты LLM-провайдера",
                    "Это тестовое письмо. Если вы его получили — отправка почты настроена правильно.\n\nВремя: "
                    + time.strftime("%Y-%m-%d %H:%M", time.gmtime()) + " UTC")
                events.admin(admin, ip, "mail_test", check_result["ok"], {"code": check_result.get("code") or "—"})
                if check_result["ok"]:
                    page.flash_success = "Письмо принято сервером."
        else:   # process_queue
            page.flash_success = f"Обработка очереди: отправлено {queue.process(settings)}."

    page.page_title = "Почта и уведомления"
    return page.render(
        "admin/sections/mail.j2",
        current=form_values if form_values is not None else settings.all(), settings=settings, field_errors=field_errors,
        check_result=check_result, security=SECURITY, auth=AUTH, stage_names=STAGE_NAMES, code_names=CODE_NAMES,
        status_names=STATUS_NAMES, rows=queue.recent(),
    )
