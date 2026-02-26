<?php

declare(strict_types=1);

namespace App\Shared\Entity;

use App\Shared\Repository\ProxyRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProxyRepository::class)]
#[ORM\Table(name: 'proxies')]
class Proxy
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(length: 500)]
    private string $address;

    #[ORM\Column(length: 20)]
    private string $source = 'admin';

    /** Тип прокси: 'static' (фиксированный IP) или 'rotating' (IP меняется провайдером) */
    #[ORM\Column(length: 20)]
    private string $type = 'static';

    #[ORM\Column(type: 'boolean')]
    private bool $isEnabled = true;


    /** URL для ротации IP (HTTP GET → провайдер переключает IP) */
    #[ORM\Column(name: 'rotation_url', length: 1000, nullable: true)]
    private ?string $rotationUrl = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $address, string $source = 'admin', string $type = 'static')
    {
        $this->address = $address;
        $this->source = $source;
        $this->type = $type;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getAddress(): string { return $this->address; }
    public function setAddress(string $address): self { $this->address = $address; $this->updatedAt = new \DateTimeImmutable(); return $this; }

    public function getSource(): string { return $this->source; }

    public function getType(): string { return $this->type; }
    public function setType(string $type): self { $this->type = $type; $this->updatedAt = new \DateTimeImmutable(); return $this; }

    public function isEnabled(): bool { return $this->isEnabled; }
    public function setEnabled(bool $enabled): self { $this->isEnabled = $enabled; $this->updatedAt = new \DateTimeImmutable(); return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function getRotationUrl(): ?string { return $this->rotationUrl; }
    public function setRotationUrl(?string $url): self { $this->rotationUrl = $url; $this->updatedAt = new \DateTimeImmutable(); return $this; }
}