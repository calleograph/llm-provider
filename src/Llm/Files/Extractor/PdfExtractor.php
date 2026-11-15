<?php

declare(strict_types=1);

namespace App\Llm\Files\Extractor;

use App\Llm\Files\ExtractionResult;
use App\Llm\Files\FileProcessingException;
use App\Llm\Files\FileProcessingSettings;
use App\Llm\Files\OcrEngine;
use App\Llm\Files\ProcessRunner;
use App\Llm\Files\TextExtractor;
use App\Llm\Files\TextNormalizer;

/**
 * PDF: текстовый слой через локальный pdftotext; страницы без текста
 * (сканы) растеризуются pdftoppm и распознаются tesseract. Если есть
 * страницы-сканы, а OCR недоступен, документ блокируется (fail closed) —
 * частично извлечённый текст не отправляется.
 */
final class PdfExtractor implements TextExtractor
{
    // Меньше этого числа непробельных символов — страница считается сканом.
    private const SCANNED_PAGE_THRESHOLD = 20;

    public function __construct(
        private readonly FileProcessingSettings $settings,
        private readonly OcrEngine $ocr,
    ) {
    }

    public function extensions(): array
    {
        return ['pdf'];
    }

    public function matchesSignature(string $head): bool
    {
        return str_contains(substr($head, 0, 1024), '%PDF-');
    }

    public function extract(string $path, string $workDir): ExtractionResult
    {
        $pdftotext = $this->settings->get('pdftotext_path');
        if (!ProcessRunner::isExecutable($pdftotext)) {
            throw new FileProcessingException('tool_unavailable', 'pdftotext не найден: ' . $pdftotext);
        }
        $timeout = max(5, (int) $this->settings->get('tool_timeout'));

        $result = ProcessRunner::run([$pdftotext, '-layout', '-enc', 'UTF-8', $path, '-'], $timeout);
        if ($result['exit'] !== 0) {
            // Зашифрованный/повреждённый PDF.
            throw new FileProcessingException('extraction_failed', 'pdftotext exit=' . $result['exit'] . ': ' . $result['stderr']);
        }

        $pages = explode("\f", TextNormalizer::toUtf8Raw($result['stdout']));
        if (count($pages) > 1 && trim((string) end($pages)) === '') {
            array_pop($pages);
        }

        $scanned = [];
        foreach ($pages as $index => $pageText) {
            if (TextNormalizer::meaningfulLength($pageText) < self::SCANNED_PAGE_THRESHOLD) {
                $scanned[] = $index;
            }
        }

        $ocrPages = 0;
        if ($scanned !== []) {
            // Если все страницы «короткие», но текст всё же есть (например, одна
            // строка), OCR всё равно нужен — иначе содержимое скана потеряется.
            $pdftoppm = $this->settings->get('pdftoppm_path');
            if (!$this->ocr->isAvailable() || !ProcessRunner::isExecutable($pdftoppm)) {
                throw new FileProcessingException('ocr_required_unavailable', 'PDF содержит страницы без текста, OCR недоступен.');
            }
            if (count($scanned) > max(1, (int) $this->settings->get('ocr_max_pages'))) {
                throw new FileProcessingException('content_too_large', 'Слишком много страниц для OCR: ' . count($scanned));
            }

            foreach ($scanned as $index) {
                $pageNumber = (string) ($index + 1);
                $prefix = $workDir . '/pdf-page-' . $pageNumber;
                $render = ProcessRunner::run(
                    [$pdftoppm, '-r', '300', '-gray', '-f', $pageNumber, '-l', $pageNumber, '-png', '-singlefile', $path, $prefix],
                    $timeout,
                );
                $image = $prefix . '.png';
                try {
                    if ($render['exit'] !== 0 || !is_file($image)) {
                        throw new FileProcessingException('ocr_failed', 'pdftoppm exit=' . $render['exit'] . ' для страницы ' . $pageNumber);
                    }
                    $ocrText = $this->ocr->recognize($image);
                    // Текст из слоя (если был) сохраняем, OCR добавляем.
                    $pages[$index] = trim($pages[$index] . "\n" . $ocrText);
                    $ocrPages++;
                } finally {
                    @unlink($image);
                }
            }
        }

        return new ExtractionResult(TextNormalizer::clean(implode("\n\n", $pages)), $ocrPages > 0, count($pages), $ocrPages);
    }
}
