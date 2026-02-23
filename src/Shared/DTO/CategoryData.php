<?php

declare(strict_types=1);

namespace App\Shared\DTO;

final readonly class CategoryData
{
    public function __construct(
        public int $external_id,
        public string $marketplace,
        public string $name,
        public ?int $parent_id = null,
        public ?int $parent_external_id = null,
        public int $depth = 0,
        public ?string $path = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            external_id: (int) $data['external_id'],
            marketplace: $data['marketplace'] ?? 'ozon',
            name: $data['name'],
            parent_id: isset($data['parent_id']) ? (int) $data['parent_id'] : null,
            parent_external_id: isset($data['parent_external_id']) ? (int) $data['parent_external_id'] : null,
            depth: (int) ($data['depth'] ?? 0),
            path: $data['path'] ?? null,
        );
    }
}
