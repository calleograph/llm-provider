<?php

declare(strict_types=1);

namespace App\Certificate;

use App\Config\Paths;
use RuntimeException;

/**
 * Собирает собственный CA bundle приложения (var/certs/ca-bundle.pem) из всех
 * загруженных администратором сертификатов. НЕ трогает системный trust store ОС.
 * Этот bundle используется исключительно LDAP-клиентом при подключении к AD.
 */
final class CaBundleBuilder
{
    private const BUNDLE_FILENAME = 'ca-bundle.pem';

    public function __construct(private readonly CertificateRepository $repository)
    {
    }

    public function currentBundlePath(): string
    {
        $path = Paths::certs() . '/' . self::BUNDLE_FILENAME;
        if (!is_file($path)) {
            $this->rebuild();
        }

        return $path;
    }

    public function rebuild(): string
    {
        Paths::ensureDirectories();
        $certs = $this->repository->all();

        $bundle = '';
        foreach ($certs as $cert) {
            $bundle .= $this->repository->readPem($cert) . "\n";
        }

        $path = Paths::certs() . '/' . self::BUNDLE_FILENAME;

        if ($bundle === '') {
            // Нет ни одного загруженного сертификата — bundle не создаём,
            // TLS-проверка тогда закономерно завершится ошибкой (fail-closed).
            if (is_file($path)) {
                unlink($path);
            }
            throw new RuntimeException('Не загружено ни одного доверенного CA-сертификата.');
        }

        if (file_put_contents($path, $bundle, LOCK_EX) === false) {
            throw new RuntimeException('Не удалось записать CA bundle.');
        }
        chmod($path, 0640);

        return $path;
    }
}
