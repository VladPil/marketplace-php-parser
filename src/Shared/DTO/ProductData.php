<?php

declare(strict_types=1);

namespace App\Shared\DTO;

final readonly class ProductData
{
    public function __construct(
        public int $external_id,
        public string $marketplace,
        public string $title,
        public ?string $url = null,
        public ?float $price = null,
        public ?float $original_price = null,
        public ?float $rating = null,
        public int $review_count = 0,
        public ?string $image_url = null,
        public array $image_urls = [],
        public array $characteristics = [],
        public ?int $category_id = null,
        public ?string $description = null,
        public ?string $brand = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            external_id: (int) $data['external_id'],
            marketplace: $data['marketplace'] ?? 'ozon',
            title: $data['title'] ?? '',
            url: $data['url'] ?? null,
            price: isset($data['price']) ? (float) $data['price'] : null,
            original_price: isset($data['original_price']) ? (float) $data['original_price'] : null,
            rating: isset($data['rating']) ? (float) $data['rating'] : null,
            review_count: (int) ($data['review_count'] ?? 0),
            image_url: $data['image_url'] ?? null,
            image_urls: $data['image_urls'] ?? [],
            characteristics: $data['characteristics'] ?? [],
            category_id: isset($data['category_id']) ? (int) $data['category_id'] : null,
            description: $data['description'] ?? null,
            brand: $data['brand'] ?? null,
        );
    }
}
