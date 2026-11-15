<?php

declare(strict_types=1);

namespace App\Tests;

use App\Llm\Http\HttpRequest;
use App\Llm\Http\HttpResponse;
use App\Llm\Http\HttpTransport;

/**
 * Фейковый HTTP-транспорт: запоминает все запросы и отвечает
 * заданным обработчиком. Позволяет проверить, что именно (и было ли
 * вообще) отправлено во внешний сервис.
 */
final class FakeHttpTransport implements HttpTransport
{
    /** @var HttpRequest[] */
    public array $requests = [];

    /** @var \Closure(HttpRequest):HttpResponse */
    private \Closure $handler;

    public function __construct(?\Closure $handler = null)
    {
        $this->handler = $handler ?? static fn (HttpRequest $r): HttpResponse => new HttpResponse(500, '{}');
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $this->requests[] = $request;

        return ($this->handler)($request);
    }

    /** @return HttpRequest[] */
    public function requestsTo(string $urlPart): array
    {
        return array_values(array_filter($this->requests, static fn (HttpRequest $r): bool => str_contains($r->url, $urlPart)));
    }
}
