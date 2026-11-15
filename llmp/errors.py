"""Машинные коды ошибок → понятные русские сообщения без технических подробностей (LDAP trace, DN, коды AD)."""

from __future__ import annotations

from .ldap.client import LdapErrorCode as C

_MESSAGES = {
    C.INVALID_CREDENTIALS: "Неверная учётная запись или пароль.",
    C.ACCOUNT_LOCKED: "Учётная запись заблокирована. Обратитесь к администратору.",
    C.ACCOUNT_DISABLED: "Учётная запись отключена. Обратитесь к администратору.",
    C.PASSWORD_EXPIRED: "Срок действия пароля истёк. Смените его штатным способом и войдите снова.",
    C.PASSWORD_MUST_CHANGE: "Требуется смена пароля при следующем входе. Смените его штатным способом и войдите снова.",
    C.USER_NOT_FOUND: "Неверная учётная запись или пароль.",
    C.SERVER_UNAVAILABLE: "Сервер Active Directory временно недоступен. Попробуйте позже.",
    C.TLS_ERROR: "Не удалось установить защищённое соединение с сервером Active Directory.",
    C.INSUFFICIENT_ACCESS: "Недостаточно прав для выполнения операции.",
    C.TIMEOUT: "Превышено время ожидания ответа от сервера Active Directory.",
    C.ACCOUNT_RESTRICTION: "Вход временно ограничен для этой учётной записи. Обратитесь к администратору.",
}
_DEFAULT = "Не удалось выполнить операцию. Попробуйте позже или обратитесь к администратору."


def for_code(code: str) -> str:
    return _MESSAGES.get(code, _DEFAULT)
