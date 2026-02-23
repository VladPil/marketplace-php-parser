<?php

declare(strict_types=1);

namespace App\Shared\DTO;

final readonly class ReviewData
{
    public function __construct(
        public string $external_review_id,
        public string $marketplace,
        public ?string $author = null,
        public int $rating = 0,
        public ?string $text = null,
        public ?string $pros = null,
        public ?string $cons = null,
        public ?string $review_date = null,
        public array $image_urls = [],
        public ?string $first_reply = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            external_review_id: (string) $data['external_review_id'],
            marketplace: $data['marketplace'] ?? 'ozon',
            author: $data['author'] ?? null,
            rating: (int) ($data['rating'] ?? 0),
            text: $data['text'] ?? null,
            pros: $data['pros'] ?? null,
            cons: $data['cons'] ?? null,
            review_date: $data['review_date'] ?? null,
            image_urls: $data['image_urls'] ?? [],
            first_reply: $data['first_reply'] ?? null,
        );
    }
}
