<?php

declare(strict_types=1);

namespace App\Module\Parser\Worker;

use App\Module\Parser\Config\ParserConfig;
use App\Shared\Contract\MarketplaceApiClientInterface;
use App\Shared\Contract\ReviewParserInterface;
use App\Shared\DTO\ReviewData;

final class ReviewCollector
{
    public function __construct(
        private readonly ParserConfig $parserConfig,
    ) {}

    /**
     * @return ReviewData[]
     */
    public function collect(
        MarketplaceApiClientInterface $apiClient,
        ReviewParserInterface $reviewParser,
        string $slug,
        int $externalId,
    ): array {
        $reviewResponse = $apiClient->fetchReviewsFirstPage($slug, $externalId);
        $reviews = [];
        $pageNum = 1;

        while (true) {
            $pageReviews = $reviewParser->parse($reviewResponse);
            $reviews = array_merge($reviews, $pageReviews);

            $pageNum++;
            if ($pageNum > $this->parserConfig->maxReviewPages) {
                break;
            }

            $nextPageUrl = $reviewParser->getNextPageUrl($reviewResponse);
            if ($nextPageUrl === null) {
                break;
            }

            $reviewResponse = $apiClient->fetchReviewsByNextPage($nextPageUrl);
        }

        return array_map(
            static fn(array $r) => ReviewData::fromArray($r),
            $reviews,
        );
    }
}
