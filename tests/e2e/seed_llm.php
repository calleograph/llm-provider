<?php

declare(strict_types=1);

/**
 * Сквозной стенд: подключение к заглушке LLM (tests/e2e/llmstub) и модели со сценариями отказа.
 * Модель по умолчанию — stub-empty (HTTP 200 с пустым ответом), её цепочка: stub-429 → stub-ok.
 * Только из консоли контейнера php. Печатает JSON: provider_id и id моделей.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../../vendor_autoload.php';

use App\Config\Database;
use App\Config\Paths;
use App\Llm\Provider\ModelRepository;
use App\Llm\Provider\ProviderRepository;

$pdo = Database::connect(Paths::database());
$providers = new ProviderRepository($pdo);
$id = (int) ($pdo->query("SELECT id FROM llm_providers WHERE name = 'Заглушка E2E'")->fetchColumn() ?: 0);
if ($id === 0) {
    $id = $providers->create([
        'name' => 'Заглушка E2E', 'provider_type' => 'openai_compatible', 'base_url' => 'https://llm.example.com:8443/v1', 'endpoint' => '/chat/completions',
        'model' => 'stub-empty', 'api_version' => '', 'connect_timeout' => 5, 'response_timeout' => 20, 'max_tokens' => 512,
        'routing_provider' => '', 'sort_by_price' => false, 'max_price_per_mtoken' => '', 'allow_fallbacks' => true, 'use_corporate_ca' => false, 'is_enabled' => true,
    ], 'sk-e2e-not-a-real-key', null);
}
$models = new ModelRepository($pdo);
$ids = ['stub-empty' => $models->defaultFor($id)];
foreach (['stub-429', 'stub-500', 'stub-ok', 'stub-drop', 'stub-badjson', 'stub-slow'] as $name) {
    $existing = $pdo->prepare('SELECT id FROM llm_models WHERE provider_id = ? AND model = ?');
    $existing->execute([$id, $name]);
    $ids[$name] = (int) ($existing->fetchColumn() ?: $models->create(['provider_id' => $id, 'model' => $name, 'display_name' => $name, 'context_window' => 32000,
        'max_output_tokens' => 512, 'priority' => 100, 'is_enabled' => 1, 'fallback_ids' => []]));
}
$pdo->prepare('UPDATE llm_models SET fallback_ids = ?, context_window = 32000 WHERE id = ?')->execute([json_encode([$ids['stub-429'], $ids['stub-ok']]), $ids['stub-empty']]);
$pdo->prepare("INSERT INTO app_settings (key, value) VALUES ('llm_chat_fallback_auto', '0') ON CONFLICT(key) DO UPDATE SET value = '0'")->execute();
echo json_encode(['provider_id' => $id, 'models' => $ids]), "\n";
