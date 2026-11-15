<?php

declare(strict_types=1);

namespace App\Llm\Files;

use App\Config\Paths;
use App\Llm\Files\Extractor\DocxExtractor;
use App\Llm\Files\Extractor\ImageOcrExtractor;
use App\Llm\Files\Extractor\PdfExtractor;
use App\Llm\Files\Extractor\PlainTextExtractor;
use App\Llm\Files\Extractor\XlsxExtractor;

/**
 * Локальная цепочка обработки загруженного файла:
 *   загрузка → временный закрытый каталог → проверка расширения и
 *   сигнатуры → локальное извлечение текста (+ OCR при необходимости) →
 *   удаление временных файлов (в finally, при любом исходе).
 *
 * Результат — ИСХОДНЫЙ извлечённый текст; дальше он обязан пройти
 * AnonymizationGateway. Сам файл никуда не передаётся и не сохраняется.
 */
final class FileProcessor
{
    private const STALE_SECONDS = 3600;

    /** @var array<string,TextExtractor> */
    private array $byExtension = [];

    public function __construct(
        private readonly FileProcessingSettings $settings,
        private readonly string $tmpRoot,
    ) {
    }

    public static function withDefaults(FileProcessingSettings $settings, ?string $tmpRoot = null): self
    {
        $ocr = new OcrEngine($settings);
        $processor = new self($settings, $tmpRoot ?? Paths::llmTmp());
        $processor->register(new PlainTextExtractor());
        $processor->register(new DocxExtractor($ocr));
        $processor->register(new XlsxExtractor());
        $processor->register(new PdfExtractor($settings, $ocr));
        $processor->register(new ImageOcrExtractor($ocr));

        return $processor;
    }

    public function register(TextExtractor $extractor): void
    {
        foreach ($extractor->extensions() as $extension) {
            $this->byExtension[strtolower($extension)] = $extractor;
        }
    }

    /** @return string[] */
    public function supportedExtensions(): array
    {
        return array_keys($this->byExtension);
    }

    /**
     * @param bool $isHttpUpload true — $sourcePath получен из $_FILES (move_uploaded_file)
     * @throws FileProcessingException
     */
    public function process(string $sourcePath, string $originalName, int $size, bool $isHttpUpload = true): ExtractedFile
    {
        return $this->withStagedFile($sourcePath, $originalName, $size, $isHttpUpload, function (StagedFile $file): ExtractedFile {
            $result = $this->extractText($file->path, $file->extension, $file->workDir);

            return new ExtractedFile($file->extension, $result->text, $file->size, $result->ocrUsed, $result->pages, $result->ocrPages);
        });
    }

    /**
     * Принимает файл во временный каталог (с проверками) и передаёт его
     * обработчику. Каталог со всем содержимым удаляется в finally при
     * любом исходе — в том числе с результатами, скачанными у сервиса
     * обезличивания в тот же $file->workDir.
     *
     * @template T
     * @param callable(StagedFile):T $handler
     * @return T
     * @throws FileProcessingException
     */
    public function withStagedFile(string $sourcePath, string $originalName, int $size, bool $isHttpUpload, callable $handler): mixed
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!isset($this->byExtension[$extension])) {
            throw new FileProcessingException('unsupported_type', 'Расширение не поддерживается: ' . $extension);
        }
        if ($size <= 0) {
            throw new FileProcessingException('empty_content', 'Пустой файл.');
        }
        if ($size > $this->settings->maxUploadBytes()) {
            throw new FileProcessingException('too_large', 'Размер файла превышает лимит.');
        }

        $this->cleanupStale();
        $workDir = $this->createWorkDir();
        $storedPath = $workDir . '/input.' . $extension;

        try {
            $moved = $isHttpUpload ? @move_uploaded_file($sourcePath, $storedPath) : @copy($sourcePath, $storedPath);
            if (!$moved) {
                throw new FileProcessingException('storage_error', 'Не удалось сохранить временный файл.');
            }
            @chmod($storedPath, 0600);

            $handle = fopen($storedPath, 'rb');
            $head = $handle !== false ? (string) fread($handle, 2048) : '';
            if ($handle !== false) {
                fclose($handle);
            }

            if (!$this->byExtension[$extension]->matchesSignature($head)) {
                throw new FileProcessingException('signature_mismatch', 'Сигнатура файла не соответствует расширению ' . $extension . '.');
            }

            return $handler(new StagedFile($storedPath, $extension, $size, $workDir));
        } finally {
            self::removeDirectory($workDir);
        }
    }

    /**
     * Локальное извлечение текста из файла известного формата с
     * нормализацией и лимитами. Пустой результат — блокировка.
     *
     * @throws FileProcessingException
     */
    public function extractText(string $path, string $extension, string $workDir): ExtractionResult
    {
        $extension = strtolower($extension);
        if (!isset($this->byExtension[$extension])) {
            throw new FileProcessingException('unsupported_type', 'Нет обработчика для формата: ' . $extension);
        }

        $result = $this->byExtension[$extension]->extract($path, $workDir);
        $text = TextNormalizer::clean($result->text);

        if (TextNormalizer::meaningfulLength($text) === 0) {
            throw new FileProcessingException('empty_content', 'Текст не извлечён.');
        }
        if (mb_strlen($text) > max(1000, (int) $this->settings->get('max_extracted_chars'))) {
            throw new FileProcessingException('content_too_large', 'Извлечённый текст превышает лимит.');
        }

        return new ExtractionResult($text, $result->ocrUsed, $result->pages, $result->ocrPages);
    }

    private function createWorkDir(): string
    {
        if (!is_dir($this->tmpRoot) && !@mkdir($this->tmpRoot, 0700, true) && !is_dir($this->tmpRoot)) {
            throw new FileProcessingException('storage_error', 'Каталог временных файлов недоступен.');
        }
        $dir = $this->tmpRoot . '/' . bin2hex(random_bytes(16));
        if (!@mkdir($dir, 0700)) {
            throw new FileProcessingException('storage_error', 'Не удалось создать временный каталог.');
        }

        return $dir;
    }

    /**
     * Страховка на случай аварийного завершения PHP-процесса посреди
     * обработки: удаляет забытые рабочие каталоги старше часа.
     */
    private function cleanupStale(): void
    {
        foreach (glob($this->tmpRoot . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (preg_match('/\A[0-9a-f]{32}\z/', basename($dir)) === 1 && (time() - (int) @filemtime($dir)) > self::STALE_SECONDS) {
                self::removeDirectory($dir);
            }
        }
    }

    private static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) && !is_link($path) ? self::removeDirectory($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
