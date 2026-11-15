<?php

declare(strict_types=1);

namespace App\Llm\Http;

final class HttpRequest
{
    /**
     * @param array<string,string> $headers
     * @param string|array<string,string|\CURLFile>|null $body массив — multipart/form-data
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers = [],
        public readonly string|array|null $body = null,
        public readonly int $connectTimeout = 10,
        public readonly int $timeout = 60,
        // null — системное хранилище CA (публичные API). Путь — только
        // собственный CA bundle приложения (внутренние сервисы).
        public readonly ?string $caBundlePath = null,
        public readonly bool $allowPlainHttp = false,
        public readonly int $maxResponseBytes = 8 * 1024 * 1024,
    ) {
    }

    /**
     * multipart/form-data (загрузка файла). Content-Type с boundary
     * формирует curl.
     *
     * @param array<string,string|\CURLFile> $fields
     * @param array<string,string> $headers
     */
    public static function multipart(string $url, array $fields, array $headers, int $connectTimeout, int $timeout, ?string $caBundlePath = null, bool $allowPlainHttp = false): self
    {
        return new self('POST', $url, ['Accept' => 'application/json'] + $headers, $fields, $connectTimeout, $timeout, $caBundlePath, $allowPlainHttp);
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,string> $headers
     */
    public static function json(string $url, array $payload, array $headers, int $connectTimeout, int $timeout, ?string $caBundlePath = null, bool $allowPlainHttp = false): self
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return new self(
            'POST',
            $url,
            ['Content-Type' => 'application/json', 'Accept' => 'application/json'] + $headers,
            $body,
            $connectTimeout,
            $timeout,
            $caBundlePath,
            $allowPlainHttp,
        );
    }
}
