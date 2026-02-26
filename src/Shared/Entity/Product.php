<?php

declare(strict_types=1);

namespace App\Shared\Entity;

use App\Shared\Entity\Fields\Marketplace;
use App\Shared\Repository\ProductRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProductRepository::class)]
#[ORM\Table(name: 'products')]
#[ORM\HasLifecycleCallbacks]
class Product extends BaseEntity
{
    use Marketplace;

    /** Ссылка на задачу парсинга, создавшую этот товар */
    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $parseTaskId = null;

    #[ORM\Column(type: 'bigint')]
    private int $externalId;

    #[ORM\ManyToOne(targetEntity: Category::class, inversedBy: 'products')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Category $category = null;

    #[ORM\Column(length: 1000)]
    private string $title;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $url = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2, nullable: true)]
    private ?string $price = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2, nullable: true)]
    private ?string $originalPrice = null;

    #[ORM\Column(type: 'decimal', precision: 2, scale: 1, nullable: true)]
    private ?string $rating = null;

    #[ORM\Column(nullable: true)]
    private ?int $reviewCount = 0;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $imageUrl = null;

    #[ORM\Column(type: 'json')]
    private array $imageUrls = [];

    #[ORM\Column(type: 'json')]
    private array $characteristics = [];

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $brand = null;

    #[ORM\OneToMany(targetEntity: Review::class, mappedBy: 'product', cascade: ['remove'])]
    private Collection $reviews;

    public function __construct()
    {
        parent::__construct();
        $this->reviews = new ArrayCollection();
    }

    public function getParseTaskId(): ?string
    {
        return $this->parseTaskId;
    }
    public function getExternalId(): int
    {
        return $this->externalId;
    }
    public function getCategory(): ?Category
    {
        return $this->category;
    }
    public function getTitle(): string
    {
        return $this->title;
    }
    public function getUrl(): ?string
    {
        return $this->url;
    }
    public function getPrice(): ?string
    {
        return $this->price;
    }
    public function getOriginalPrice(): ?string
    {
        return $this->originalPrice;
    }
    public function getRating(): ?string
    {
        return $this->rating;
    }
    public function getReviewCount(): ?int
    {
        return $this->reviewCount;
    }
    public function getImageUrl(): ?string
    {
        return $this->imageUrl;
    }
    public function getImageUrls(): array
    {
        return $this->imageUrls;
    }
    public function getCharacteristics(): array
    {
        return $this->characteristics;
    }
    public function getDescription(): ?string
    {
        return $this->description;
    }
    public function getBrand(): ?string
    {
        return $this->brand;
    }
    public function getReviews(): Collection
    {
        return $this->reviews;
    }
}
