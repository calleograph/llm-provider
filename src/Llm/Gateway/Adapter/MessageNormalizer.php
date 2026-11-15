<?php

declare(strict_types=1);

namespace App\Llm\Gateway\Adapter;

/**
 * Приводит историю к строгому чередованию user/assistant, начиная с user:
 * пустые реплики отбрасываются, подряд идущие реплики одной роли
 * склеиваются. Нужно Anthropic Messages API и безопасно для остальных.
 */
final class MessageNormalizer
{
    /**
     * @param list<array{role:string,content:string}> $messages
     * @return list<array{role:string,content:string}>
     */
    public static function alternate(array $messages): array
    {
        $result = [];
        foreach ($messages as $message) {
            $role = $message['role'] === 'assistant' ? 'assistant' : 'user';
            $content = (string) $message['content'];
            if (trim($content) === '') {
                continue;
            }
            if ($result === [] && $role === 'assistant') {
                continue;
            }
            $last = count($result) - 1;
            if ($last >= 0 && $result[$last]['role'] === $role) {
                $result[$last]['content'] .= "\n\n" . $content;
                continue;
            }
            $result[] = ['role' => $role, 'content' => $content];
        }

        return $result;
    }

    /**
     * @param array<string,string> $extraHeaders
     * @param array<string,string> $authHeaders
     * @return array<string,string> заголовки авторизации приоритетнее дополнительных
     */
    public static function mergeHeaders(array $extraHeaders, array $authHeaders): array
    {
        $merged = [];
        $reserved = array_map('strtolower', array_keys($authHeaders));
        foreach ($extraHeaders as $name => $value) {
            if (!in_array(strtolower($name), $reserved, true)) {
                $merged[$name] = $value;
            }
        }

        return $merged + $authHeaders;
    }
}
