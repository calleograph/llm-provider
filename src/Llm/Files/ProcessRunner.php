<?php

declare(strict_types=1);

namespace App\Llm\Files;

/**
 * Запуск локальных утилит (pdftotext, pdftoppm, tesseract) без shell:
 * команда передаётся массивом аргументов в proc_open, поэтому имя файла
 * не интерпретируется оболочкой. Есть таймаут и лимит объёма вывода.
 */
final class ProcessRunner
{
    /**
     * @param string[] $command
     * @return array{exit:int,stdout:string,stderr:string}
     */
    public static function run(array $command, int $timeoutSeconds, int $maxOutputBytes = 32 * 1024 * 1024): array
    {
        $binary = $command[0] ?? '';
        if ($binary === '' || !is_file($binary) || !is_executable($binary)) {
            throw new FileProcessingException('tool_unavailable', 'Инструмент недоступен: ' . $binary);
        }

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes, null, ['LC_ALL' => 'C.UTF-8', 'OMP_THREAD_LIMIT' => '1']);
        if (!is_resource($process)) {
            throw new FileProcessingException('extraction_failed', 'Не удалось запустить ' . basename($binary));
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + $timeoutSeconds;

        while (true) {
            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                proc_terminate($process, 9);
                self::closePipes($pipes);
                proc_close($process);
                throw new FileProcessingException('extraction_failed', basename($binary) . ': превышен таймаут ' . $timeoutSeconds . ' с.');
            }
            $ready = @stream_select($read, $write, $except, (int) ceil(min($remaining, 1.0)));
            if ($ready === false) {
                break;
            }
            foreach ($read as $stream) {
                $chunk = fread($stream, 65536);
                if ($chunk === false || $chunk === '') {
                    continue;
                }
                if ($stream === $pipes[1]) {
                    $stdout .= $chunk;
                } else {
                    $stderr .= substr($chunk, 0, 8192);
                }
            }
            if (strlen($stdout) > $maxOutputBytes) {
                proc_terminate($process, 9);
                self::closePipes($pipes);
                proc_close($process);
                throw new FileProcessingException('content_too_large', basename($binary) . ': превышен объём вывода.');
            }
            if (feof($pipes[1]) && feof($pipes[2])) {
                break;
            }
        }

        self::closePipes($pipes);
        $exit = proc_close($process);

        return ['exit' => $exit, 'stdout' => $stdout, 'stderr' => mb_substr($stderr, 0, 1000)];
    }

    /** @param array<int,resource> $pipes */
    private static function closePipes(array $pipes): void
    {
        foreach ([1, 2] as $index) {
            if (isset($pipes[$index]) && is_resource($pipes[$index])) {
                fclose($pipes[$index]);
            }
        }
    }

    public static function isExecutable(string $path): bool
    {
        return $path !== '' && is_file($path) && is_executable($path);
    }
}
