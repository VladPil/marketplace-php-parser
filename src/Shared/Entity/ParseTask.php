<?php

declare(strict_types=1);

namespace App\Shared\Entity;

use App\Shared\Entity\Fields\CreatedAt;
use App\Shared\Entity\Fields\Marketplace;
use App\Shared\Entity\Fields\UpdatedAt;
use App\Shared\Repository\ParseTaskRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ParseTaskRepository::class)]
#[ORM\Table(name: 'parse_tasks')]
#[ORM\HasLifecycleCallbacks]
class ParseTask
{
    use Marketplace;
    use CreatedAt;
    use UpdatedAt;

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private ?string $id = null;

    #[ORM\Column(length: 50)]
    private string $type;

    #[ORM\Column(type: 'json')]
    private array $params = [];

    #[ORM\Column(length: 20)]
    private string $status = 'pending';

    #[ORM\Column(nullable: true)]
    private ?int $totalItems = 0;

    #[ORM\Column(nullable: true)]
    private ?int $parsedItems = 0;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $resumeState = null;

    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $batchId = null;

    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $parentTaskId = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    public function __construct()
    {
        $this->id = \Symfony\Component\Uid\Uuid::v4()->toRfc4122();
        $this->initCreatedAt();
        $this->initUpdatedAt();
    }

    public function getId(): ?string { return $this->id; }
    public function getType(): string { return $this->type; }
    public function setType(string $type): self { $this->type = $type; return $this; }
    public function getParams(): array { return $this->params; }
    public function setParams(array $params): self { $this->params = $params; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self { $this->status = $status; return $this; }
    public function getTotalItems(): ?int { return $this->totalItems; }
    public function getParsedItems(): ?int { return $this->parsedItems; }
    public function getErrorMessage(): ?string { return $this->errorMessage; }
    public function getBatchId(): ?string { return $this->batchId; }
    public function setBatchId(?string $batchId): self { $this->batchId = $batchId; return $this; }
    public function getParentTaskId(): ?string { return $this->parentTaskId; }
    public function setParentTaskId(?string $parentTaskId): self { $this->parentTaskId = $parentTaskId; return $this; }
    public function getStartedAt(): ?\DateTimeImmutable { return $this->startedAt; }
    public function getCompletedAt(): ?\DateTimeImmutable { return $this->completedAt; }
}
