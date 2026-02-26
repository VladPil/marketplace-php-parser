<?php

declare(strict_types=1);

namespace App\Shared\Entity;

use App\Shared\Entity\Fields\CreatedAt;
use App\Shared\Entity\Fields\Id;
use App\Shared\Entity\Fields\Marketplace;
use App\Shared\Repository\CategoryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CategoryRepository::class)]
#[ORM\Table(name: 'categories')]
class Category
{
    use Id;
    use Marketplace;
    use CreatedAt;

    #[ORM\Column(type: 'bigint')]
    private int $externalId;

    #[ORM\Column(length: 500)]
    private string $name;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?self $parent = null;

    #[ORM\Column]
    private int $depth = 0;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $path = null;

    #[ORM\Column(nullable: true)]
    private ?int $productCount = 0;

    /** Ссылка на задачу парсинга, создавшую/обновившую категорию */
    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $parseTaskId = null;

    #[ORM\OneToMany(targetEntity: Product::class, mappedBy: 'category')]
    private Collection $products;

    public function __construct()
    {
        $this->initCreatedAt();
        $this->products = new ArrayCollection();
    }

    public function getExternalId(): int
    {
        return $this->externalId;
    }
    public function getName(): string
    {
        return $this->name;
    }
    public function getParent(): ?self
    {
        return $this->parent;
    }
    public function getDepth(): int
    {
        return $this->depth;
    }
    public function getPath(): ?string
    {
        return $this->path;
    }
    public function getProductCount(): ?int
    {
        return $this->productCount;
    }
    public function getParseTaskId(): ?string
    {
        return $this->parseTaskId;
    }
    public function getProducts(): Collection
    {
        return $this->products;
    }
}
