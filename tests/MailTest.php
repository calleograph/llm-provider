<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config\AppSettings;
use App\Mail\MailQueue;
use App\Mail\MailSettings;
use App\Mail\SmtpClient;
use PHPUnit\Framework\TestCase;

/** Почта: поэтапная проверка, классификация, очередь с ограниченными повторами, секреты не утекают. */
final class MailTest extends TestCase
{
    private \PDO $pdo;
    private MailSettings $settings;
    private string $log;

    protected function setUp(): void
    {
        $this->pdo = LlmTestKit::database();
        $this->settings = new MailSettings(new AppSettings($this->pdo));
        $this->log = sys_get_temp_dir() . '/fake-smtp-' . bin2hex(random_bytes(4)) . '.log';
    }

    protected function tearDown(): void
    {
        @unlink($this->log);
        @unlink($this->log . '.ready');
    }

    /** @param array<string,int> $scenario */
    private function server(array $scenario): int
    {
        $port = random_int(20000, 40000);
        $proc = proc_open([PHP_BINARY, __DIR__ . '/fixtures/fake_smtp.php', (string) $port, json_encode($scenario), $this->log], [], $pipes);
        $this->assertIsResource($proc);
        for ($i = 0; $i < 50 && !is_file($this->log . '.ready'); $i++) {
            usleep(20000);
        }
        $this->settings->save(['enabled' => '1', 'host' => '127.0.0.1', 'port' => (string) $port, 'security' => 'none', 'auth' => 'auto',
            'username' => 'robot', 'from_address' => 'robot@example.com', 'timeout' => '5'], 'S3cret-Pass-123');

        return $port;
    }

    public function testStagedCheckAndSendWithoutLeakingThePassword(): void
    {
        $this->server([]);
        $r = (new SmtpClient($this->settings, true))->check('admin@example.com', 'Тема', "Строка 1\nСтрока 2");
        $this->assertTrue($r['ok'], json_encode($r['stages'], JSON_UNESCAPED_UNICODE));
        $stages = array_column($r['stages'], 'stage');
        foreach (['dns', 'tcp', 'greeting', 'ehlo', 'tls', 'methods', 'auth', 'sender', 'recipient', 'data'] as $s) {
            $this->assertContains($s, $stages);
        }
        $this->assertStringNotContainsString('S3cret', json_encode($r, JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString(base64_encode("\0robot\0S3cret-Pass-123"), json_encode($r));
        $wire = (string) file_get_contents($this->log);
        $this->assertStringContainsString('Subject: =?UTF-8?B?' . base64_encode('Тема') . '?=', $wire);
    }

    public function testAuthFailureIsClassifiedAndNeverRetried(): void
    {
        $this->server(['auth' => 535]);
        $queue = new MailQueue($this->pdo);
        $queue->enqueue('test', 'admin@example.com', ['host' => 'x', 'time' => 'now']);
        $queue->process($this->settings, 5, fn () => new SmtpClient($this->settings, true));
        $row = $this->pdo->query('SELECT status, last_error, payload_enc FROM mail_queue')->fetch();
        $this->assertSame(['failed', 'auth_failed', null], [$row['status'], $row['last_error'], $row['payload_enc']], 'неверный пароль не повторяется (блокировка в AD), тело стёрто');
    }

    public function testTemporaryRejectIsRetriedWithBackoff(): void
    {
        $this->server(['rcpt' => 451]);
        $queue = new MailQueue($this->pdo);
        $queue->enqueue('model_alert', 'admin@example.com', ['model' => 'М', 'provider' => 'П', 'reason' => 'HTTP 429', 'until' => '—']);
        $queue->process($this->settings, 5, fn () => new SmtpClient($this->settings, true));
        $row = $this->pdo->query('SELECT status, attempts, last_error, next_attempt_at, payload_enc FROM mail_queue')->fetch();
        $this->assertSame('queued', $row['status']);
        $this->assertSame('temporary', $row['last_error']);
        $this->assertGreaterThanOrEqual(time() + 55, (int) $row['next_attempt_at']);
        $this->assertNotNull($row['payload_enc'], 'письмо ждёт повтора зашифрованным');
        $this->assertStringNotContainsString('HTTP 429', (string) $row['payload_enc']);
    }

    public function testClassificationAndSafety(): void
    {
        $this->assertSame('limit', SmtpClient::classify('recipient', 421, 'Too many connections'));
        $this->assertSame('auth_unsupported', SmtpClient::classify('auth', 504, 'Unrecognized authentication type'));
        $this->assertSame('relay_denied', SmtpClient::classify('recipient', 550, 'Unable to relay'));
        $this->assertSame('recipient_rejected', SmtpClient::classify('recipient', 550, 'No such user'));
        $this->assertSame('[…]', SmtpClient::serverText(str_repeat('QUJD', 20)));
        $this->settings->save(['enabled' => '1', 'host' => '127.0.0.1', 'port' => '25', 'security' => 'none', 'from_address' => 'a@example.com'], null);
        $r = (new SmtpClient($this->settings))->check();
        $this->assertSame('forbidden_target', $r['code'], 'loopback без явного разрешения — защита от SSRF');
        $this->settings->save(['host' => 'smtp.example.com', 'port' => '587'], 'pass1');
        $this->settings->save(['host' => 'other.example.com'], null);
        $this->assertFalse($this->settings->hasPassword(), 'смена сервера сбрасывает сохранённый пароль');
    }
}
