<?php

declare(strict_types=1);

namespace App\Http;

use App\Ldap\LdapErrorCode;

/**
 * Перевод машинных кодов ошибок в понятные русские сообщения без внутренних
 * технических деталей (LDAP trace, DN, коды AD).
 */
final class ErrorMessages
{
    public static function forCode(string $code): string
    {
        return match ($code) {
            LdapErrorCode::INVALID_CREDENTIALS => 'Неверная учётная запись или пароль.',
            LdapErrorCode::ACCOUNT_LOCKED => 'Учётная запись заблокирована. Обратитесь к администратору.',
            LdapErrorCode::ACCOUNT_DISABLED => 'Учётная запись отключена. Обратитесь к администратору.',
            LdapErrorCode::PASSWORD_EXPIRED => 'Срок действия пароля истёк. Смените его штатным способом и войдите снова.',
            LdapErrorCode::PASSWORD_MUST_CHANGE => 'Требуется смена пароля при следующем входе. Смените его штатным способом и войдите снова.',
            LdapErrorCode::USER_NOT_FOUND => 'Неверная учётная запись или пароль.',
            LdapErrorCode::SERVER_UNAVAILABLE => 'Сервер Active Directory временно недоступен. Попробуйте позже.',
            LdapErrorCode::TLS_ERROR => 'Не удалось установить защищённое соединение с сервером Active Directory.',
            LdapErrorCode::INSUFFICIENT_ACCESS => 'Недостаточно прав для выполнения операции.',
            LdapErrorCode::TIMEOUT => 'Превышено время ожидания ответа от сервера Active Directory.',
            LdapErrorCode::ACCOUNT_RESTRICTION => 'Вход временно ограничен для этой учётной записи. Обратитесь к администратору.',
            default => 'Не удалось выполнить операцию. Попробуйте позже или обратитесь к администратору.',
        };
    }
}
