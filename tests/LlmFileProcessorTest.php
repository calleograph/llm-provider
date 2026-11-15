<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config\AppSettings;
use App\Llm\Files\FileProcessingException;
use App\Llm\Files\FileProcessingSettings;
use App\Llm\Files\FileProcessor;
use PHPUnit\Framework\TestCase;

/**
 * Локальное извлечение текста: форматы, проверка сигнатур, fail closed и
 * обязательное удаление временных файлов.
 */
final class LlmFileProcessorTest extends TestCase
{
    private string $tmpRoot;
    private string $sourceDir;
    private FileProcessor $processor;
    private FileProcessingSettings $settings;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('ext-pdo_sqlite недоступно.');
        }
        $base = sys_get_temp_dir() . '/llm-test-' . bin2hex(random_bytes(6));
        $this->tmpRoot = $base . '/work';
        $this->sourceDir = $base . '/src';
        mkdir($this->sourceDir, 0700, true);

        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE app_settings (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
        $this->settings = new FileProcessingSettings(new AppSettings($pdo));
        // Инструменты заведомо отсутствуют — проверяем fail closed для сканов/PDF.
        $this->settings->save(['pdftotext_path' => '/nonexistent/pdftotext', 'pdftoppm_path' => '/nonexistent/pdftoppm', 'tesseract_path' => '/nonexistent/tesseract']);
        $this->processor = FileProcessor::withDefaults($this->settings, $this->tmpRoot);
    }

    protected function tearDown(): void
    {
        if (isset($this->sourceDir)) {
            foreach (glob($this->sourceDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->sourceDir);
            @rmdir($this->tmpRoot);
            @rmdir(dirname($this->sourceDir));
        }
    }

    private function file(string $name, string $content): string
    {
        $path = $this->sourceDir . '/' . $name;
        file_put_contents($path, $content);

        return $path;
    }

    private function process(string $path): \App\Llm\Files\ExtractedFile
    {
        return $this->processor->process($path, basename($path), (int) filesize($path), false);
    }

    private function assertNoTempLeft(): void
    {
        $this->assertSame([], glob($this->tmpRoot . '/*') ?: [], 'Временные файлы должны удаляться');
    }

    /** @param array<string,string> $entries */
    private function zip(string $name, array $entries): string
    {
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ext-zip недоступно.');
        }
        $path = $this->sourceDir . '/' . $name;
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($entries as $entry => $content) {
            $zip->addFromString($entry, $content);
        }
        $zip->close();

        return $path;
    }

    public function testTxtInWindows1251IsConverted(): void
    {
        $result = $this->process($this->file('note.txt', mb_convert_encoding("Служебная записка\r\nИванов", 'Windows-1251', 'UTF-8')));

        $this->assertSame("Служебная записка\nИванов", $result->text);
        $this->assertSame('txt', $result->extension);
        $this->assertNoTempLeft();
    }

    public function testCsv(): void
    {
        $result = $this->process($this->file('data.csv', "\xEF\xBB\xBFФИО;Сумма\nИванов;100\n"));

        $this->assertSame("ФИО;Сумма\nИванов;100", $result->text);
    }

    public function testDocxParagraphsTablesAndHeaders(): void
    {
        $ns = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"';
        $path = $this->zip('doc.docx', [
            '[Content_Types].xml' => '<?xml version="1.0"?><Types/>',
            'word/document.xml' => '<?xml version="1.0"?><w:document ' . $ns . '><w:body>'
                . '<w:p><w:r><w:t>Приказ</w:t></w:r><w:r><w:tab/><w:t>№ 5</w:t></w:r></w:p>'
                . '<w:tbl><w:tr><w:tc><w:p><w:r><w:t>Иванов</w:t></w:r></w:p></w:tc></w:tr></w:tbl>'
                . '</w:body></w:document>',
            'word/header1.xml' => '<?xml version="1.0"?><w:hdr ' . $ns . '><w:p><w:r><w:t>ООО Ромашка</w:t></w:r></w:p></w:hdr>',
        ]);

        $result = $this->process($path);

        $this->assertStringContainsString("Приказ\t№ 5", $result->text);
        $this->assertStringContainsString('Иванов', $result->text);
        $this->assertStringContainsString('ООО Ромашка', $result->text);
        $this->assertNoTempLeft();
    }

    public function testDocxWithDoctypeIsRejected(): void
    {
        $path = $this->zip('xxe.docx', [
            'word/document.xml' => '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><w:document>&e;</w:document>',
        ]);

        $this->expectException(FileProcessingException::class);
        $this->process($path);
    }

    public function testScannedDocxWithoutOcrIsBlocked(): void
    {
        $ns = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"';
        $path = $this->zip('scan.docx', [
            'word/document.xml' => '<?xml version="1.0"?><w:document ' . $ns . '><w:body><w:p/></w:body></w:document>',
            'word/media/image1.png' => "\x89PNG\r\n\x1a\n" . str_repeat("\0", 32),
        ]);

        try {
            $this->process($path);
            $this->fail('Ожидалась блокировка');
        } catch (FileProcessingException $e) {
            $this->assertSame('ocr_required_unavailable', $e->errorCode);
        }
        $this->assertNoTempLeft();
    }

    public function testXlsxSheetsSharedStringsAndColumns(): void
    {
        $ns = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"';
        $rel = 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"';
        $path = $this->zip('book.xlsx', [
            'xl/workbook.xml' => '<?xml version="1.0"?><workbook ' . $ns . ' ' . $rel . '><sheets><sheet name="Сотрудники" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml" Type="x"/></Relationships>',
            'xl/sharedStrings.xml' => '<?xml version="1.0"?><sst ' . $ns . '><si><t>ФИО</t></si><si><r><t>Иван</t></r><r><t>ов</t></r></si></sst>',
            'xl/worksheets/sheet1.xml' => '<?xml version="1.0"?><worksheet ' . $ns . '><sheetData>'
                . '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="C1" t="inlineStr"><is><t>Оклад</t></is></c></row>'
                . '<row r="2"><c r="A2" t="s"><v>1</v></c><c r="C2"><v>100500</v></c></row>'
                . '</sheetData></worksheet>',
        ]);

        $result = $this->process($path);

        $this->assertSame("Лист: Сотрудники\nФИО\t\tОклад\nИванов\t\t100500", $result->text);
    }

    public function testPdfWithoutToolsIsBlocked(): void
    {
        try {
            $this->process($this->file('doc.pdf', "%PDF-1.4\n%fake"));
            $this->fail('Ожидалась блокировка');
        } catch (FileProcessingException $e) {
            $this->assertSame('tool_unavailable', $e->errorCode);
        }
        $this->assertNoTempLeft();
    }

    public function testImageWithoutOcrIsBlocked(): void
    {
        $this->expectException(FileProcessingException::class);
        $this->process($this->file('scan.png', "\x89PNG\r\n\x1a\n" . str_repeat("\0", 32)));
    }

    public function testSignatureMismatchIsRejected(): void
    {
        try {
            $this->process($this->file('fake.docx', 'просто текст'));
            $this->fail('Ожидалась ошибка');
        } catch (FileProcessingException $e) {
            $this->assertSame('signature_mismatch', $e->errorCode);
        }
        $this->assertNoTempLeft();
    }

    public function testBinaryDisguisedAsTxtIsRejected(): void
    {
        $this->expectException(FileProcessingException::class);
        $this->process($this->file('bin.txt', "MZ\0\0\x90\0binary"));
    }

    public function testUnsupportedExtensionAndLimits(): void
    {
        try {
            $this->process($this->file('script.exe', 'x'));
            $this->fail('Ожидалась ошибка');
        } catch (FileProcessingException $e) {
            $this->assertSame('unsupported_type', $e->errorCode);
        }

        $this->settings->save(['max_upload_mb' => '1']);
        try {
            $this->processor->process($this->file('big.txt', 'x'), 'big.txt', 2 * 1024 * 1024, false);
            $this->fail('Ожидалась ошибка');
        } catch (FileProcessingException $e) {
            $this->assertSame('too_large', $e->errorCode);
        }
    }

    public function testEmptyContentIsBlocked(): void
    {
        $this->expectException(FileProcessingException::class);
        $this->process($this->file('empty.txt', " \n\t "));
    }
}
