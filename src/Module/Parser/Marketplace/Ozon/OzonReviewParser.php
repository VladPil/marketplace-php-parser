<?php

declare(strict_types=1);

namespace App\Module\Parser\Marketplace\Ozon;

use App\Shared\Contract\ReviewParserInterface;

/**
 * Парсер отзывов Ozon.
 *
 * Поддерживает два формата ответа API:
 * 1. webListReviews — основной формат (один виджет с массивом reviews)
 * 2. webReviewItem — устаревший формат (отдельный виджет на каждый отзыв)
 */
final class OzonReviewParser implements ReviewParserInterface
{
    public function __construct(
        private readonly OzonWidgetExtractor $widgetExtractor,
    ) {}

    public function parse(array $response): array
    {
        $widgets = $this->widgetExtractor->extractWidgets($response);

        // Основной формат: webListReviews
        $listWidget = $this->widgetExtractor->findWidget($widgets, 'webListReviews');
        if ($listWidget !== null) {
            return $this->parseListReviews($listWidget);
        }

        // Фолбек: старый формат webReviewItem
        $reviewWidgets = $this->widgetExtractor->findWidgets($widgets, 'webReviewItem');
        $reviews = [];
        foreach ($reviewWidgets as $widget) {
            $review = $this->parseLegacyReview($widget);
            if ($review !== null) {
                $reviews[] = $review;
            }
        }

        return $reviews;
    }

    public function hasNextPage(array $response): bool
    {
        return isset($response['nextPage']) && $response['nextPage'] !== '';
    }

    public function getNextPageUrl(array $response): ?string
    {
        $nextPage = $response['nextPage'] ?? null;

        if ($nextPage === null || $nextPage === '') {
            return null;
        }

        return $nextPage;
    }

    /**
     * Парсит отзывы из виджета webListReviews.
     */
    private function parseListReviews(array $widget): array
    {
        $reviews = [];

        foreach ($widget['reviews'] ?? [] as $item) {
            $review = $this->parseListReviewItem($item);
            if ($review !== null) {
                $reviews[] = $review;
            }
        }

        return $reviews;
    }

    /**
     * Парсит один отзыв из массива reviews[] в webListReviews.
     */
    private function parseListReviewItem(array $item): ?array
    {
        $uuid = $item['uuid'] ?? null;
        if ($uuid === null || $uuid === '') {
            return null;
        }

        $content = $item['content'] ?? [];

        // Дата из unix timestamp
        $date = null;
        $publishedAt = $item['publishedAt'] ?? null;
        if ($publishedAt !== null) {
            try {
                if (is_numeric($publishedAt)) {
                    $date = (new \DateTimeImmutable('@' . $publishedAt))->format('Y-m-d H:i:s');
                } else {
                    $date = (new \DateTimeImmutable($publishedAt))->format('Y-m-d H:i:s');
                }
            } catch (\Throwable) {
                $date = null;
            }
        }

        // Фото из content.photos
        $imageUrls = [];
        foreach ($content['photos'] ?? [] as $photo) {
            $url = $photo['url'] ?? null;
            if ($url !== null && $url !== '') {
                $imageUrls[] = $url;
            }
        }

        // Первый ответ из comments.list[0].text
        $firstReply = null;
        $comments = $item['comments'] ?? [];
        $commentList = $comments['list'] ?? [];
        if (!empty($commentList)) {
            $firstReply = $this->cleanText($commentList[0]['text'] ?? null);
        }

        // Автор
        $author = trim(($item['author']['firstName'] ?? '') . ' ' . ($item['author']['lastName'] ?? ''));
        if ($author === '') {
            $author = $item['author']['name'] ?? 'Аноним';
        }

        return [
            'external_review_id' => (string) $uuid,
            'marketplace' => 'ozon',
            'author' => trim($author),
            'rating' => (int) ($content['score'] ?? $item['score'] ?? 0),
            'text' => $this->cleanText($content['comment'] ?? null),
            'pros' => $this->cleanText($content['positive'] ?? null),
            'cons' => $this->cleanText($content['negative'] ?? null),
            'review_date' => $date,
            'image_urls' => $imageUrls,
            'first_reply' => $firstReply,
        ];
    }

    /**
     * Парсит отзыв в устаревшем формате webReviewItem.
     */
    private function parseLegacyReview(array $widget): ?array
    {
        $reviewId = $widget['id'] ?? null;
        if ($reviewId === null) {
            return null;
        }

        $date = null;
        if (!empty($widget['date'])) {
            try {
                $date = (new \DateTimeImmutable($widget['date']))->format('Y-m-d H:i:s');
            } catch (\Throwable) {
                $date = null;
            }
        }

        $imageUrls = [];
        foreach ($widget['photos'] ?? [] as $photo) {
            if (!empty($photo['url'])) {
                $imageUrls[] = $photo['url'];
            }
        }

        return [
            'external_review_id' => (string) $reviewId,
            'marketplace' => 'ozon',
            'author' => trim($widget['author'] ?? 'Аноним'),
            'rating' => (int) ($widget['score'] ?? 0),
            'text' => $this->cleanText(($widget['comment'] ?? [])['text'] ?? null),
            'pros' => $this->cleanText(($widget['positive'] ?? [])['text'] ?? null),
            'cons' => $this->cleanText(($widget['negative'] ?? [])['text'] ?? null),
            'review_date' => $date,
            'image_urls' => $imageUrls,
            'first_reply' => $this->cleanText(($widget['reply'] ?? [])['text'] ?? null),
        ];
    }

    private function cleanText(mixed $text): ?string
    {
        if ($text === null || !is_string($text) || trim($text) === '') {
            return null;
        }
        return trim($text);
    }
}
