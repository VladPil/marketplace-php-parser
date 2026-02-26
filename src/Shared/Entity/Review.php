<?php

declare(strict_types=1);

namespace App\Shared\Entity;

use App\Shared\Entity\Fields\CreatedAt;
use App\Shared\Entity\Fields\Id;
use App\Shared\Entity\Fields\Marketplace;
use App\Shared\Repository\ReviewRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ReviewRepository::class)]
#[ORM\Table(name: 'reviews')]
class Review
{
    use Id;
    use Marketplace;
    use CreatedAt;

    /** Ссылка на задачу парсинга, создавшую этот отзыв */
    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $parseTaskId = null;

    #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'reviews')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Product $product;

    #[ORM\Column(type: 'string', length: 100)]
    private string $externalReviewId;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $author = null;

    #[ORM\Column]
    private int $rating;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $text = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $pros = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $cons = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $reviewDate = null;

    #[ORM\Column(type: 'json')]
    private array $imageUrls = [];

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $firstReply = null;

    public function __construct()
    {
        $this->initCreatedAt();
    }

    public function getParseTaskId(): ?string
    {
        return $this->parseTaskId;
    }
    public function getProduct(): Product
    {
        return $this->product;
    }
    public function getExternalReviewId(): string
    {
        return $this->externalReviewId;
    }
    public function getAuthor(): ?string
    {
        return $this->author;
    }
    public function getRating(): int
    {
        return $this->rating;
    }
    public function getText(): ?string
    {
        return $this->text;
    }
    public function getPros(): ?string
    {
        return $this->pros;
    }
    public function getCons(): ?string
    {
        return $this->cons;
    }
    public function getReviewDate(): ?\DateTimeImmutable
    {
        return $this->reviewDate;
    }
    public function getImageUrls(): array
    {
        return $this->imageUrls;
    }
    public function getFirstReply(): ?string
    {
        return $this->firstReply;
    }
}
