<?php

declare(strict_types=1);

namespace App\Ldap;

/**
 * Тонкая обёртка над ext-ldap для одного соединения с AD через LDAPS.
 *
 * Принципиально:
 *  - TLS-проверка сертификата ВСЕГДА включена (LDAP_OPT_X_TLS_REQUIRE_CERT = DEMAND).
 *    Доверяем только собственному CA bundle (собранному из загруженных администратором
 *    сертификатов), см. Certificate\CaBundleBuilder. Системный trust store не используется
 *    и не изменяется.
 *  - StartTLS не выполняется, если используется ldaps:// (соединение уже зашифровано на
 *    транспортном уровне) — включение StartTLS поверх ldaps:// не имеет смысла и запрещено
 *    конфигурацией (starttls=false для ldaps).
 *  - Не содержит НИКАКОЙ операции admin reset/SetPassword.
 */
final class LdapClient
{
    private \LDAP\Connection|false $conn = false;

    public function __construct(
        private readonly LdapConnectionConfig $config,
        private readonly string $caBundlePath,
    ) {
    }

    public function connect(): void
    {
        // Эти константы должны существовать всегда, если ext-ldap собран с
        // поддержкой TLS (--with-ldap-sasl / TLS-опции libldap). Их отсутствие
        // означает, что PHP на этом сервере собран БЕЗ поддержки TLS-опций
        // OpenLDAP — в таком случае мы обязаны явно отказать (fail-closed),
        // а не тихо пропустить проверку сертификата.
        if (!defined('LDAP_OPT_X_TLS_CACERTFILE') || !defined('LDAP_OPT_X_TLS_REQUIRE_CERT') || !defined('LDAP_OPT_X_TLS_DEMAND')) {
            throw new LdapException(
                LdapErrorCode::TLS_ERROR,
                'ext-ldap на этом сервере собран без поддержки TLS-опций OpenLDAP (LDAP_OPT_X_TLS_*). '
                . 'Требуется PHP с ext-ldap, собранным с поддержкой TLS.',
            );
        }

        // LDAP_OPT_X_TLS_CACERTFILE должен быть установлен ДО ldap_connect,
        // так как в OpenLDAP TLS-опции частично являются глобальными для процесса.
        if (!is_file($this->caBundlePath)) {
            throw new LdapException(LdapErrorCode::TLS_ERROR, 'CA bundle не найден: ' . $this->caBundlePath);
        }

        if (!ldap_set_option(null, LDAP_OPT_X_TLS_CACERTFILE, $this->caBundlePath)) {
            throw new LdapException(LdapErrorCode::TLS_ERROR, 'Не удалось установить CA bundle для TLS.');
        }

        // Строгая проверка сертификата сервера — обязательна, обхода нет.
        ldap_set_option(null, LDAP_OPT_X_TLS_REQUIRE_CERT, LDAP_OPT_X_TLS_DEMAND);

        // LDAP_OPT_X_TLS_NEWCTX не определена в некоторых сборках ext-ldap
        // (зависит от версии libldap/OpenLDAP, с которой собран PHP) — без
        // неё TLS-опции выше всё равно применяются, эта опция лишь форсирует
        // создание нового TLS-контекста при повторных подключениях в рамках
        // одного процесса. Не критична для одиночного подключения за запрос.
        if (defined('LDAP_OPT_X_TLS_NEWCTX')) {
            ldap_set_option(null, LDAP_OPT_X_TLS_NEWCTX, 0);
        }

        $uri = $this->config->ldapsUrl;
        $conn = ldap_connect($uri);
        if ($conn === false) {
            throw new LdapException(LdapErrorCode::SERVER_UNAVAILABLE, 'ldap_connect вернул false для ' . $uri);
        }

        ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, $this->config->protocolVersion);
        ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
        ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, $this->config->networkTimeout);
        ldap_set_option($conn, LDAP_OPT_TIMEOUT, $this->config->networkTimeout);

        // StartTLS применяется только для не-ldaps:// соединений и явно включён в конфигурации.
        $isLdapsScheme = str_starts_with(strtolower($uri), 'ldaps://');
        if (!$isLdapsScheme && $this->config->startTls) {
            if (!@ldap_start_tls($conn)) {
                throw new LdapException(LdapErrorCode::TLS_ERROR, 'StartTLS не удался: ' . ldap_error($conn));
            }
        }

        $this->conn = $conn;
    }

    public function handle(): \LDAP\Connection
    {
        if ($this->conn === false) {
            throw new LdapException(LdapErrorCode::SERVER_UNAVAILABLE, 'LDAP-соединение не установлено.');
        }

        return $this->conn;
    }

    /**
     * Простой bind с указанными DN/паролем (для аутентификации самого пользователя
     * или для тестового bind в диагностике). Не используется как service account
     * для операций над чужими записями.
     */
    public function bind(string $dn, string $password): void
    {
        $conn = $this->handle();
        $result = @ldap_bind($conn, $dn, $password);

        if ($result === false) {
            $errno = 0;
            ldap_get_option($conn, LDAP_OPT_ERROR_NUMBER, $errno);
            $diagnostic = '';
            ldap_get_option($conn, LDAP_OPT_DIAGNOSTIC_MESSAGE, $diagnostic);

            throw new LdapException(
                self::classifyBindError($errno, (string) $diagnostic),
                sprintf('bind failed errno=%d diag=%s', $errno, $diagnostic),
            );
        }
    }

    /**
     * Анонимный/системный поиск DN пользователя по sAMAccountName нужен только
     * ПОСЛЕ успешного bind самого пользователя (используем его же соединение и права),
     * поэтому отдельного service account не требуется.
     */
    public function findUserDnBySamAccountName(string $samAccountName): ?string
    {
        $conn = $this->handle();
        $escaped = ldap_escape($samAccountName, '', LDAP_ESCAPE_FILTER);
        $filter = sprintf('(&(objectClass=user)(objectCategory=person)(sAMAccountName=%s))', $escaped);

        $result = @ldap_search($conn, $this->config->baseDn, $filter, ['distinguishedName']);
        if ($result === false) {
            throw new LdapException(LdapErrorCode::UNKNOWN, 'Поиск пользователя не удался: ' . ldap_error($conn));
        }

        $entries = ldap_get_entries($conn, $result);
        if ($entries === false || (int) $entries['count'] === 0) {
            return null;
        }

        if ((int) $entries['count'] > 1) {
            throw new LdapException(LdapErrorCode::UNKNOWN, 'Поиск вернул несколько записей для одного sAMAccountName.');
        }

        return (string) $entries[0]['dn'];
    }

    /**
     * Проверка членства (прямого и вложенного) в группе через
     * LDAP_MATCHING_RULE_IN_CHAIN (1.2.840.113556.1.4.1941).
     */
    public function isMemberOfGroupRecursive(string $userDn, string $groupDn): bool
    {
        $conn = $this->handle();
        $escapedUserDn = ldap_escape($userDn, '', LDAP_ESCAPE_FILTER);
        $filter = sprintf('(member:1.2.840.113556.1.4.1941:=%s)', $escapedUserDn);

        $result = @ldap_read($conn, $groupDn, $filter, ['dn']);
        if ($result === false) {
            return false;
        }

        $entries = ldap_get_entries($conn, $result);

        return $entries !== false && (int) $entries['count'] > 0;
    }

    /**
     * Поиск DN группы по её CN в пределах Base DN подключения — для
     * разрешённых групп LLM-чата, заданных администратором только именем
     * (без полного DN). CN экранируется как значение фильтра. Если найдено
     * не ровно одна группа, возвращается null: неоднозначность трактуется
     * как «группа не найдена» (fail closed).
     */
    public function findGroupDnByCn(string $cn): ?string
    {
        $conn = $this->handle();
        $escaped = ldap_escape($cn, '', LDAP_ESCAPE_FILTER);
        $filter = sprintf('(&(objectClass=group)(cn=%s))', $escaped);

        $result = @ldap_search($conn, $this->config->baseDn, $filter, ['distinguishedName']);
        if ($result === false) {
            throw new LdapException(LdapErrorCode::UNKNOWN, 'Поиск группы не удался: ' . ldap_error($conn));
        }

        $entries = ldap_get_entries($conn, $result);
        if ($entries === false || (int) $entries['count'] !== 1) {
            return null;
        }

        return (string) $entries[0]['dn'];
    }

    public function groupExists(string $groupDn): bool
    {
        $conn = $this->handle();
        $result = @ldap_read($conn, $groupDn, '(objectClass=group)', ['dn']);
        if ($result === false) {
            return false;
        }

        $entries = ldap_get_entries($conn, $result);

        return $entries !== false && (int) $entries['count'] > 0;
    }

    public function close(): void
    {
        if ($this->conn !== false) {
            ldap_unbind($this->conn);
            $this->conn = false;
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    private static function classifyBindError(int $errno, string $diagnostic): string
    {
        // AD возвращает LDAP_INVALID_CREDENTIALS (49) с data-кодом внутри diagnostic message.
        if ($errno === 49) {
            if (str_contains($diagnostic, 'data 775')) {
                return LdapErrorCode::ACCOUNT_LOCKED;
            }
            if (str_contains($diagnostic, 'data 533')) {
                return LdapErrorCode::ACCOUNT_DISABLED;
            }
            if (str_contains($diagnostic, 'data 532')) {
                return LdapErrorCode::PASSWORD_EXPIRED;
            }

            // data 773 — ERROR_PASSWORD_MUST_CHANGE ("User must change password
            // at next logon", pwdLastSet=0). Отличается от истёкшего пароля (532):
            // введённый пароль пригоден как старое значение для Change Password,
            // это не окончательный отказ.
            if (str_contains($diagnostic, 'data 773')) {
                return LdapErrorCode::PASSWORD_MUST_CHANGE;
            }

            // data 52f — ERROR_ACCOUNT_RESTRICTION. Пароль мог быть введён
            // верно, но simple bind отклонён из-за ограничения на сам вход —
            // типично для членов защищённой группы AD "Protected Users"
            // (Kerberos-only с 2012 R2+). См. LdapErrorCode::ACCOUNT_RESTRICTION.
            if (str_contains($diagnostic, 'data 52f')) {
                return LdapErrorCode::ACCOUNT_RESTRICTION;
            }

            return LdapErrorCode::INVALID_CREDENTIALS;
        }

        if ($errno === 52) { // LDAP_UNAVAILABLE
            return LdapErrorCode::SERVER_UNAVAILABLE;
        }

        if ($errno === -1 || $errno === 81) { // LDAP_SERVER_DOWN
            return LdapErrorCode::SERVER_UNAVAILABLE;
        }

        if ($errno === 50) { // LDAP_INSUFFICIENT_ACCESS
            return LdapErrorCode::INSUFFICIENT_ACCESS;
        }

        return LdapErrorCode::UNKNOWN;
    }
}
