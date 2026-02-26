<?php

declare(strict_types=1);

namespace App\Module\Parser\Marketplace;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

use App\Shared\Contract\CategoryExtractorInterface;
use App\Shared\Contract\MarketplaceApiClientInterface;
use App\Shared\Contract\ProductParserInterface;
use App\Shared\Contract\ReviewParserInterface;
use App\Shared\Contract\SearchParserInterface;

#[AutoconfigureTag('app.marketplace')]
interface MarketplaceInterface
{
    public function getName(): string;
    public function getApiClient(): MarketplaceApiClientInterface;
    public function getProductParser(): ProductParserInterface;
    public function getReviewParser(): ReviewParserInterface;
    public function getSearchParser(): SearchParserInterface;
    public function getCategoryExtractor(): CategoryExtractorInterface;
}
