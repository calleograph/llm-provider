<?php

declare(strict_types=1);

namespace App\Ldap;

use App\Config\AppSettings;

/**
 * Кэш результата публичной проверки связи с AD (маркер вверху главной
 * страницы). Результат ОБЩИЙ для всех посетителей (не привязан к IP) и живёт
 * ограниченное время — состояние связи с AD одинаково для всех, кто смотрит
 * на страницу в один и тот же момент, поэтому кэшировать по каждому IP
 * отдельно не нужно и не даёт защиты: любое число посетителей с любых адресов
 * укладывается в один реальный сетевой запрос к AD раз в TTL.
 *
 * Без этого кэша публичный (неаутентифицированный) эндпоинт статуса делал бы
 * реальное TCP/TLS-подключение к контроллеру домена при КАЖДОМ обращении
 * любого анонимного посетителя — что само по себе является поверхностью для
 * нагрузки на внутренний AD-сервер.
 *
 * TTL для успеха и для неудачи различаются НАМЕРЕННО: успешный результат
 * живёт минуту (реальная сеть/AD не флапают за секунды, лишний раз дёргать
 * контроллер домена незачем), а неудача — всего несколько секунд, чтобы
 * единичный кратковременный сетевой сбой не держал маркер красным для всех
 * посетителей минуту, пока связь на самом деле уже восстановилась.
 */
final class AdLinkStatusCache
{
    private const SETTINGS_KEY = 'ad_link_status_cache';
    private const TTL_SUCCESS_SECONDS = 60;
    private const TTL_FAILURE_SECONDS = 5;

    public function __construct(private readonly AppSettings $appSettings)
    {
    }

    /**
     * @return array{ok:bool,checked_at:int}|null null, если кэш пуст или устарел
     */
    public function get(): ?array
    {
        $raw = $this->appSettings->get(self::SETTINGS_KEY);
        if ($raw === null) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['ok'], $decoded['checked_at'])) {
            return null;
        }

        $ok = (bool) $decoded['ok'];
        $ttl = $ok ? self::TTL_SUCCESS_SECONDS : self::TTL_FAILURE_SECONDS;

        if ((time() - (int) $decoded['checked_at']) > $ttl) {
            return null;
        }

        return ['ok' => $ok, 'checked_at' => (int) $decoded['checked_at']];
    }

    public function set(bool $ok): void
    {
        $this->appSettings->set(self::SETTINGS_KEY, json_encode(['ok' => $ok, 'checked_at' => time()]));
    }
}
