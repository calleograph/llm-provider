<?php

// Поддельный SMTP-сервер для тестов: argv[1] — порт, argv[2] — JSON-сценарий {auth: код, rcpt: код, data: код}, argv[3] — файл журнала команд.
$port = (int) $argv[1];
$cfg = json_decode($argv[2], true);
$log = $argv[3];
$srv = stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $errstr);
file_put_contents($log . '.ready', '1');
$conn = @stream_socket_accept($srv, 10);
if ($conn === false) {
    exit;
}
$say = static fn (string $l) => fwrite($conn, $l . "\r\n");
$say('220 fake.example.com ESMTP');
$inData = false;
while (($line = fgets($conn)) !== false) {
    file_put_contents($log, $line, FILE_APPEND);
    $cmd = strtoupper(trim($line));
    if ($inData) {
        if (trim($line) === '.') {
            $inData = false;
            $say(($cfg['data'] ?? 250) . ' queued');
        }
        continue;
    }
    match (true) {
        str_starts_with($cmd, 'EHLO') => $say("250-fake.example.com\r\n250-AUTH PLAIN LOGIN\r\n250 8BITMIME"),
        str_starts_with($cmd, 'AUTH PLAIN') => $say(($cfg['auth'] ?? 235) . (($cfg['auth'] ?? 235) === 235 ? ' ok' : ' 5.7.8 Authentication credentials invalid')),
        str_starts_with($cmd, 'MAIL FROM') => $say('250 ok'),
        str_starts_with($cmd, 'RCPT TO') => $say(($cfg['rcpt'] ?? 250) . (($cfg['rcpt'] ?? 250) === 250 ? ' ok' : ' 4.7.1 try again later')),
        $cmd === 'DATA' => [$inData = true, $say('354 go')],
        $cmd === 'QUIT' => [$say('221 bye'), fclose($conn), exit],
        default => $say('250 ok'),
    };
}
