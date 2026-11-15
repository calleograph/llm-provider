<?php

declare(strict_types=1);

namespace App\Mail;

use App\Security\SecretBox;
use PDO;

/**
 * Очередь писем (send.ts ArkLock на SQLite): тело зашифровано, пока ждёт отправки, и стирается после приёма сервером;
 * захват записи с арендой 5 минут (зависшая отправка возвращается в очередь); повтор только для временных ошибок,
 * не больше 5 попыток, пауза min(15 мин, 1 мин·2ⁿ⁻¹); срок жизни письма — 24 ч; журнал — 30 дней.
 * Шаблоны уведомлений — здесь же (без пользовательских данных и секретов).
 */
final class MailQueue
{
    public const MAX_ATTEMPTS = 5;
    public const TEMPLATES = [
        'test' => ['Проверка почты LLM-провайдера', "Это тестовое письмо. Если вы его получили — отправка почты настроена правильно.\n\nСервер: {host}\nВремя: {time} UTC"],
        'model_alert' => ['LLM-провайдер: проблема с моделью «{model}»', "Модель «{model}» ({provider}) временно исключена из работы: {reason}.\nПауза до {until} UTC. Запросы сотрудников обслуживают резервные модели.\n\nПодробности — Администрирование → Модели и резервирование."],
        'all_failed' => ['LLM-провайдер: все модели недоступны', "Ни одна модель цепочки не ответила на запрос сотрудника.\nПоследняя причина: {reason}.\n\nПроверьте раздел «Модели и резервирование» и состояние провайдеров."],
    ];

    public function __construct(private readonly PDO $pdo)
    {
        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS mail_queue (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                status TEXT NOT NULL DEFAULT 'queued',
                template TEXT NOT NULL,
                to_addr TEXT NOT NULL,
                payload_enc TEXT,
                attempts INTEGER NOT NULL DEFAULT 0,
                next_attempt_at INTEGER NOT NULL,
                locked_at INTEGER,
                last_error TEXT,
                created_at TEXT NOT NULL,
                sent_at TEXT,
                expires_at INTEGER NOT NULL
            )
        SQL);
    }

    /** @param array<string,string> $vars */
    public function enqueue(string $template, string $to, array $vars): int
    {
        [$subject, $body] = self::TEMPLATES[$template] ?? throw new \InvalidArgumentException('Неизвестный шаблон письма.');
        $replace = [];
        foreach ($vars as $k => $v) {
            $replace['{' . $k . '}'] = str_replace(["\r", "\n"], ' ', mb_substr($v, 0, 300));
        }
        $payload = SecretBox::encrypt((string) json_encode(['subject' => strtr($subject, $replace), 'body' => strtr($body, $replace)], JSON_UNESCAPED_UNICODE));
        $this->pdo->prepare('INSERT INTO mail_queue (template, to_addr, payload_enc, next_attempt_at, created_at, expires_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$template, $to, $payload, time(), gmdate('c'), time() + 86400]);

        return (int) $this->pdo->lastInsertId();
    }

    /** Обработать готовые к отправке письма; возвращает число отправленных. */
    public function process(MailSettings $settings, int $limit = 20, ?\Closure $clientFactory = null): int
    {
        if (!$settings->isConfigured()) {
            return 0;
        }
        $sent = 0;
        $this->pdo->prepare("UPDATE mail_queue SET status = 'failed', payload_enc = NULL, last_error = 'expired' WHERE status IN ('queued','sending') AND expires_at < ?")->execute([time()]);
        for ($i = 0; $i < $limit; $i++) {
            $now = time();
            $row = $this->pdo->query("SELECT * FROM mail_queue WHERE (status = 'queued' AND next_attempt_at <= {$now}) OR (status = 'sending' AND locked_at < " . ($now - 300) . ') ORDER BY next_attempt_at LIMIT 1')->fetch();
            if ($row === false) {
                break;
            }
            $claim = $this->pdo->prepare("UPDATE mail_queue SET status = 'sending', locked_at = ?, attempts = attempts + 1 WHERE id = ? AND (status = 'queued' OR (status = 'sending' AND locked_at < ?))");
            $claim->execute([$now, $row['id'], $now - 300]);
            if ($claim->rowCount() === 0) {
                continue;   // перехватил другой процесс
            }
            $payload = json_decode(SecretBox::decrypt((string) $row['payload_enc']), true);
            $client = $clientFactory !== null ? $clientFactory() : new SmtpClient($settings);
            $result = $client->check((string) $row['to_addr'], (string) $payload['subject'], (string) $payload['body']);
            $attempts = (int) $row['attempts'] + 1;
            if ($result['ok']) {
                $this->pdo->prepare("UPDATE mail_queue SET status = 'sent', payload_enc = NULL, sent_at = ?, last_error = NULL WHERE id = ?")->execute([gmdate('c'), $row['id']]);
                $sent++;
            } elseif (in_array($result['code'], SmtpClient::RETRYABLE, true) && $attempts < self::MAX_ATTEMPTS) {
                $delay = (int) min(900, 60 * 2 ** ($attempts - 1));
                $this->pdo->prepare("UPDATE mail_queue SET status = 'queued', next_attempt_at = ?, last_error = ? WHERE id = ?")->execute([time() + $delay, $result['code'], $row['id']]);
            } else {
                $this->pdo->prepare("UPDATE mail_queue SET status = 'failed', payload_enc = NULL, last_error = ? WHERE id = ?")->execute([$result['code'], $row['id']]);
            }
        }
        $this->pdo->prepare("DELETE FROM mail_queue WHERE created_at < ? AND status IN ('sent','failed')")->execute([gmdate('c', time() - 30 * 86400)]);

        return $sent;
    }

    /** @return list<array<string,mixed>> журнал без содержимого писем */
    public function recent(int $limit = 50): array
    {
        $stmt = $this->pdo->prepare('SELECT id, status, template, to_addr, attempts, last_error, created_at, sent_at FROM mail_queue ORDER BY id DESC LIMIT ?');
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }
}
