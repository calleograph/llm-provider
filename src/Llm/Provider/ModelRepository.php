<?php

declare(strict_types=1);

namespace App\Llm\Provider;

use PDO;

/**
 * Модели внутри провайдеров (llm_models): то, что выбирает пользователь и между чем переключается
 * резервирование. Доступ к модели — доступ к её провайдеру (группы AD и индивидуальные права,
 * AccessPolicy) плюс включённая модель и включённый провайдер.
 */
final class ModelRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return array_map([self::class, 'normalize'], $this->pdo->query(
            'SELECT m.*, p.name AS provider_name, p.provider_type, p.is_enabled AS provider_enabled
             FROM llm_models m JOIN llm_providers p ON p.id = m.provider_id ORDER BY p.name, m.priority, m.display_name'
        )->fetchAll());
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT m.*, p.name AS provider_name, p.provider_type, p.is_enabled AS provider_enabled
             FROM llm_models m JOIN llm_providers p ON p.id = m.provider_id WHERE m.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : self::normalize($row);
    }

    public function defaultFor(int $providerId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM llm_models WHERE provider_id = ? ORDER BY is_default DESC, priority, id LIMIT 1');
        $stmt->execute([$providerId]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * Модели, доступные пользователю: включённые модели провайдеров из allowedProviders().
     *
     * @param array<int,array<string,mixed>> $allowedProviders результат AccessPolicy::allowedProviders()
     * @return array<int,array<string,mixed>> по id модели
     */
    public function allowedFor(array $allowedProviders): array
    {
        $ids = array_map('intval', array_keys($allowedProviders));
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT m.*, p.name AS provider_name, p.provider_type, p.is_enabled AS provider_enabled, p.is_default AS provider_default
             FROM llm_models m JOIN llm_providers p ON p.id = m.provider_id
             WHERE m.provider_id IN ({$in}) AND m.is_enabled = 1 AND p.is_enabled = 1 ORDER BY m.priority, p.name, m.display_name"
        );
        $stmt->execute($ids);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int) $row['id']] = self::normalize($row);
        }

        return $out;
    }

    /**
     * Цепочка попыток для модели: сама модель, затем её резервные модели по порядку, затем (если включено
     * автоматическое резервирование) остальные доступные модели по приоритету. Без повторов — каждая модель
     * участвует один раз, поэтому циклическое переключение невозможно.
     *
     * @param array<int,array<string,mixed>> $allowedModels модели, доступные пользователю (allowedFor)
     * @return list<int>
     */
    public function chain(int $modelId, array $allowedModels, bool $autoByPriority): array
    {
        $primary = $this->find($modelId);
        if ($primary === null) {
            return [];
        }
        $chain = [$modelId];
        foreach ($primary['fallback_ids'] as $id) {
            if (isset($allowedModels[$id]) && !in_array($id, $chain, true)) {
                $chain[] = $id;
            }
        }
        if ($autoByPriority) {
            foreach ($allowedModels as $id => $model) {
                if (!in_array($id, $chain, true)) {
                    $chain[] = $id;
                }
            }
        }

        return $chain;
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            'INSERT INTO llm_models (provider_id, model, display_name, context_window, max_output_tokens, temperature, supports_streaming, supports_json,
                                     priority, is_enabled, fallback_ids, price_in_per_mtoken, price_out_per_mtoken, created_at, updated_at)
             VALUES (:provider_id, :model, :display_name, :context_window, :max_output_tokens, :temperature, :supports_streaming, :supports_json,
                     :priority, :is_enabled, :fallback_ids, :price_in, :price_out, :now, :now)'
        );
        $stmt->execute(self::bind($data) + ['provider_id' => (int) $data['provider_id'], 'now' => $now]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE llm_models SET model = :model, display_name = :display_name, context_window = :context_window, max_output_tokens = :max_output_tokens,
                    temperature = :temperature, supports_streaming = :supports_streaming, supports_json = :supports_json, priority = :priority,
                    is_enabled = :is_enabled, fallback_ids = :fallback_ids, price_in_per_mtoken = :price_in, price_out_per_mtoken = :price_out, updated_at = :now
             WHERE id = :id'
        );
        $stmt->execute(self::bind($data) + ['id' => $id, 'now' => gmdate('c')]);
        // Модель по умолчанию — зеркало полей провайдера (совместимость со схемой «провайдер = модель»).
        $this->pdo->prepare('UPDATE llm_providers SET model = :model, max_tokens = :mt WHERE id = (SELECT provider_id FROM llm_models WHERE id = :id AND is_default = 1)')
            ->execute(['model' => $data['model'], 'mt' => (int) $data['max_output_tokens'], 'id' => $id]);
    }

    /** Модель по умолчанию у провайдера удалить нельзя (её задаёт форма провайдера). */
    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM llm_models WHERE id = ? AND is_default = 0');
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) {
            return false;
        }
        // Удалённая модель исчезает из чужих цепочек резервирования.
        foreach ($this->pdo->query("SELECT id, fallback_ids FROM llm_models WHERE fallback_ids LIKE '%" . $id . "%'")->fetchAll() as $row) {
            $ids = array_values(array_filter(self::ids((string) $row['fallback_ids']), static fn (int $x): bool => $x !== $id));
            $this->pdo->prepare('UPDATE llm_models SET fallback_ids = ? WHERE id = ?')->execute([json_encode($ids), (int) $row['id']]);
        }

        return true;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,string>
     */
    public static function validate(array $data): array
    {
        $errors = [];
        if (!preg_match('#\A[A-Za-z0-9._:/@+-]{1,200}\z#', (string) ($data['model'] ?? ''))) {
            $errors['model'] = 'Идентификатор модели: латиница, цифры и . _ : / @ + -';
        }
        $name = trim((string) ($data['display_name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 80) {
            $errors['display_name'] = 'Название — от 1 до 80 символов.';
        }
        foreach (['context_window' => [1024, 10_000_000], 'max_output_tokens' => [16, 1_000_000], 'priority' => [0, 10000]] as $f => [$min, $max]) {
            $v = (int) ($data[$f] ?? 0);
            if ($v < $min || $v > $max) {
                $errors[$f] = "Допустимый диапазон: {$min}–{$max}.";
            }
        }
        if ((int) ($data['max_output_tokens'] ?? 0) >= (int) ($data['context_window'] ?? 0)) {
            $errors['max_output_tokens'] = 'Длина ответа должна быть меньше контекстного окна.';
        }
        $t = $data['temperature'] ?? '';
        if ($t !== '' && $t !== null && (!is_numeric($t) || (float) $t < 0 || (float) $t > 2)) {
            $errors['temperature'] = 'Температура: от 0 до 2 или пусто.';
        }
        foreach (['price_in', 'price_out'] as $f) {
            $v = $data[$f] ?? '';
            if ($v !== '' && $v !== null && (!is_numeric($v) || (float) $v < 0)) {
                $errors[$f] = 'Цена — неотрицательное число или пусто.';
            }
        }

        return $errors;
    }

    /** @return list<int> */
    public static function ids(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? array_values(array_unique(array_filter(array_map('intval', $decoded), static fn (int $x): bool => $x > 0))) : [];
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function bind(array $data): array
    {
        $num = static fn ($v): ?float => $v === '' || $v === null ? null : (float) $v;

        return [
            'model' => (string) $data['model'],
            'display_name' => trim((string) $data['display_name']),
            'context_window' => (int) $data['context_window'],
            'max_output_tokens' => (int) $data['max_output_tokens'],
            'temperature' => $num($data['temperature'] ?? null),
            'supports_streaming' => !empty($data['supports_streaming']) ? 1 : 0,
            'supports_json' => !empty($data['supports_json']) ? 1 : 0,
            'priority' => (int) ($data['priority'] ?? 100),
            'is_enabled' => !empty($data['is_enabled']) ? 1 : 0,
            'fallback_ids' => json_encode(array_map('intval', (array) ($data['fallback_ids'] ?? []))),
            'price_in' => $num($data['price_in'] ?? null),
            'price_out' => $num($data['price_out'] ?? null),
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function normalize(array $row): array
    {
        foreach (['id', 'provider_id', 'context_window', 'max_output_tokens', 'priority'] as $f) {
            $row[$f] = (int) $row[$f];
        }
        foreach (['supports_streaming', 'supports_json', 'is_enabled', 'is_default', 'provider_enabled'] as $f) {
            $row[$f] = (bool) ($row[$f] ?? false);
        }
        $row['temperature'] = $row['temperature'] !== null ? (float) $row['temperature'] : null;
        $row['fallback_ids'] = self::ids((string) $row['fallback_ids']);

        return $row;
    }
}
