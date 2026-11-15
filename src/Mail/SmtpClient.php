<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * SMTP-клиент с поэтапной диагностикой (алгоритм ArkLock apps/api/src/mail/smtp.ts, на PHP):
 * dns → tcp → greeting → ehlo → tls → certificate → methods → auth → sender → recipient → data.
 * Каждый этап записывается {stage, ok, info, code}; тайм-аут относится к этапу, на котором случился.
 *
 * Безопасность: имя сервера разрешается один раз и соединение идёт на проверенный адрес (SSRF: запрещены
 * loopback, link-local, multicast и служебные порты); TLS не ниже 1.2 с проверкой сертификата и имени
 * (отключается только явно); пароль и обмен AUTH в результаты и журналы не попадают — ответы сервера
 * очищаются (serverText): управляющие символы, base64 длиннее 40 знаков, обрезка до 300 знаков.
 */
final class SmtpClient
{
    public const STAGES = ['dns', 'tcp', 'greeting', 'ehlo', 'tls', 'certificate', 'methods', 'auth', 'sender', 'recipient', 'data'];
    private const FORBIDDEN_PORTS = [22, 80, 443, 3306, 5432, 6379, 2375, 2376, 9000, 11211, 27017];
    /** Повторяются в очереди только временные ошибки; неверный пароль — никогда (иначе блокировка учётной записи в AD). */
    public const RETRYABLE = ['temporary', 'limit', 'connect', 'timeout', 'closed', 'dns'];

    /** @var resource|null */
    private $sock = null;
    /** @var list<array{stage:string,ok:bool,info:string,code:?string,skipped?:bool}> */
    public array $log = [];
    private string $password = '';

    public function __construct(private readonly MailSettings $settings, private readonly bool $allowLoopback = false)
    {
    }

    /**
     * Проверка соединения по этапам; с $recipient — до DATA включительно (настоящее письмо).
     *
     * @return array{ok:bool,stages:list<array<string,mixed>>,code:?string}
     */
    public function check(?string $recipient = null, string $subject = '', string $body = ''): array
    {
        $this->log = [];
        try {
            $this->password = $this->settings->password();
            $this->run($recipient, $subject, $body);
            $ok = true;
            $code = null;
        } catch (SmtpException $e) {
            $this->log[] = ['stage' => $e->stage, 'ok' => false, 'info' => self::serverText($e->getMessage()), 'code' => $e->code_];
            $ok = false;
            $code = $e->code_;
        } finally {
            $this->password = '';
            if (is_resource($this->sock)) {
                @fwrite($this->sock, "QUIT\r\n");
                fclose($this->sock);
            }
            $this->sock = null;
        }

        return ['ok' => $ok, 'stages' => $this->log, 'code' => $code];
    }

    private function run(?string $to, string $subject, string $body): void
    {
        $host = $this->settings->get('host');
        $port = (int) $this->settings->get('port');
        $timeout = max(3, (int) $this->settings->get('timeout'));
        $security = $this->settings->get('security');

        // dns + защита от SSRF
        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
        if ($ip === $host && !filter_var($host, FILTER_VALIDATE_IP)) {
            throw new SmtpException('dns', 'dns', 'Имя сервера не разрешается: ' . $host);
        }
        if (in_array($port, self::FORBIDDEN_PORTS, true) || !$this->addressAllowed($ip)) {
            throw new SmtpException('dns', 'forbidden_target', 'Адрес или порт запрещён для почтового сервера: ' . $ip . ':' . $port);
        }
        $this->ok('dns', $host . ' → ' . $ip);

        // tcp (+ сразу TLS для 465)
        $ctx = stream_context_create(['ssl' => $this->tlsOptions($host)]);
        $scheme = $security === 'tls' ? 'tls' : 'tcp';
        $errno = 0;
        $errstr = '';
        $sock = @stream_socket_client($scheme . '://' . $ip . ':' . $port, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if ($sock === false) {
            $tls = $security === 'tls' && $errno === 0;
            throw new SmtpException($tls ? 'tls' : 'tcp', $tls ? 'certificate' : (str_contains(strtolower($errstr), 'timed out') ? 'timeout' : 'connect'), $errstr !== '' ? $errstr : 'Нет соединения');
        }
        $this->sock = $sock;
        stream_set_timeout($sock, $timeout);
        $this->ok('tcp', $ip . ':' . $port);
        if ($security === 'tls') {
            $this->ok('tls', 'TLS установлен при подключении');
            $this->certInfo($ctx);
        }

        $this->expect('greeting', 220);
        $helo = $this->settings->get('helo') !== '' ? $this->settings->get('helo') : (gethostname() ?: 'localhost');
        $caps = $this->ehlo($helo);

        if ($security === 'starttls') {
            if (!isset($caps['STARTTLS'])) {
                throw new SmtpException('tls', 'starttls', 'Сервер не предлагает STARTTLS — выберите другой режим или порт.');
            }
            $this->command('tls', 'STARTTLS', 220);
            stream_context_set_option($sock, ['ssl' => $this->tlsOptions($host)]);
            $crypto = @stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0));
            if ($crypto !== true) {
                throw new SmtpException('certificate', 'certificate', 'TLS не установлен: ' . (error_get_last()['message'] ?? 'проверка сертификата не пройдена'));
            }
            $this->ok('tls', 'STARTTLS');
            $this->certInfo($sock);
            $caps = $this->ehlo($helo);
        } elseif ($security === 'none') {
            $this->log[] = ['stage' => 'tls', 'ok' => true, 'info' => 'не применяется (без шифрования)', 'code' => null, 'skipped' => true];
            $this->log[] = ['stage' => 'certificate', 'ok' => true, 'info' => 'не применяется', 'code' => null, 'skipped' => true];
        }

        $methods = isset($caps['AUTH']) ? array_values(array_filter(explode(' ', strtoupper($caps['AUTH'])))) : [];
        $this->ok('methods', $methods !== [] ? 'AUTH: ' . implode(', ', $methods) : 'сервер не предлагает AUTH');
        $this->auth($methods);

        if ($to === null) {
            return;
        }
        $from = $this->settings->get('from_address');
        $this->command('sender', 'MAIL FROM:<' . $from . '>', 250);
        $this->command('recipient', 'RCPT TO:<' . $to . '>', [250, 251]);
        $this->command('data', 'DATA', 354);
        $this->write(self::message($from, $this->settings->get('from_name'), $to, $subject, $body) . "\r\n.");
        $this->expect('data', 250);
    }

    /** @param list<string> $methods */
    private function auth(array $methods): void
    {
        $mode = $this->settings->get('auth');
        $user = $this->settings->get('username');
        if ($mode === 'none' || $user === '') {
            $this->log[] = ['stage' => 'auth', 'ok' => true, 'info' => 'без авторизации', 'code' => null, 'skipped' => true];

            return;
        }
        $pick = $mode !== 'auto' ? strtoupper($mode) : (in_array('PLAIN', $methods, true) ? 'PLAIN' : (in_array('LOGIN', $methods, true) ? 'LOGIN' : null));
        if ($pick === null || ($methods !== [] && !in_array($pick, $methods, true))) {
            throw new SmtpException('auth', 'auth_unsupported', 'Сервер не поддерживает ' . ($pick ?? 'PLAIN/LOGIN') . '; доступно: ' . ($methods !== [] ? implode(', ', $methods) : 'ничего') . '. Для NTLM/Kerberos используйте relay без авторизации.');
        }
        if ($pick === 'PLAIN') {
            $this->command('auth', 'AUTH PLAIN ' . base64_encode("\0" . $user . "\0" . $this->password), 235, true);
        } else {
            $this->command('auth', 'AUTH LOGIN', 334, true);
            $this->command('auth', base64_encode($user), 334, true);
            $this->command('auth', base64_encode($this->password), 235, true);
        }
        $this->ok('auth', $pick . ' — принят');
    }

    /** @return array<string,string> возможности EHLO */
    private function ehlo(string $helo): array
    {
        $this->write('EHLO ' . $helo);
        [$code, $lines] = $this->read('ehlo');
        if ($code !== 250) {
            throw new SmtpException('ehlo', self::classify('ehlo', $code, implode(' ', $lines)), $code . ' ' . implode(' ', $lines));
        }
        $caps = [];
        foreach (array_slice($lines, 1) as $l) {
            $parts = explode(' ', trim($l), 2);
            $caps[strtoupper($parts[0])] = $parts[1] ?? '';
        }
        $this->ok('ehlo', $lines[0] ?? '');

        return $caps;
    }

    /** @param int|list<int> $want */
    private function command(string $stage, string $line, int|array $want, bool $secret = false): void
    {
        $this->write($line);
        $this->expect($stage, $want, $secret);
    }

    /** @param int|list<int> $want */
    private function expect(string $stage, int|array $want, bool $secret = false): void
    {
        [$code, $lines] = $this->read($stage);
        if (!in_array($code, (array) $want, true)) {
            throw new SmtpException($stage, self::classify($stage, $code, implode(' ', $lines)), $code . ' ' . implode(' ', $lines));
        }
        if (!$secret && in_array($stage, ['greeting', 'sender', 'recipient', 'data'], true)) {
            $this->ok($stage, $code . ' ' . ($lines[0] ?? ''));
        }
    }

    private function write(string $line): void
    {
        if (@fwrite($this->sock, $line . "\r\n") === false) {
            throw new SmtpException('data', 'closed', 'Соединение закрыто сервером');
        }
    }

    /** @return array{0:int,1:list<string>} */
    private function read(string $stage): array
    {
        $lines = [];
        $code = 0;
        while (true) {
            $line = fgets($this->sock, 4096);
            if ($line === false) {
                $meta = stream_get_meta_data($this->sock);
                throw new SmtpException($stage, !empty($meta['timed_out']) ? 'timeout' : 'closed', !empty($meta['timed_out']) ? 'Сервер не ответил вовремя' : 'Соединение закрыто сервером');
            }
            $code = (int) substr($line, 0, 3);
            $lines[] = rtrim(substr($line, 4));
            if (($line[3] ?? ' ') !== '-' || count($lines) > 100) {
                return [$code, $lines];
            }
        }
    }

    /** Классификация ответа (classifyReply ArkLock). */
    public static function classify(string $stage, int $status, string $text): string
    {
        $t = strtolower($text);

        return match (true) {
            in_array($status, [421, 450, 451, 454, 455], true) => preg_match('/too many|rate|limit|throttl|exceed|quota/', $t) === 1 ? 'limit' : ($stage === 'auth' && $status === 454 ? 'auth_failed' : 'temporary'),
            $status === 452, $status === 552 && preg_match('/too many|rate limit|quota exceeded/', $t) === 1 => 'limit',
            $status >= 400 && $status < 500 => 'temporary',
            $stage === 'auth' => $status === 504 || $status === 538 ? 'auth_unsupported' : 'auth_failed',
            preg_match('/relay|not permitted|unable to relay|not allowed to send|access denied/', $t) === 1 => $stage === 'sender' ? 'sender_rejected' : 'relay_denied',
            $stage === 'sender' => 'sender_rejected',
            $stage === 'recipient' => 'recipient_rejected',
            $stage === 'tls' => 'starttls',
            $stage === 'greeting', $stage === 'ehlo' => 'protocol',
            default => 'data',
        };
    }

    /** Текст ответа сервера без секретов: управляющие символы, длинный base64 (обмен AUTH), обрезка. */
    public static function serverText(string $text): string
    {
        $text = (string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text);
        $text = (string) preg_replace('#[A-Za-z0-9+/=]{40,}#', '[…]', $text);

        return mb_substr(trim($text), 0, 300);
    }

    public static function message(string $from, string $fromName, string $to, string $subject, string $body): string
    {
        $enc = static fn (string $s): string => '=?UTF-8?B?' . base64_encode($s) . '?=';
        $headers = [
            'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
            'From: ' . ($fromName !== '' ? $enc($fromName) . ' ' : '') . '<' . $from . '>',
            'To: <' . $to . '>',
            'Subject: ' . $enc($subject),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . (explode('@', $from)[1] ?? 'localhost') . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];
        $text = (string) preg_replace("/\r?\n/", "\r\n", $body);

        return implode("\r\n", $headers) . "\r\n\r\n" . rtrim(chunk_split(base64_encode($text), 76, "\r\n"));
    }

    private function addressAllowed(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;   // IPv6-адреса почтовых серверов — по имени через IPv4 (упрощение: корпоративные SMTP почти всегда IPv4)
        }
        $l = ip2long($ip);
        $in = static fn (string $cidr): bool => (($l ^ ip2long(explode('/', $cidr)[0])) >> (32 - (int) explode('/', $cidr)[1])) === 0;
        if ($in('127.0.0.0/8')) {
            return $this->allowLoopback;
        }

        return !$in('0.0.0.0/8') && !$in('169.254.0.0/16') && !$in('224.0.0.0/4') && !$in('240.0.0.0/4');
    }

    /** @return array<string,mixed> */
    private function tlsOptions(string $host): array
    {
        $verify = $this->settings->get('verify_cert') === '1';

        return ['peer_name' => $host, 'verify_peer' => $verify, 'verify_peer_name' => $verify, 'allow_self_signed' => !$verify, 'capture_peer_cert' => true,
                'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0)];
    }

    /** @param resource $streamOrCtx */
    private function certInfo($streamOrCtx): void
    {
        $params = is_resource($streamOrCtx) && get_resource_type($streamOrCtx) === 'stream' ? stream_context_get_params($streamOrCtx) : stream_context_get_params($streamOrCtx);
        $cert = $params['options']['ssl']['peer_certificate'] ?? null;
        $info = $cert ? openssl_x509_parse($cert) : false;
        $this->ok('certificate', $info ? ('CN=' . ($info['subject']['CN'] ?? '?') . ', до ' . gmdate('Y-m-d', (int) $info['validTo_time_t']) . ($this->settings->get('verify_cert') === '1' ? '' : ' — проверка ОТКЛЮЧЕНА')) : 'сертификат принят');
    }

    private function ok(string $stage, string $info): void
    {
        $this->log[] = ['stage' => $stage, 'ok' => true, 'info' => self::serverText($info), 'code' => null];
    }
}
