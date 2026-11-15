<?php

declare(strict_types=1);

namespace App\Llm\Files\Extractor;

use App\Llm\Files\FileProcessingException;

/**
 * Безопасное чтение OOXML-пакета (DOCX/XLSX — это ZIP): лимиты на число
 * записей и суммарный распакованный размер (zip bomb), XML без DOCTYPE и
 * без сетевых обращений (XXE / billion laughs).
 */
final class ZipPackage
{
    private const MAX_ENTRIES = 10000;
    private const MAX_TOTAL_UNCOMPRESSED = 200 * 1024 * 1024;
    private const MAX_ENTRY_BYTES = 64 * 1024 * 1024;

    private \ZipArchive $zip;

    public function __construct(string $path)
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new FileProcessingException('tool_unavailable', 'ext-zip не установлено.');
        }
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
            throw new FileProcessingException('extraction_failed', 'Файл повреждён или не является OOXML-документом.');
        }
        if ($zip->numFiles > self::MAX_ENTRIES) {
            $zip->close();
            throw new FileProcessingException('extraction_failed', 'Слишком много записей в архиве документа.');
        }
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $total += (int) ($stat['size'] ?? 0);
        }
        if ($total > self::MAX_TOTAL_UNCOMPRESSED) {
            $zip->close();
            throw new FileProcessingException('content_too_large', 'Распакованный размер документа превышает лимит.');
        }
        $this->zip = $zip;
    }

    /** @return string[] */
    public function names(): array
    {
        $names = [];
        for ($i = 0; $i < $this->zip->numFiles; $i++) {
            $names[] = (string) $this->zip->getNameIndex($i);
        }

        return $names;
    }

    public function has(string $name): bool
    {
        return $this->zip->locateName($name) !== false;
    }

    public function read(string $name): ?string
    {
        $stat = $this->zip->statName($name);
        if ($stat === false) {
            return null;
        }
        if ((int) $stat['size'] > self::MAX_ENTRY_BYTES) {
            throw new FileProcessingException('content_too_large', 'Часть документа превышает лимит размера.');
        }
        $data = $this->zip->getFromName($name);

        return $data === false ? null : $data;
    }

    public function xml(string $name): ?\DOMDocument
    {
        $data = $this->read($name);
        if ($data === null) {
            return null;
        }
        if (stripos($data, '<!DOCTYPE') !== false || stripos($data, '<!ENTITY') !== false) {
            throw new FileProcessingException('extraction_failed', 'XML документа содержит DOCTYPE/ENTITY — отклонён.');
        }
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $ok = $dom->loadXML($data, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$ok) {
            throw new FileProcessingException('extraction_failed', 'Некорректный XML в документе: ' . $name);
        }

        return $dom;
    }

    public function close(): void
    {
        $this->zip->close();
    }
}
