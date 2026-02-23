<?php

declare(strict_types=1);

namespace App\Shared\Entity;

use App\Shared\Repository\ImageRepository;
use Doctrine\ORM\Mapping as ORM;

/** Изображение, привязанное к произвольной сущности (товар, категория и т.д.) */
#[ORM\Entity(repositoryClass: ImageRepository::class)]
#[ORM\Table(name: 'images')]
class Image
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(length: 50)]
    private string $entityType;

    #[ORM\Column(type: 'bigint')]
    private int $entityId;

    #[ORM\Column(type: 'text')]
    private string $url;

    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $parseTaskId = null;

    #[ORM\Column]
    private int $sortOrder = 0;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getEntityType(): string { return $this->entityType; }
    public function getEntityId(): int { return $this->entityId; }
    public function getUrl(): string { return $this->url; }
    public function getParseTaskId(): ?string { return $this->parseTaskId; }
    public function getSortOrder(): int { return $this->sortOrder; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
