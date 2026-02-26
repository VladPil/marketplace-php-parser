<?php

declare(strict_types=1);

namespace App\Module\Parser\Marketplace\Ozon;

use App\Module\Parser\Marketplace\MarketplaceInterface;
use App\Shared\Contract\CategoryExtractorInterface;
use App\Shared\Contract\MarketplaceApiClientInterface;
use App\Shared\Contract\ProductParserInterface;
use App\Shared\Contract\ReviewParserInterface;

final class OzonMarketplace implements MarketplaceInterface
{
    public function __construct(
        private readonly OzonApiClient $apiClient,
        private readonly OzonProductParser $productParser,
        private readonly OzonReviewParser $reviewParser,
        private readonly OzonCategoryExtractor $categoryExtractor,
    ) {}
    public function getName(): string
    {
        return 'ozon';
    }

    public function getApiClient(): MarketplaceApiClientInterface
    {
        return $this->apiClient;
    }

    public function getProductParser(): ProductParserInterface
    {
        return $this->productParser;
    }

    public function getReviewParser(): ReviewParserInterface
    {
        return $this->reviewParser;
    }

    public function getCategoryExtractor(): CategoryExtractorInterface
    {
        return $this->categoryExtractor;
    }
}
