<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Валидация полей формы конфигурации подключения к AD (раздел «LDAP-подключения»,
 * а также используется в «Доступе к админ-панели» при смене группы администраторов).
 * Вынесена в отдельный класс для переиспользования и модульного тестирования.
 */
final class LdapConfigValidator
{
    /**
     * @param array{name:string,ldaps_url:string,host:string,port:int,base_dn:string,
     *              domain:string,netbios_domain:string,admin_group_dn:string,
     *              protocol_version:int,network_timeout:int} $data
     * @return string|null Текст ошибки либо null, если данные корректны.
     */
    public static function validate(array $data): ?string
    {
        $errors = self::validateFields($data);

        return $errors === [] ? null : array_values($errors)[0];
    }

    /**
     * Та же валидация, но с привязкой ошибки к конкретному имени поля формы —
     * позволяет подсветить именно то поле, где проблема, вместо общего
     * сообщения об ошибке над формой.
     *
     * @param array{name:string,ldaps_url:string,host:string,port:int,base_dn:string,
     *              domain:string,netbios_domain:string,admin_group_dn:string,
     *              protocol_version:int,network_timeout:int} $data
     * @return array<string,string> имя поля => текст ошибки
     */
    public static function validateFields(array $data): array
    {
        $errors = [];

        if (trim($data['name']) === '') {
            $errors['name'] = 'Укажите имя конфигурации.';
        }
        if (trim($data['host']) === '') {
            $errors['host'] = 'Укажите host.';
        }
        if (trim($data['base_dn']) === '') {
            $errors['base_dn'] = 'Укажите Base DN.';
        }
        if (trim($data['domain']) === '') {
            $errors['domain'] = 'Укажите домен.';
        }
        if (!str_starts_with(strtolower($data['ldaps_url']), 'ldaps://')) {
            $errors['ldaps_url'] = 'LDAPS URL должен начинаться с ldaps://';
        }
        if ($data['port'] < 1 || $data['port'] > 65535) {
            $errors['port'] = 'Порт должен быть в диапазоне 1–65535.';
        }
        if (!in_array($data['protocol_version'], [2, 3], true)) {
            $errors['protocol_version'] = 'Допустимые значения: 2 или 3.';
        }
        if ($data['network_timeout'] < 1 || $data['network_timeout'] > 120) {
            $errors['network_timeout'] = 'Таймаут должен быть в диапазоне 1–120 сек.';
        }

        return $errors;
    }
}
