<?php

declare(strict_types=1);

namespace App\Llm\Http;

/**
 * Абстракция исходящего HTTP-запроса. Позволяет LLM Gateway и
 * Anonymization Gateway тестироваться без сети (фейковая реализация в тестах).
 */
interface HttpTransport
{
    /**
     * @throws HttpTransportException при сетевой/TLS-ошибке или превышении лимитов
     */
    public function send(HttpRequest $request): HttpResponse;
}
