<?php

declare(strict_types=1);

namespace App\Llm\Chat;

/**
 * Аутентифицированный пользователь чата. Состав групп фиксируется при
 * входе (LDAPS-проверка членства), а права на нейросети вычисляются
 * AccessPolicy заново на каждом запросе.
 */
final class ChatUser
{
    /**
     * @param int[] $groupIds
     * @param string[] $groupNames
     */
    public function __construct(
        public readonly string $username,
        public readonly int $domainId,
        public readonly string $domainFqdn,
        public readonly array $groupIds,
        public readonly array $groupNames,
    ) {
    }

    /** Ключ владельца истории: "sam@домен" в нижнем регистре. */
    public function ownerKey(): string
    {
        return mb_strtolower($this->username . '@' . $this->domainFqdn);
    }

    public function logName(): string
    {
        return $this->username . '@' . $this->domainFqdn;
    }
}
