<?php

declare(strict_types=1);

namespace App\Llm\Files\Extractor;

use App\Llm\Files\ExtractionResult;
use App\Llm\Files\FileProcessingException;
use App\Llm\Files\OcrEngine;
use App\Llm\Files\TextExtractor;
use App\Llm\Files\TextNormalizer;

/**
 * ODT (OpenDocument): текст content.xml (включая сноски text:note и примечания
 * office:annotation — они внутри абзацев) и styles.xml (колонтитулы).
 * Встроенные изображения распознаются OCR; без OCR документ с изображениями
 * блокируется — как DOCX (порядок из extract.py «Переговорки»).
 */
final class OdtExtractor implements TextExtractor
{
    private const NS_TEXT = 'urn:oasis:names:tc:opendocument:xmlns:text:1.0';

    public function __construct(private readonly ?OcrEngine $ocr = null)
    {
    }

    public function extensions(): array
    {
        return ['odt'];
    }

    public function matchesSignature(string $head): bool
    {
        return str_starts_with($head, "PK\x03\x04") && str_contains($head, 'application/vnd.oasis.opendocument.text');
    }

    public function extract(string $path, string $workDir): ExtractionResult
    {
        $package = new ZipPackage($path);
        try {
            if (!$package->has('content.xml')) {
                throw new FileProcessingException('extraction_failed', 'В архиве нет content.xml.');
            }
            $sections = [];
            foreach (['content.xml', 'styles.xml'] as $part) {
                $dom = $package->has($part) ? $package->xml($part) : null;
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
                static fn (string $n): bool => preg_match('#\APictures/[^/]+\.(png|jpe?g|tiff?|bmp)\z#i', $n) === 1,
            ));
            if ($images === []) {
                return new ExtractionResult($text);
            }
            if ($this->ocr === null || !$this->ocr->isAvailable()) {
                throw new FileProcessingException('ocr_required_unavailable', 'ODT содержит изображения, OCR недоступен — содержимое не проверить.');
            }
            $ocrTexts = [];
            foreach ($images as $index => $image) {
                $data = $package->read($image);
                if ($data === null) {
                    continue;
                }
                $tmp = $workDir . '/odt-image-' . $index . '.' . strtolower(pathinfo($image, PATHINFO_EXTENSION));
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
        $xpath->registerNamespace('text', self::NS_TEXT);
        $lines = [];
        foreach ($xpath->query('//text:p[not(ancestor::text:p)] | //text:h[not(ancestor::text:p)]') ?: [] as $node) {
            $lines[] = self::nodeText($node);
        }

        return implode("\n", $lines);
    }

    private static function nodeText(\DOMNode $node): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            $out .= match (true) {
                $child instanceof \DOMText => $child->nodeValue,
                $child->localName === 'tab' => "\t",
                $child->localName === 'line-break' => "\n",
                $child->localName === 's' => str_repeat(' ', max(1, (int) ($child instanceof \DOMElement ? $child->getAttributeNS(self::NS_TEXT, 'c') : 1))),
                in_array($child->localName, ['p', 'h'], true) => "\n" . self::nodeText($child),
                in_array($child->localName, ['annotation', 'note'], true) => self::nodeText($child) . "\n",
                default => self::nodeText($child),
            };
        }

        return $out;
    }
}
