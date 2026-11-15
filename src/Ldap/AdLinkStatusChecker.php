<?php

declare(strict_types=1);

namespace App\Ldap;

use App\Certificate\CaBundleBuilder;

/**
 * Минимальная безопасная проверка «жив ли LDAPS» для публичного маркера на
 * главной странице и для страницы «Состояние» в админке. Никаких учётных
 * данных пользователя — только DNS, TCP/636 и валидность TLS-сертификата
 * через собственный CA bundle (форсируется реальным anonymous bind, так как
 * ldap_connect() в PHP ленивый и сам по себе TLS handshake не выполняет).
 * Не принимает никакого пользовательского ввода (адрес и порт берутся из
 * активной конфигурации, заданной администратором), поэтому не может быть
 * использована для обращения к произвольному хосту.
 *
 * Делает до 2 попыток перед тем, как признать связь недоступной — единичная
 * кратковременная сетевая задержка (или медленный ответ AD под нагрузкой) не
 * должна давать ложный "нет связи", особенно с учётом того, что результат
 * кэшируется на стороне вызывающего кода (см. AdLinkStatusCache).
 */
final class AdLinkStatusChecker
{
    private const TCP_TIMEOUT_SECONDS = 5;
    private const MAX_ATTEMPTS = 2;

    public function __construct(private readonly CaBundleBuilder $caBundleBuilder)
    {
    }

    public function isReachable(LdapConnectionConfig $config): bool
    {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            if ($this->checkOnce($config)) {
                return true;
            }
        }

        return false;
    }

    private function checkOnce(LdapConnectionConfig $config): bool
    {
        $ip = @gethostbyname($config->host);
        if ($ip === $config->host && filter_var($config->host, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        $socket = @fsockopen($config->host, $config->port, $errno, $errstr, self::TCP_TIMEOUT_SECONDS);
        if ($socket === false) {
            return false;
        }
        fclose($socket);

        try {
            $caBundlePath = $this->caBundleBuilder->currentBundlePath();
        } catch (\Throwable) {
            return false;
        }

        $client = new LdapClient($config, $caBundlePath);

        try {
            $client->connect();

            // ldap_connect() не выполняет реального сетевого/TLS-подключения —
            // это происходит лениво при первой настоящей операции. Anonymous
            // bind форсирует TLS handshake: если сертификат AD не проходит
            // проверку через наш CA bundle, LdapClient::bind() бросит
            // LdapException с кодом TLS_ERROR/SERVER_UNAVAILABLE/TIMEOUT —
            // это и означает "связи нет". Любой другой исход (успех или отказ
            // по причине, не связанной с TLS, например анонимный bind просто
            // запрещён политикой AD) означает, что TLS-уровень был пройден.
            try {
                $client->bind('', '');

                return true;
            } catch (LdapException $e) {
                return !in_array($e->errorCode, [LdapErrorCode::TLS_ERROR, LdapErrorCode::SERVER_UNAVAILABLE, LdapErrorCode::TIMEOUT], true);
            }
        } catch (\Throwable) {
            return false;
        } finally {
            $client->close();
        }
    }
}
