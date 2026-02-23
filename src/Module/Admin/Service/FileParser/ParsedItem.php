<?php

declare(strict_types=1);

namespace App\Module\Admin\Service\FileParser;

/**
 * Результат парсинга одной строки/записи файла.
 */
final readonly class ParsedItem
{
    public function __construct(
        public ?int $externalId,
        public ?string $slug,
        public ?string $url,
        public string $rawValue,
        public int $lineNumber,
        public ?string $error = null,
    ) {}

    public function isValid(): bool
    {
        return $this->error === null && ($this->externalId !== null || $this->slug !== null);
    }

    /**
     * Определяет тип распознанного значения для отображения в UI.
     */
    public function getType(): string
    {
        if ($this->url !== null) {
            return 'url';
        }
        if ($this->slug !== null) {
            return 'slug';
        }
        if ($this->externalId !== null) {
            return 'id';
        }

        return 'unknown';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'externalId' => $this->externalId,
            'slug' => $this->slug,
            'url' => $this->url,
            'rawValue' => $this->rawValue,
            'lineNumber' => $this->lineNumber,
            'type' => $this->getType(),
            'valid' => $this->isValid(),
            'error' => $this->error,
        ];
    }
}
