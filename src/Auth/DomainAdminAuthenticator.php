<?php

declare(strict_types=1);

namespace App\Auth;

use App\Certificate\CaBundleBuilder;
use App\Ldap\LdapClient;
use App\Ldap\LdapConnectionConfig;
use App\Ldap\LdapException;

/**
 * Второй способ входа в админку — доменная учётная запись, входящая
 * (прямо или через вложенные группы) в настроенную AD-группу администраторов.
 */
final class DomainAdminAuthenticator
{
    public function __construct(
        private readonly LdapConnectionConfig $config,
        private readonly CaBundleBuilder $caBundleBuilder,
    ) {
    }

    /**
     * @throws LdapException
     */
    public function authenticate(string $username, string $password): bool
    {
        $principal = trim($username) . '@' . $this->config->domain;
        $caBundlePath = $this->caBundleBuilder->currentBundlePath();

        $client = new LdapClient($this->config, $caBundlePath);
        try {
            $client->connect();
            $client->bind($principal, $password);

            $userDn = $client->findUserDnBySamAccountName(trim($username));
            if ($userDn === null) {
                return false;
            }

            return $client->isMemberOfGroupRecursive($userDn, $this->config->adminGroupDn);
        } finally {
            $client->close();
            $password = str_repeat("\0", strlen($password));
            unset($password);
        }
    }
}
