<?php

declare(strict_types=1);

namespace App\Llm\Dictation;

use App\Llm\Chat\ChatSettings;

/**
 * Голосовой ввод: одноразовый билет для WebSocket-шлюза диктовки в контейнере ASR
 * (PHP-FPM не держит WebSocket). Формат `base64url(json).base64url(HMAC-SHA256)`
 * совпадает с services/asr/app/ws_gateway.py::verify_ticket. В билете — ключ
 * пользователя (для лимита диктовок в час), срок 60 с, одноразовый номер и
 * настройки диктовки; шлюз проверяет подпись, срок и одноразовость.
 */
final class DictationTicket
{
    public const TTL = 60;

    public function __construct(private readonly ChatSettings $settings)
    {
    }

    /** Включено администратором и развёрнуто (контейнер ASR, секрет). */
    public function enabled(): bool
    {
        return $this->settings->get('dictation_enabled') === '1' && getenv('ASR_ENABLED') === '1' && self::secret() !== '';
    }

    public function maxSeconds(): int
    {
        return max(5, min(600, (int) $this->settings->get('dictation_max_seconds')));
    }

    /** Готова ли модель распознавания (без инференса): GET /readyz ASR во внутренней сети. */
    public function asrReady(): bool
    {
        $url = rtrim((string) getenv('ASR_URL'), '/');
        if ($url === '') {
            return false;
        }
        $ch = curl_init($url . '/readyz');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 3, CURLOPT_PROTOCOLS => CURLPROTO_HTTP]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        return $status === 200;
    }

    public function issue(string $ownerKey, ?int $now = null): string
    {
        $body = self::b64(json_encode([
            'u' => $ownerKey,
            'exp' => ($now ?? time()) + self::TTL,
            'n' => bin2hex(random_bytes(16)),
            'ms' => $this->maxSeconds(),
            'mc' => max(1, min(64, (int) $this->settings->get('dictation_max_concurrent'))),
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $body . '.' . self::b64(hash_hmac('sha256', $body, self::secret(), true));
    }

    private static function secret(): string
    {
        return (string) getenv('DICTATION_SECRET');
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
