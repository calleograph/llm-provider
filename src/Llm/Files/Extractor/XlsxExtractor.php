<?php

declare(strict_types=1);

namespace App\Llm\Files\Extractor;

use App\Llm\Files\ExtractionResult;
use App\Llm\Files\FileProcessingException;
use App\Llm\Files\TextExtractor;
use App\Llm\Files\TextNormalizer;

/**
 * XLSX: все листы в порядке книги, строки — через перевод строки, ячейки —
 * через табуляцию с сохранением позиций столбцов. Формулы не вычисляются:
 * берётся сохранённое Excel значение.
 */
final class XlsxExtractor implements TextExtractor
{
    private const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const NS_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private const NS_PKG_REL = 'http://schemas.openxmlformats.org/package/2006/relationships';
    private const MAX_COLUMNS = 16384;

    public function extensions(): array
    {
        return ['xlsx'];
    }

    public function matchesSignature(string $head): bool
    {
        return str_starts_with($head, "PK\x03\x04");
    }

    public function extract(string $path, string $workDir): ExtractionResult
    {
        $package = new ZipPackage($path);
        try {
            if (!$package->has('xl/workbook.xml')) {
                throw new FileProcessingException('extraction_failed', 'В архиве нет xl/workbook.xml.');
            }

            $shared = self::sharedStrings($package);
            $blocks = [];
            foreach (self::sheets($package) as $name => $sheetPath) {
                $dom = $package->xml($sheetPath);
                if ($dom === null) {
                    continue;
                }
                $rows = self::sheetRows($dom, $shared);
                if ($rows !== []) {
                    $blocks[] = 'Лист: ' . $name . "\n" . implode("\n", $rows);
                }
            }

            return new ExtractionResult(TextNormalizer::clean(implode("\n\n", $blocks)), false, count($blocks));
        } finally {
            $package->close();
        }
    }

    /** @return string[] */
    private static function sharedStrings(ZipPackage $package): array
    {
        $dom = $package->xml('xl/sharedStrings.xml');
        if ($dom === null) {
            return [];
        }
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('x', self::NS);

        $strings = [];
        foreach ($xpath->query('//x:si') ?: [] as $si) {
            $value = '';
            foreach ($xpath->query('.//x:t[not(ancestor::x:rPh)]', $si) ?: [] as $t) {
                $value .= $t->textContent;
            }
            $strings[] = $value;
        }

        return $strings;
    }

    /** @return array<string,string> имя листа => путь внутри архива */
    private static function sheets(ZipPackage $package): array
    {
        $targets = [];
        $rels = $package->xml('xl/_rels/workbook.xml.rels');
        if ($rels !== null) {
            $xpath = new \DOMXPath($rels);
            $xpath->registerNamespace('r', self::NS_PKG_REL);
            foreach ($xpath->query('//r:Relationship') ?: [] as $rel) {
                /** @var \DOMElement $rel */
                $target = $rel->getAttribute('Target');
                $targets[$rel->getAttribute('Id')] = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . $target;
            }
        }

        $workbook = $package->xml('xl/workbook.xml');
        $result = [];
        if ($workbook !== null) {
            $xpath = new \DOMXPath($workbook);
            $xpath->registerNamespace('x', self::NS);
            foreach ($xpath->query('//x:sheets/x:sheet') ?: [] as $sheet) {
                /** @var \DOMElement $sheet */
                $rid = $sheet->getAttributeNS(self::NS_REL, 'id');
                if (isset($targets[$rid]) && preg_match('#\Axl/worksheets/[^/]+\.xml\z#', $targets[$rid]) === 1) {
                    $result[$sheet->getAttribute('name')] = $targets[$rid];
                }
            }
        }

        if ($result === []) {
            // Нестандартный пакет без связей — берём листы по именам файлов.
            $names = array_values(array_filter($package->names(), static fn (string $n): bool => preg_match('#\Axl/worksheets/sheet\d+\.xml\z#', $n) === 1));
            natsort($names);
            foreach (array_values($names) as $i => $name) {
                $result['Лист' . ($i + 1)] = $name;
            }
        }

        return $result;
    }

    /**
     * @param string[] $shared
     * @return string[]
     */
    private static function sheetRows(\DOMDocument $dom, array $shared): array
    {
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('x', self::NS);

        $rows = [];
        foreach ($xpath->query('//x:sheetData/x:row') ?: [] as $row) {
            $cells = [];
            $nextColumn = 0;
            foreach ($xpath->query('x:c', $row) ?: [] as $cell) {
                /** @var \DOMElement $cell */
                $column = self::columnIndex($cell->getAttribute('r'));
                if ($column === null || $column < $nextColumn) {
                    $column = $nextColumn;
                }
                if ($column >= self::MAX_COLUMNS) {
                    break;
                }
                $cells[$column] = self::cellValue($xpath, $cell, $shared);
                $nextColumn = $column + 1;
            }
            $cells = array_filter($cells, static fn (string $v): bool => $v !== '');
            if ($cells === []) {
                continue;
            }
            $line = [];
            for ($i = 0, $max = max(array_keys($cells)); $i <= $max; $i++) {
                $line[] = str_replace(["\t", "\n"], ' ', $cells[$i] ?? '');
            }
            $rows[] = implode("\t", $line);
        }

        return $rows;
    }

    /** @param string[] $shared */
    private static function cellValue(\DOMXPath $xpath, \DOMElement $cell, array $shared): string
    {
        $type = $cell->getAttribute('t');
        if ($type === 'inlineStr') {
            $value = '';
            foreach ($xpath->query('x:is//x:t', $cell) ?: [] as $t) {
                $value .= $t->textContent;
            }

            return $value;
        }

        $v = $xpath->query('x:v', $cell);
        $raw = $v !== false && $v->length > 0 ? (string) $v->item(0)?->textContent : '';

        return match ($type) {
            's' => $shared[(int) $raw] ?? '',
            'b' => $raw === '1' ? 'TRUE' : 'FALSE',
            default => $raw,
        };
    }

    private static function columnIndex(string $reference): ?int
    {
        if (preg_match('/\A([A-Z]{1,3})\d+\z/', $reference, $m) !== 1) {
            return null;
        }
        $index = 0;
        foreach (str_split($m[1]) as $char) {
            $index = $index * 26 + (ord($char) - 64);
        }

        return $index - 1;
    }
}
