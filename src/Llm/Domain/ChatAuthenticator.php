<?php

declare(strict_types=1);

namespace App\Llm\Domain;

use App\Certificate\CaBundleBuilder;
use App\Diagnostics\DiagnosticLogger;
use App\Diagnostics\DiagnosticSource;
use App\Ldap\LdapClient;
use App\Ldap\LdapException;

/**
 * Доменная авторизация пользователя LLM-чата на базе существующего
 * LdapClient (LDAPS, строгая проверка сертификата по CA bundle приложения):
 *
 *  1. simple bind от имени самого пользователя — проверка пароля;
 *  2. поиск DN пользователя;
 *  3. проверка членства (включая вложенные группы, LDAP_MATCHING_RULE_IN_CHAIN —
 *     тот же isMemberOfGroupRecursive, что и для группы администраторов)
 *     в КАЖДОЙ включённой разрешённой группе этого домена.
 *
 * Шаги 2–3 выполняются под сервисной read-only учёткой домена, если она
 * настроена, иначе — на соединении самого пользователя. Вход разрешается,
 * только если найдено членство хотя бы в одной разрешённой группе.
 */
final class ChatAuthenticator
{
    public function __construct(
        private readonly DomainGroupRepository $groups,
        private readonly LlmDomainRepository $domains,
        private readonly CaBundleBuilder $caBundleBuilder,
        private readonly ?DiagnosticLogger $diagnosticLogger = null,
    ) {
    }

    /**
     * @return array{user_dn:?string,groups:array<int,string>,unresolved:string[],lookup:string}
     * @throws LdapException неверный пароль, блокировка, недоступность LDAPS и т.п.
     */
    public function authenticate(LlmDomain $domain, string $samAccountName, #[\SensitiveParameter] string $password): array
    {
        $caBundlePath = $this->caBundleBuilder->currentBundlePath();
        $userClient = new LdapClient($domain->config, $caBundlePath);
        $serviceClient = null;

        try {
            $userClient->connect();
            $userClient->bind($samAccountName . '@' . $domain->config->domain, $password);

            $lookup = $userClient;
            $lookupMode = 'user';
            if ($domain->hasServiceAccount()) {
                try {
                    $serviceClient = new LdapClient($domain->config, $caBundlePath);
                    $serviceClient->connect();
                    $serviceClient->bind(
                        self::servicePrincipal((string) $domain->serviceUsername, $domain->config->domain),
                        (string) $this->domains->servicePassword($domain->id()),
                    );
                    $lookup = $serviceClient;
                    $lookupMode = 'service';
                } catch (\Throwable $e) {
                    // Сервисная учётка недоступна — не блокируем вход, а
                    // проверяем группы правами самого пользователя.
                    $serviceClient = null;
                    $this->diagnosticLogger?->warning(
                        DiagnosticSource::CHAT_AUTH,
                        'service_account_bind_failed',
                        'Сервисная учётка домена не прошла bind, членство проверяется правами пользователя.',
                        ['domain' => $domain->config->name, 'error' => $e instanceof LdapException ? $e->errorCode : get_class($e)],
                    );
                }
            }

            $userDn = $lookup->findUserDnBySamAccountName($samAccountName);
            if ($userDn === null) {
                return ['user_dn' => null, 'groups' => [], 'unresolved' => [], 'lookup' => $lookupMode];
            }

            $matched = [];
            $unresolved = [];
            foreach ($this->groups->forDomain($domain->id(), true) as $group) {
                $ref = (string) $group['group_ref'];
                if (($group['subject_type'] ?? DomainGroupRepository::SUBJECT_GROUP) === DomainGroupRepository::SUBJECT_USER) {
                    // «Группа из одного» — прямое сравнение sAMAccountName,
                    // без обращения к группам AD.
                    if (strcasecmp($ref, $samAccountName) === 0) {
                        $matched[(int) $group['id']] = (string) $group['display_name'];
                    }
                    continue;
                }
                $groupDn = DomainGroupRepository::isDistinguishedName($ref) ? $ref : $lookup->findGroupDnByCn($ref);
                if ($groupDn === null) {
                    $unresolved[] = (string) $group['display_name'];
                    continue;
                }
                if ($lookup->isMemberOfGroupRecursive($userDn, $groupDn)) {
                    $matched[(int) $group['id']] = (string) $group['display_name'];
                }
            }

            if ($unresolved !== []) {
                $this->diagnosticLogger?->warning(
                    DiagnosticSource::CHAT_AUTH,
                    'group_not_resolved',
                    'Часть разрешённых групп не найдена в каталоге по CN.',
                    ['domain' => $domain->config->name, 'groups' => implode(', ', $unresolved)],
                );
            }

            return ['user_dn' => $userDn, 'groups' => $matched, 'unresolved' => $unresolved, 'lookup' => $lookupMode];
        } finally {
            $userClient->close();
            $serviceClient?->close();
            $password = str_repeat("\0", strlen($password));
            unset($password);
        }
    }

    /**
     * Проверка групп домена из админки под сервисной учёткой: существует ли
     * каждая группа и во что разрешается CN.
     *
     * @return array<int,array{id:int,display_name:string,group_ref:string,ok:bool,dn:?string}>
     * @throws LdapException
     */
    public function verifyGroups(LlmDomain $domain): array
    {
        if (!$domain->hasServiceAccount()) {
            throw new LdapException('SERVICE_ACCOUNT_MISSING', 'Для проверки групп задайте сервисную учётную запись домена.');
        }

        $client = new LdapClient($domain->config, $this->caBundleBuilder->currentBundlePath());
        try {
            $client->connect();
            $client->bind(
                self::servicePrincipal((string) $domain->serviceUsername, $domain->config->domain),
                (string) $this->domains->servicePassword($domain->id()),
            );

            $result = [];
            foreach ($this->groups->forDomain($domain->id()) as $group) {
                $ref = (string) $group['group_ref'];
                if (($group['subject_type'] ?? DomainGroupRepository::SUBJECT_GROUP) === DomainGroupRepository::SUBJECT_USER) {
                    // Для пользователя «найдено в каталоге» означает наличие
                    // учётной записи с таким sAMAccountName, а не группы.
                    $dn = $client->findUserDnBySamAccountName($ref);
                } elseif (DomainGroupRepository::isDistinguishedName($ref)) {
                    $dn = $client->groupExists($ref) ? $ref : null;
                } else {
                    $dn = $client->findGroupDnByCn($ref);
                }
                $result[] = [
                    'id' => (int) $group['id'],
                    'display_name' => (string) $group['display_name'],
                    'group_ref' => $ref,
                    'ok' => $dn !== null,
                    'dn' => $dn,
                ];
            }

            return $result;
        } finally {
            $client->close();
        }
    }

    /**
     * Разбор введённого логина: "user", "DOMAIN\user" или "user@domain.local".
     *
     * @return array{sam:string,domain_hint:?string}|null null — недопустимый формат
     */
    public static function parseLogin(string $login): ?array
    {
        $login = trim($login);
        $hint = null;
        if (str_contains($login, '\\')) {
            [$hint, $login] = explode('\\', $login, 2);
        } elseif (str_contains($login, '@')) {
            [$login, $hint] = explode('@', $login, 2);
        }
        if ($login === '' || mb_strlen($login) > 64 || preg_match('/[\s"\/\\\\\[\]:;|=,+*?<>@]/u', $login) === 1) {
            return null;
        }

        return ['sam' => $login, 'domain_hint' => $hint !== null && $hint !== '' ? mb_strtolower($hint) : null];
    }

    public static function servicePrincipal(string $serviceUsername, string $domain): string
    {
        return str_contains($serviceUsername, '@') ? $serviceUsername : $serviceUsername . '@' . $domain;
    }
}
