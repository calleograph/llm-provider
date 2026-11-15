<?php

declare(strict_types=1);

namespace App\Llm\Http;

/**
 * HTTP-клиент на ext-curl с жёсткими настройками безопасности:
 *  - проверка TLS-сертификата и имени хоста всегда включена;
 *  - редиректы не выполняются (данные не уходят на непредусмотренный адрес);
 *  - только https:// (http:// — лишь при явном разрешении администратора
 *    для изолированной сети сервиса обезличивания);
 *  - размер ответа ограничен.
 */
final class CurlHttpTransport implements HttpTransport
{
    public function send(HttpRequest $request): HttpResponse
    {
        if (!function_exists('curl_init')) {
            throw new HttpTransportException('other', 'ext-curl не установлено на сервере.');
        }

        $scheme = strtolower((string) parse_url($request->url, PHP_URL_SCHEME));
        if ($scheme !== 'https' && !($scheme === 'http' && $request->allowPlainHttp)) {
            throw new HttpTransportException('scheme', 'Разрешены только https:// адреса.');
        }

        $handle = curl_init();
        if ($handle === false) {
            throw new HttpTransportException('other', 'curl_init вернул false.');
        }

        $headerLines = [];
        foreach ($request->headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        // Без "Expect: 100-continue" для больших тел.
        $headerLines[] = 'Expect:';

        $buffer = '';
        $tooLarge = false;
        $limit = $request->maxResponseBytes;

        $options = [
            CURLOPT_URL => $request->url,
            CURLOPT_CUSTOMREQUEST => $request->method,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_CONNECTTIMEOUT => max(1, $request->connectTimeout),
            CURLOPT_TIMEOUT => max(1, $request->timeout),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => $request->allowPlainHttp ? (CURLPROTO_HTTPS | CURLPROTO_HTTP) : CURLPROTO_HTTPS,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$buffer, &$tooLarge, $limit): int {
                if (strlen($buffer) + strlen($chunk) > $limit) {
                    $tooLarge = true;

                    return 0; // прерывает передачу
                }
                $buffer .= $chunk;

                return strlen($chunk);
            },
        ];
        if (defined('CURLOPT_REDIR_PROTOCOLS')) {
            $options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTPS;
        }
        if ($request->body !== null) {
            // Массив → multipart/form-data (CURLFile читается curl напрямую с диска).
            $options[CURLOPT_POSTFIELDS] = $request->body;
        }
        if ($request->caBundlePath !== null) {
            if (!is_file($request->caBundlePath)) {
                curl_close($handle);
                throw new HttpTransportException('tls', 'CA bundle приложения не найден — загрузите сертификаты в разделе «Сертификаты».');
            }
            $options[CURLOPT_CAINFO] = $request->caBundlePath;
        }

        curl_setopt_array($handle, $options);

        $started = microtime(true);
        $ok = curl_exec($handle);
        $durationMs = (int) round((microtime(true) - $started) * 1000);
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if ($tooLarge) {
            throw new HttpTransportException('too_large', 'Ответ сервера превысил допустимый размер.');
        }
        if ($ok === false || $errno !== 0) {
            throw new HttpTransportException(self::classify($errno), sprintf('curl errno=%d: %s', $errno, $error));
        }

        return new HttpResponse($status, $buffer, $durationMs);
    }

    private static function classify(int $errno): string
    {
        return match ($errno) {
            CURLE_OPERATION_TIMEDOUT => 'timeout',
            CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_RESOLVE_PROXY => 'dns',
            CURLE_COULDNT_CONNECT => 'connect',
            CURLE_SSL_CONNECT_ERROR, CURLE_PEER_FAILED_VERIFICATION, CURLE_SSL_CACERT_BADFILE => 'tls',
            CURLE_UNSUPPORTED_PROTOCOL => 'scheme',
            default => 'other',
        };
    }
}
