<?php

declare(strict_types=1);

namespace App\Llm\Files\Extractor;

use App\Llm\Files\ExtractionResult;
use App\Llm\Files\FileProcessingException;
use App\Llm\Files\OcrEngine;
use App\Llm\Files\TextExtractor;
use App\Llm\Files\TextNormalizer;

/**
 * DOCX: текст основного документа, колонтитулов, сносок и примечаний.
 * Встроенные изображения (сканы, снимки экрана) — тоже содержимое документа:
 * они распознаются локальным OCR и добавляются к тексту; без OCR документ
 * с изображениями блокируется целиком (непроверенное содержимое наружу не уходит).
 */
final class DocxExtractor implements TextExtractor
{
    private const NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    public function __construct(private readonly ?OcrEngine $ocr = null)
    {
    }

    public function extensions(): array
    {
        return ['docx'];
    }

    public function matchesSignature(string $head): bool
    {
        return str_starts_with($head, "PK\x03\x04");
    }

    public function extract(string $path, string $workDir): ExtractionResult
    {
        $package = new ZipPackage($path);
        try {
            if (!$package->has('word/document.xml')) {
                throw new FileProcessingException('extraction_failed', 'В архиве нет word/document.xml.');
            }

            $parts = ['word/document.xml'];
            foreach ($package->names() as $name) {
                if (preg_match('#\Aword/(header\d*|footer\d*|footnotes|endnotes|comments)\.xml\z#', $name) === 1) {
                    $parts[] = $name;
                }
            }

            $sections = [];
            foreach ($parts as $part) {
                $dom = $package->xml($part);
                if ($dom !== null) {
                    $text = self::paragraphsText($dom);
                    if (trim($text) !== '') {
                        $sections[] = $text;
                    }
                }
            }
            $text = TextNormalizer::clean(implode("\n\n", $sections));

            $images = array_values(array_filter(
                $package->names(),
                static fn (string $n): bool => preg_match('#\Aword/media/[^/]+\.(png|jpe?g|tiff?|bmp)\z#i', $n) === 1,
            ));
            if ($images === []) {
                return new ExtractionResult($text);
            }
            if ($this->ocr === null || !$this->ocr->isAvailable()) {
                throw new FileProcessingException('ocr_required_unavailable', 'DOCX содержит изображения, OCR недоступен — содержимое не проверить.');
            }

            $ocrTexts = [];
            foreach ($images as $index => $image) {
                $data = $package->read($image);
                if ($data === null) {
                    continue;
                }
                $tmp = $workDir . '/docx-image-' . $index . '.' . strtolower(pathinfo($image, PATHINFO_EXTENSION));
                file_put_contents($tmp, $data);
                try {
                    $ocrTexts[] = $this->ocr->recognize($tmp);
                } finally {
                    @unlink($tmp);
                }
            }

            return new ExtractionResult(TextNormalizer::clean(implode("\n\n", array_merge([$text], $ocrTexts))), true, null, count($ocrTexts));
        } finally {
            $package->close();
        }
    }

    private static function paragraphsText(\DOMDocument $dom): string
    {
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('w', self::NS);

        $lines = [];
        // Только абзацы верхнего уровня: текст вложенных (надписи) войдёт в них.
        foreach ($xpath->query('//w:p[not(ancestor::w:p)]') ?: [] as $paragraph) {
            $line = '';
            foreach ($xpath->query('.//w:t | .//w:tab | .//w:br | .//w:cr', $paragraph) ?: [] as $node) {
                $line .= match ($node->localName) {
                    't' => $node->textContent,
                    'tab' => "\t",
                    default => "\n",
                };
            }
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }
}
