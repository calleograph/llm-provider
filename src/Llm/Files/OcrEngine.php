<?php

declare(strict_types=1);

namespace App\Llm\Files;

/**
 * Локальный OCR через tesseract. Изображение не покидает сервер.
 */
final class OcrEngine
{
    public function __construct(private readonly FileProcessingSettings $settings)
    {
    }

    public function isAvailable(): bool
    {
        return $this->settings->ocrEnabled() && ProcessRunner::isExecutable($this->settings->get('tesseract_path'));
    }

    /**
     * @throws FileProcessingException
     */
    public function recognize(string $imagePath): string
    {
        if (!$this->isAvailable()) {
            throw new FileProcessingException('ocr_required_unavailable', 'OCR отключён или tesseract не найден.');
        }

        $result = ProcessRunner::run(
            [$this->settings->get('tesseract_path'), $imagePath, 'stdout', '-l', $this->settings->get('ocr_languages')],
            max(5, (int) $this->settings->get('tool_timeout')),
        );
        if ($result['exit'] !== 0) {
            throw new FileProcessingException('ocr_failed', 'tesseract exit=' . $result['exit'] . ': ' . $result['stderr']);
        }

        return TextNormalizer::toUtf8($result['stdout']);
    }
}
