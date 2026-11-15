<?php

declare(strict_types=1);

namespace App\Llm\Provider;

use App\Security\SecretBox;
use PDO;
use RuntimeException;

/**
 * CRUD подключений к LLM (таблица llm_providers). API-ключ и доп. заголовки
 * шифруются SecretBox (тот же master key, что у остальных секретов проекта)
 * и наружу из репозитория в открытом виде отдаются только в ProviderConfig
 * для LLM Gateway. Списки для админки/чата секретов не содержат.
 */
final class ProviderRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<int,array<string,mixed>> строки без секретов */
    public function all(): array
    {
        return $this->pdo->query(
            'SELECT id, name, provider_type, base_url, endpoint, api_key_hint, model, api_version, connect_timeout,
                    response_timeout, max_tokens, extra_header_names, routing_provider, sort_by_price,
                    max_price_per_mtoken, allow_fallbacks, is_default, use_corporate_ca, is_enabled,
                    last_check_at, last_check_ok, last_check_message, created_at, updated_at,
                    (api_key_encrypted IS NOT NULL AND api_key_encrypted != \'\') AS has_api_key
             FROM llm_providers ORDER BY name'
        )->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        foreach ($this->all() as $row) {
            if ((int) $row['id'] === $id) {
                return $row;
            }
        }

        return null;
    }

    /** @return array<int,string> id => name */
    public function names(): array
    {
        $result = [];
        foreach ($this->pdo->query('SELECT id, name FROM llm_providers ORDER BY name')->fetchAll() as $row) {
            $result[(int) $row['id']] = (string) $row['name'];
        }

        return $result;
    }

    public function nameExists(string $name, ?int $excludeId = null): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM llm_providers WHERE name = :name AND id != :id');
        $stmt->execute(['name' => $name, 'id' => $excludeId ?? 0]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * @param array<string,mixed> $data поля из ProviderValidator::normalize()
     * @param array<string,string>|null $extraHeaders
     */
    public function create(array $data, string $apiKey, ?array $extraHeaders): int
    {
        $now = gmdate('c');
        $stmt = $this->pdo->prepare(<<<SQL
            INSERT INTO llm_providers
                (name, provider_type, base_url, endpoint, api_key_encrypted, api_key_hint, model, api_version,
                 connect_timeout, response_timeout, max_tokens, extra_headers_encrypted, extra_header_names,
                 routing_provider, sort_by_price, max_price_per_mtoken, allow_fallbacks, use_corporate_ca, is_enabled, created_at, updated_at)
            VALUES
                (:name, :provider_type, :base_url, :endpoint, :api_key_encrypted, :api_key_hint, :model, :api_version,
                 :connect_timeout, :response_timeout, :max_tokens, :extra_headers_encrypted, :extra_header_names,
                 :routing_provider, :sort_by_price, :max_price_per_mtoken, :allow_fallbacks, :use_corporate_ca, :is_enabled, :now, :now)
        SQL);
        $stmt->execute($this->bind($data) + [
            'api_key_encrypted' => $apiKey !== '' ? SecretBox::encrypt($apiKey) : null,
            'api_key_hint' => self::hint($apiKey),
            'extra_headers_encrypted' => $extraHeaders ? SecretBox::encrypt(json_encode($extraHeaders, JSON_THROW_ON_ERROR)) : null,
            'extra_header_names' => $extraHeaders ? implode(', ', array_keys($extraHeaders)) : null,
            'now' => $now,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        \App\Llm\LlmSchema::seedDefaultModels($this->pdo);

        return $id;
    }

    /**
     * @param array<string,mixed> $data
     * @param string|null $newApiKey null — оставить сохранённый ключ
     * @param array<string,string>|null|false $extraHeaders false — не менять, null/[] — очистить
     */
    public function update(int $id, array $data, ?string $newApiKey, array|null|false $extraHeaders): void
    {
        $sets = 'name = :name, provider_type = :provider_type, base_url = :base_url, endpoint = :endpoint, model = :model,
                 api_version = :api_version, connect_timeout = :connect_timeout, response_timeout = :response_timeout,
                 max_tokens = :max_tokens, routing_provider = :routing_provider, sort_by_price = :sort_by_price,
                 max_price_per_mtoken = :max_price_per_mtoken, allow_fallbacks = :allow_fallbacks, use_corporate_ca = :use_corporate_ca,
                 is_enabled = :is_enabled, updated_at = :now';
        $params = $this->bind($data) + ['id' => $id, 'now' => gmdate('c')];

        if ($newApiKey !== null && $newApiKey !== '') {
            $sets .= ', api_key_encrypted = :api_key_encrypted, api_key_hint = :api_key_hint';
            $params['api_key_encrypted'] = SecretBox::encrypt($newApiKey);
            $params['api_key_hint'] = self::hint($newApiKey);
        }
        if ($extraHeaders !== false) {
            $sets .= ', extra_headers_encrypted = :extra_headers_encrypted, extra_header_names = :extra_header_names';
            $params['extra_headers_encrypted'] = $extraHeaders ? SecretBox::encrypt(json_encode($extraHeaders, JSON_THROW_ON_ERROR)) : null;
            $params['extra_header_names'] = $extraHeaders ? implode(', ', array_keys($extraHeaders)) : null;
        }

        $stmt = $this->pdo->prepare("UPDATE llm_providers SET {$sets} WHERE id = :id");
        $stmt->execute($params);
        // Модель провайдера по умолчанию следует полям «Модель» и «Макс. токенов ответа» формы провайдера.
        $this->pdo->prepare('UPDATE llm_models SET model = :model, display_name = CASE WHEN display_name = model THEN :model ELSE display_name END,
                             max_output_tokens = :max_tokens, updated_at = :now WHERE provider_id = :id AND is_default = 1
                             AND NOT EXISTS (SELECT 1 FROM llm_models o WHERE o.provider_id = :id AND o.model = :model AND o.is_default = 0)')
            ->execute(['model' => $params['model'], 'max_tokens' => $params['max_tokens'], 'now' => $params['now'], 'id' => $id]);
        \App\Llm\LlmSchema::seedDefaultModels($this->pdo);
    }

    public function setEnabled(int $id, bool $enabled): void
    {
        $stmt = $this->pdo->prepare('UPDATE llm_providers SET is_enabled = :enabled, updated_at = :now WHERE id = :id');
        $stmt->execute(['enabled' => $enabled ? 1 : 0, 'now' => gmdate('c'), 'id' => $id]);
    }

    /**
     * Нейросеть, которая открывается в чате по умолчанию вместо первой по
     * алфавиту (см. AccessPolicy::allowedProviders()). Ровно одна строка
     * может быть is_default = 1 — сброс остальных и установка новой в одной
     * операции (не транзакция: SQLite выполняет каждый exec атомарно, а
     * между ними эта колонка нигде больше не читается в рамках одного
     * HTTP-запроса).
     */
    public function setDefault(int $id): void
    {
        $now = gmdate('c');
        $this->pdo->exec('UPDATE llm_providers SET is_default = 0, updated_at = ' . $this->pdo->quote($now) . ' WHERE is_default = 1');
        $stmt = $this->pdo->prepare('UPDATE llm_providers SET is_default = 1, updated_at = :now WHERE id = :id');
        $stmt->execute(['now' => $now, 'id' => $id]);
    }

    /** Снимает флаг «по умолчанию» со всех нейросетей (переключатель формы — выключить и ничего не выбрать взамен). */
    public function clearDefault(): void
    {
        $this->pdo->exec('UPDATE llm_providers SET is_default = 0, updated_at = ' . $this->pdo->quote(gmdate('c')) . ' WHERE is_default = 1');
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM llm_providers WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function recordCheck(int $id, bool $ok, string $message): void
    {
        $stmt = $this->pdo->prepare('UPDATE llm_providers SET last_check_at = :at, last_check_ok = :ok, last_check_message = :msg WHERE id = :id');
        $stmt->execute(['at' => gmdate('c'), 'ok' => $ok ? 1 : 0, 'msg' => mb_substr($message, 0, 300), 'id' => $id]);
    }

    /**
     * Полная конфигурация с расшифрованными секретами — только для LLM Gateway.
     */
    public function loadForGateway(int $id): ?ProviderConfig
    {
        $stmt = $this->pdo->prepare('SELECT * FROM llm_providers WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        try {
            $apiKey = $row['api_key_encrypted'] ? SecretBox::decrypt((string) $row['api_key_encrypted']) : '';
            $headers = $row['extra_headers_encrypted']
                ? json_decode(SecretBox::decrypt((string) $row['extra_headers_encrypted']), true, 8, JSON_THROW_ON_ERROR)
                : [];
        } catch (\Throwable) {
            throw new RuntimeException('Не удалось расшифровать секреты подключения (проверьте master.key).');
        }

        return new ProviderConfig(
            id: (int) $row['id'],
            name: (string) $row['name'],
            type: (string) $row['provider_type'],
            baseUrl: (string) $row['base_url'],
            endpoint: (string) $row['endpoint'],
            apiKey: $apiKey,
            model: (string) $row['model'],
            apiVersion: $row['api_version'] !== null && $row['api_version'] !== '' ? (string) $row['api_version'] : null,
            connectTimeout: (int) $row['connect_timeout'],
            responseTimeout: (int) $row['response_timeout'],
            maxTokens: (int) $row['max_tokens'],
            extraHeaders: is_array($headers) ? array_map('strval', $headers) : [],
            useCorporateCa: (bool) $row['use_corporate_ca'],
            isEnabled: (bool) $row['is_enabled'],
            routingProvider: isset($row['routing_provider']) && $row['routing_provider'] !== '' ? (string) $row['routing_provider'] : null,
            sortByPrice: (bool) ($row['sort_by_price'] ?? false),
            maxPricePerMtoken: isset($row['max_price_per_mtoken']) && $row['max_price_per_mtoken'] !== null ? (float) $row['max_price_per_mtoken'] : null,
            allowFallbacks: (bool) ($row['allow_fallbacks'] ?? true),
        );
    }

    /** @param array<string,mixed> $data */
    private function bind(array $data): array
    {
        return [
            'name' => $data['name'],
            'provider_type' => $data['provider_type'],
            'base_url' => $data['base_url'],
            'endpoint' => $data['endpoint'],
            'model' => $data['model'],
            'api_version' => $data['api_version'] !== '' ? $data['api_version'] : null,
            'connect_timeout' => $data['connect_timeout'],
            'response_timeout' => $data['response_timeout'],
            'max_tokens' => $data['max_tokens'],
            'routing_provider' => $data['routing_provider'] !== '' ? $data['routing_provider'] : null,
            'sort_by_price' => $data['sort_by_price'] ? 1 : 0,
            'max_price_per_mtoken' => $data['max_price_per_mtoken'] !== '' ? (float) $data['max_price_per_mtoken'] : null,
            'allow_fallbacks' => $data['allow_fallbacks'] ? 1 : 0,
            'use_corporate_ca' => $data['use_corporate_ca'] ? 1 : 0,
            'is_enabled' => $data['is_enabled'] ? 1 : 0,
        ];
    }

    private static function hint(string $apiKey): ?string
    {
        if ($apiKey === '') {
            return null;
        }

        return mb_strlen($apiKey) >= 12 ? '…' . mb_substr($apiKey, -4) : '…';
    }
}
