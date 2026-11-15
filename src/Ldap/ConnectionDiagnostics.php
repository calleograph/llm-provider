<?php

declare(strict_types=1);

namespace App\Ldap;

use App\Certificate\CaBundleBuilder;
use App\Certificate\CertificateRepository;
use App\Http\ErrorMessages;

/**
 * Подробная пошаговая диагностика подключения для кнопки «Проверить
 * подключение» в админке. Возвращает факты и точные (но безопасные —
 * без паролей и без полного LDAP diagnostic trace) коды каждого шага,
 * чтобы администратор видел, на каком именно этапе рвётся цепочка:
 * DNS -> TCP -> TLS handshake -> предъявленный сертификат сервера ->
 * (опционально) LDAP bind.
 */
final class ConnectionDiagnostics
{
    public function __construct(
        private readonly CaBundleBuilder $caBundleBuilder,
        private readonly ?CertificateRepository $certificateRepository = null,
    ) {
    }

    /**
     * @return array{
     *   dns_resolved:bool, resolved_ip:?string,
     *   tcp_reachable:bool, tcp_error:?string,
     *   ca_bundle_ready:bool, ca_bundle_error:?string,
     *   peer_certificate:?array{subject:string,issuer:string,valid_from:string,valid_to:string,expired:bool},
     *   peer_certificate_error:?string,
     *   tls_valid:bool, chain_valid:bool, tls_error_code:?string, tls_error_message:?string,
     *   bind_tested:bool, bind_successful:?bool, bind_error_code:?string, bind_error_message:?string,
     *   steps:array<int,array{label:string,ok:bool,detail:string}>
     * }
     */
    public function check(LdapConnectionConfig $config, ?string $testUsername, ?string $testPassword): array
    {
        $result = [
            'dns_resolved' => false,
            'resolved_ip' => null,
            'tcp_reachable' => false,
            'tcp_error' => null,
            'ca_bundle_ready' => false,
            'ca_bundle_error' => null,
            'peer_certificate' => null,
            'peer_certificate_error' => null,
            'tls_valid' => false,
            'chain_valid' => false,
            'tls_error_code' => null,
            'tls_error_message' => null,
            'bind_tested' => false,
            'bind_successful' => null,
            'bind_error_code' => null,
            'bind_error_message' => null,
            'steps' => [],
        ];

        $host = $config->host;

        // 1. DNS
        $ip = @gethostbyname($host);
        $result['dns_resolved'] = $ip !== $host || filter_var($host, FILTER_VALIDATE_IP) !== false;
        $result['resolved_ip'] = $result['dns_resolved'] ? $ip : null;
        $result['steps'][] = [
            'label' => 'DNS-разрешение имени ' . $host,
            'ok' => $result['dns_resolved'],
            'detail' => $result['dns_resolved'] ? ('Разрешён в ' . $ip) : 'Имя не разрешается в IP-адрес.',
        ];
        if (!$result['dns_resolved']) {
            return $result;
        }

        // 2. TCP
        $errno = 0;
        $errstr = '';
        $timeout = max(1, min($config->networkTimeout, 5));
        $socket = @fsockopen($host, $config->port, $errno, $errstr, $timeout);
        $result['tcp_reachable'] = $socket !== false;
        if ($socket === false) {
            $result['tcp_error'] = trim($errstr) !== '' ? $errstr : ('errno ' . $errno);
            $result['steps'][] = [
                'label' => 'TCP-подключение к ' . $host . ':' . $config->port,
                'ok' => false,
                'detail' => 'Порт недоступен (' . $result['tcp_error'] . '). Проверьте firewall/маршрутизацию и что AD слушает LDAPS на этом порту.',
            ];

            return $result;
        }
        fclose($socket);
        $result['steps'][] = [
            'label' => 'TCP-подключение к ' . $host . ':' . $config->port,
            'ok' => true,
            'detail' => 'Порт открыт.',
        ];

        // 3. CA bundle приложения готов
        try {
            $caBundlePath = $this->caBundleBuilder->currentBundlePath();
            $result['ca_bundle_ready'] = true;
            $result['steps'][] = [
                'label' => 'Собственный CA bundle приложения',
                'ok' => true,
                'detail' => 'Собран из загруженных сертификатов, используется для проверки TLS.',
            ];
        } catch (\Throwable $e) {
            $result['ca_bundle_error'] = $e->getMessage();
            $result['steps'][] = [
                'label' => 'Собственный CA bundle приложения',
                'ok' => false,
                'detail' => 'Не загружено ни одного доверенного сертификата — загрузите Root/Intermediate CA в разделе «Сертификаты».',
            ];

            return $result;
        }

        // 4. Реальный TLS handshake + чтение сертификата, который предъявляет
        // сервер, — НАПРЯМУЮ через stream_socket_client с TLS, независимо от
        // LDAP-слоя. Это не влияет на решение о доверии (его принимает
        // ext-ldap с нашим CA bundle отдельно на шаге 5), а только показывает
        // администратору, что именно прислал сервер, для сравнения с
        // загруженным CA — полезно при диагностике "не тот сертификат/не та
        // цепочка".
        $peerCert = $this->readPeerCertificate($host, $config->port, $timeout);
        if ($peerCert !== null) {
            $result['peer_certificate'] = $peerCert;

            // Подсказка (не решающая проверка!): есть ли среди ЗАГРУЖЕННЫХ
            // сертификатов такой, чей Subject текстуально похож на Issuer
            // предъявленного сертификата. Сравнение DN как строк ненадёжно —
            // OpenSSL может по-разному форматировать многозначные RDN
            // (например DC=local+DC=example) при разборе одного и того же
            // сертификата в разных вызовах, поэтому НЕСОВПАДЕНИЕ здесь НЕ
            // считается ошибкой и не влияет на итоговый статус шага.
            // Единственный источник истины о валидности цепочки — реальная
            // криптографическая проверка на шаге 5 (ext-ldap TLS handshake
            // с нашим CA bundle), а не это текстовое сравнение.
            $issuerFound = $this->issuerIsAmongUploadedCertificates($peerCert['issuer']);
            $issuerDetail = $issuerFound
                ? ' Издатель похож на один из загруженных сертификатов.'
                : ' Издатель ("' . $peerCert['issuer'] . '") текстуально не совпал ни с одним загруженным Subject — '
                    . 'это не обязательно ошибка (см. итоговый результат TLS handshake ниже), но если он окажется '
                    . 'отрицательным, скорее всего не хватает Intermediate CA в цепочке.';

            $result['steps'][] = [
                'label' => 'Сертификат, предъявленный сервером',
                'ok' => !$peerCert['expired'],
                'detail' => sprintf(
                    'Subject: %s; Issuer: %s; действует до %s%s%s',
                    $peerCert['subject'] !== '' ? $peerCert['subject'] : '(пусто — обычно для сертификатов контроллеров домена, имя указано в SAN)',
                    $peerCert['issuer'],
                    $peerCert['valid_to'],
                    $peerCert['expired'] ? ' — СРОК ИСТЁК' : '',
                    $issuerDetail,
                ),
            ];
        } else {
            $result['peer_certificate_error'] = 'Не удалось прочитать сертификат сервера напрямую (сеть может блокировать «сырой» TLS вне LDAP-порта или сервер требует SNI).';
            $result['steps'][] = [
                'label' => 'Сертификат, предъявленный сервером',
                'ok' => false,
                'detail' => $result['peer_certificate_error'] . ' Это не обязательно ошибка — LDAP-проверка ниже всё равно выполняется.',
            ];
        }

        // 5. TLS-проверка глазами ext-ldap (это то, что реально решает, будет
        // ли работать LDAPS-подключение) + опциональный bind-тест.
        $client = new LdapClient($config, $caBundlePath);
        try {
            $client->connect();

            try {
                // ldap_connect() ленив — не выполняет реального сетевого
                // подключения. Anonymous bind форсирует настоящий TLS handshake
                // и проверку сертификата нашим CA bundle.
                $client->bind('', '');
                $result['tls_valid'] = true;
                $result['chain_valid'] = true;
                $result['steps'][] = [
                    'label' => 'TLS handshake и проверка цепочки сертификатов (ext-ldap)',
                    'ok' => true,
                    'detail' => 'Сертификат сервера принят нашим CA bundle.',
                ];
            } catch (LdapException $e) {
                if (in_array($e->errorCode, [LdapErrorCode::TLS_ERROR, LdapErrorCode::SERVER_UNAVAILABLE, LdapErrorCode::TIMEOUT], true)) {
                    $result['tls_valid'] = false;
                    $result['chain_valid'] = false;
                    $result['tls_error_code'] = $e->errorCode;
                    $result['tls_error_message'] = ErrorMessages::forCode($e->errorCode);
                    $result['steps'][] = [
                        'label' => 'TLS handshake и проверка цепочки сертификатов (ext-ldap)',
                        'ok' => false,
                        'detail' => 'Сертификат сервера НЕ принят нашим CA bundle, либо TLS handshake не завершился. '
                            . 'Сверьте Subject/Issuer сертификата выше с загруженными CA в разделе «Сертификаты» — '
                            . 'скорее всего загружен не тот CA либо не хватает Intermediate CA в цепочке.',
                    ];
                } else {
                    // Сервер ответил осмысленным LDAP-кодом — значит TLS уже пройден.
                    $result['tls_valid'] = true;
                    $result['chain_valid'] = true;
                    $result['steps'][] = [
                        'label' => 'TLS handshake и проверка цепочки сертификатов (ext-ldap)',
                        'ok' => true,
                        'detail' => 'Сертификат принят (анонимный bind отклонён политикой AD, что ожидаемо и не является TLS-ошибкой).',
                    ];
                }
            }

            if ($result['tls_valid'] && $testUsername !== null && $testPassword !== null && $testUsername !== '' && $testPassword !== '') {
                $result['bind_tested'] = true;
                try {
                    $principal = $testUsername . '@' . $config->domain;
                    $client->bind($principal, $testPassword);
                    $result['bind_successful'] = true;
                    $result['steps'][] = [
                        'label' => 'Тестовый LDAP bind (' . $testUsername . ')',
                        'ok' => true,
                        'detail' => 'Учётные данные приняты.',
                    ];
                } catch (LdapException $e) {
                    $result['bind_successful'] = false;
                    $result['bind_error_code'] = $e->errorCode;
                    $result['bind_error_message'] = ErrorMessages::forCode($e->errorCode);
                    $result['steps'][] = [
                        'label' => 'Тестовый LDAP bind (' . $testUsername . ')',
                        'ok' => false,
                        'detail' => $result['bind_error_message'],
                    ];
                } finally {
                    $testPassword = str_repeat("\0", strlen($testPassword));
                    unset($testPassword);
                }
            }

            $client->close();
        } catch (\Throwable $e) {
            $result['tls_valid'] = false;
            $result['chain_valid'] = false;
            $result['steps'][] = [
                'label' => 'TLS handshake и проверка цепочки сертификатов (ext-ldap)',
                'ok' => false,
                'detail' => 'Непредвиденная ошибка при установке соединения.',
            ];
        }

        return $result;
    }

    /**
     * Читает сертификат, который сервер предъявляет на TLS-уровне, НЕ
     * полагаясь на доверие к нему (verify_peer выключен нарочно — здесь нам
     * нужно только УВИДЕТЬ сертификат для сравнения с загруженным CA,
     * решение о доверии принимает отдельно ext-ldap с нашим CA bundle).
     *
     * @return array{subject:string,issuer:string,valid_from:string,valid_to:string,expired:bool}|null
     */
    private function readPeerCertificate(string $host, int $port, int $timeoutSeconds): ?array
    {
        $context = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ],
        ]);

        $stream = @stream_socket_client(
            'ssl://' . $host . ':' . $port,
            $errno,
            $errstr,
            $timeoutSeconds,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if ($stream === false) {
            return null;
        }

        $params = stream_context_get_params($stream);
        fclose($stream);

        if (!isset($params['options']['ssl']['peer_certificate'])) {
            return null;
        }

        $parsed = openssl_x509_parse($params['options']['ssl']['peer_certificate']);
        if ($parsed === false) {
            return null;
        }

        $validTo = (int) ($parsed['validTo_time_t'] ?? 0);

        return [
            'subject' => self::formatDn($parsed['subject'] ?? []),
            'issuer' => self::formatDn($parsed['issuer'] ?? []),
            'valid_from' => gmdate('Y-m-d H:i:s', (int) ($parsed['validFrom_time_t'] ?? 0)) . ' UTC',
            'valid_to' => gmdate('Y-m-d H:i:s', $validTo) . ' UTC',
            'expired' => $validTo > 0 && $validTo < time(),
        ];
    }

    /**
     * Текстовое сравнение Issuer предъявленного сертификата с Subject каждого
     * загруженного сертификата. ЭТО ТОЛЬКО ПОДСКАЗКА, не решающая проверка:
     * OpenSSL может по-разному форматировать многозначные RDN (например,
     * DC=local и DC=example как один компонент "DC=local, DC=example" в одном
     * разборе и как "DC=local+DC=example" в другом) в зависимости от того, как
     * именно были прочитаны байты сертификата — напрямую из TLS-сокета или
     * из PEM-файла, — поэтому строки могут не совпасть даже для одного и
     * того же сертификата. Сравнение нормализуется (без учёта регистра,
     * пробелов и порядка компонентов через сортировку), чтобы поймать
     * максимум реальных совпадений, но при расхождении решающим остаётся
     * только фактический результат TLS handshake на шаге 5.
     */
    private function issuerIsAmongUploadedCertificates(string $issuer): bool
    {
        if ($this->certificateRepository === null) {
            return true; // Нет доступа к репозиторию — не показываем ложную тревогу.
        }

        $normalizedIssuer = self::normalizeDn($issuer);

        foreach ($this->certificateRepository->all() as $cert) {
            if (self::normalizeDn($cert['subject']) === $normalizedIssuer) {
                return true;
            }
        }

        return false;
    }

    /**
     * Приводит DN-строку к форме, устойчивой к типичным различиям
     * форматирования: регистр, порядок компонентов, пробелы вокруг
     * разделителей "+"/",".
     */
    private static function normalizeDn(string $dn): string
    {
        $parts = preg_split('/[,+]/', $dn) ?: [];
        $parts = array_map(static fn (string $p): string => mb_strtolower(trim($p)), $parts);
        sort($parts);

        return implode('|', $parts);
    }

    private static function formatDn(array $nameParts): string
    {
        $segments = [];
        foreach ($nameParts as $key => $value) {
            if (is_array($value)) {
                // Многозначный RDN (например DC=local + DC=example в одном
                // компоненте имени) — каждое значение получает собственный
                // ключ, а не склеивается без него, иначе на выходе получится
                // некорректный "DC=local+example" вместо "DC=local+DC=example".
                foreach ($value as $v) {
                    $segments[] = $key . '=' . $v;
                }
                continue;
            }
            $segments[] = $key . '=' . $value;
        }

        return implode(', ', $segments);
    }
}
