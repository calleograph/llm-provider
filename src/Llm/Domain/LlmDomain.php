<?php

declare(strict_types=1);

namespace App\Llm\Domain;

use App\Ldap\LdapConnectionConfig;

/**
 * Доменное подключение с точки зрения LLM-модуля: существующая
 * LDAPS-конфигурация (LdapConnectionConfig без изменений) плюс параметры
 * модуля. Пароль сервисной учётки здесь не хранится — только признак.
 */
final class LlmDomain
{
    public function __construct(
        public readonly LdapConnectionConfig $config,
        public readonly bool $llmEnabled,
        public readonly ?string $serviceUsername,
        public readonly bool $hasServicePassword,
    ) {
    }

    public function id(): int
    {
        return $this->config->id;
    }

    public function hasServiceAccount(): bool
    {
        return $this->serviceUsername !== null && $this->serviceUsername !== '' && $this->hasServicePassword;
    }
}
