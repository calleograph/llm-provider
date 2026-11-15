<?php

declare(strict_types=1);

namespace App\Tests;

use App\Llm\Gateway\Adapter\AnthropicAdapter;
use App\Llm\Gateway\Adapter\MessageNormalizer;
use App\Llm\Gateway\Adapter\OpenAiAdapter;
use App\Llm\Gateway\Adapter\OpenAiCompatibleAdapter;
use App\Llm\Gateway\AdapterRegistry;
use App\Llm\Gateway\LlmException;
use App\Llm\Http\HttpResponse;
use App\Llm\Provider\ProviderConfig;
use App\Llm\Provider\ProviderValidator;
use PHPUnit\Framework\TestCase;

final class LlmAdaptersTest extends TestCase
{
    private static function provider(string $type, array $overrides = []): ProviderConfig
    {
        $values = $overrides + [
            'baseUrl' => 'https://polza.ai/api/v1',
            'endpoint' => '/chat/completions',
            'apiVersion' => null,
            'extraHeaders' => [],
        ];

        return new ProviderConfig(1, 'Test', $type, $values['baseUrl'], $values['endpoint'], 'sk-secret', 'openai/gpt-4o', $values['apiVersion'], 5, 60, 256, $values['extraHeaders'], false, true);
    }

    public function testOpenAiCompatibleRequestMatchesPolzaExample(): void
    {
        $request = (new OpenAiCompatibleAdapter())->buildRequest(
            self::provider('openai_compatible'),
            [['role' => 'user', 'content' => 'Что думаешь об этой жизни?']],
            'Системная инструкция',
            256,
            null,
        );
        $payload = json_decode((string) $request->body, true);

        $this->assertSame('https://polza.ai/api/v1/chat/completions', $request->url);
        $this->assertSame('Bearer sk-secret', $request->headers['Authorization']);
        $this->assertSame('openai/gpt-4o', $payload['model']);
        $this->assertSame(256, $payload['max_tokens']);
        $this->assertSame([
            ['role' => 'system', 'content' => 'Системная инструкция'],
            ['role' => 'user', 'content' => 'Что думаешь об этой жизни?'],
        ], $payload['messages']);
    }

    public function testOpenAiUsesMaxCompletionTokensAndApiVersionQuery(): void
    {
        $request = (new OpenAiAdapter())->buildRequest(
            self::provider('openai', ['baseUrl' => 'https://api.openai.com/v1', 'apiVersion' => '2024-06-01']),
            [['role' => 'user', 'content' => 'x']],
            null,
            100,
            null,
        );
        $payload = json_decode((string) $request->body, true);

        $this->assertArrayHasKey('max_completion_tokens', $payload);
        $this->assertStringEndsWith('?api-version=2024-06-01', $request->url);
    }

    public function testExtraHeadersCannotOverrideAuthorization(): void
    {
        $request = (new OpenAiAdapter())->buildRequest(
            self::provider('openai', ['extraHeaders' => ['authorization' => 'Bearer evil', 'X-Org' => 'corp']]),
            [['role' => 'user', 'content' => 'x']],
            null,
            10,
            null,
        );

        $this->assertSame('Bearer sk-secret', $request->headers['Authorization']);
        $this->assertArrayNotHasKey('authorization', $request->headers);
        $this->assertSame('corp', $request->headers['X-Org']);
    }

    public function testChatCompletionsResponseParsing(): void
    {
        $result = (new OpenAiCompatibleAdapter())->parseResponse(new HttpResponse(200, json_encode([
            'model' => 'openai/gpt-4o',
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'Ответ'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 3],
        ])));

        $this->assertSame('Ответ', $result->text);
        $this->assertSame(10, $result->inputTokens);
        $this->assertSame('stop', $result->finishReason);
    }

    public function testAnthropicRequestAndResponse(): void
    {
        $adapter = new AnthropicAdapter();
        $request = $adapter->buildRequest(
            self::provider('anthropic', ['baseUrl' => 'https://api.anthropic.com', 'endpoint' => '/v1/messages', 'apiVersion' => '2023-06-01']),
            [
                ['role' => 'assistant', 'content' => 'лишнее начало'],
                ['role' => 'user', 'content' => 'Первый'],
                ['role' => 'user', 'content' => 'Второй'],
            ],
            'Система',
            300,
            null,
        );
        $payload = json_decode((string) $request->body, true);

        $this->assertSame('https://api.anthropic.com/v1/messages', $request->url);
        $this->assertSame('sk-secret', $request->headers['x-api-key']);
        $this->assertSame('2023-06-01', $request->headers['anthropic-version']);
        $this->assertSame('Система', $payload['system']);
        $this->assertSame(300, $payload['max_tokens']);
        $this->assertSame([['role' => 'user', 'content' => "Первый\n\nВторой"]], $payload['messages']);

        $result = $adapter->parseResponse(new HttpResponse(200, json_encode([
            'model' => 'claude-sonnet-5',
            'content' => [['type' => 'text', 'text' => 'Привет'], ['type' => 'text', 'text' => '!']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 7, 'output_tokens' => 2],
        ])));
        $this->assertSame('Привет!', $result->text);
        $this->assertSame(2, $result->outputTokens);
    }

    public function testHttpErrorsAreClassified(): void
    {
        $cases = [401 => 'auth_failed', 429 => 'rate_limited', 400 => 'bad_request', 503 => 'server_error'];
        foreach ($cases as $status => $code) {
            try {
                (new OpenAiAdapter())->parseResponse(new HttpResponse($status, '{"error":{"message":"m","type":"t"}}'));
                $this->fail('Ожидалось исключение');
            } catch (LlmException $e) {
                $this->assertSame($code, $e->errorCode);
                $this->assertSame('t', $e->providerErrorType);
            }
        }
    }

    public function testUnexpectedSuccessBodyIsInvalidResponse(): void
    {
        $this->expectException(LlmException::class);
        (new AnthropicAdapter())->parseResponse(new HttpResponse(200, '{"choices":[]}'));
    }

    public function testNormalizerAlternatesRoles(): void
    {
        $this->assertSame(
            [['role' => 'user', 'content' => 'a'], ['role' => 'assistant', 'content' => "b\n\nc"], ['role' => 'user', 'content' => 'd']],
            MessageNormalizer::alternate([
                ['role' => 'user', 'content' => 'a'],
                ['role' => 'assistant', 'content' => 'b'],
                ['role' => 'assistant', 'content' => 'c'],
                ['role' => 'user', 'content' => ' '],
                ['role' => 'user', 'content' => 'd'],
            ]),
        );
    }

    public function testRegistryKnowsRequiredTypes(): void
    {
        $registry = AdapterRegistry::withDefaults();
        foreach (['openai', 'anthropic', 'openai_compatible'] as $type) {
            $this->assertTrue($registry->has($type));
        }
        $this->expectException(LlmException::class);
        $registry->get('unknown');
    }

    public function testProviderValidation(): void
    {
        $registry = AdapterRegistry::withDefaults();
        $valid = ProviderValidator::normalize([
            'name' => 'Polza', 'provider_type' => 'openai_compatible', 'base_url' => 'https://polza.ai/api/v1/',
            'endpoint' => '/chat/completions', 'model' => 'openai/gpt-4o', 'connect_timeout' => 10, 'response_timeout' => 120, 'max_tokens' => 2048,
        ]);
        $this->assertSame('https://polza.ai/api/v1', $valid['base_url']);
        $this->assertSame([], ProviderValidator::validate($valid, $registry));

        $invalid = ProviderValidator::validate(['base_url' => 'http://insecure.example', 'endpoint' => 'chat', 'model' => 'bad model'] + $valid, $registry);
        $this->assertArrayHasKey('base_url', $invalid);
        $this->assertArrayHasKey('endpoint', $invalid);
        $this->assertArrayHasKey('model', $invalid);
    }

    public function testHeaderParsing(): void
    {
        [$headers, $error] = ProviderValidator::parseHeaders("X-Org: corp\nX-Project: llm");
        $this->assertNull($error);
        $this->assertSame(['X-Org' => 'corp', 'X-Project' => 'llm'], $headers);

        $this->assertNotNull(ProviderValidator::parseHeaders('Host: evil')[1]);
        $this->assertNotNull(ProviderValidator::parseHeaders('broken line')[1]);
        $this->assertNotNull(ProviderValidator::parseHeaders("X-A: b\r\nX-B:")[1]);
    }
}
