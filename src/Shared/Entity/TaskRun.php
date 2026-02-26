<?php

declare(strict_types=1);

namespace App\Shared\Entity;

use App\Shared\Repository\TaskRunRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Запуск задачи парсинга.
 *
 * Одна задача может иметь несколько запусков (runs).
 * Статус задачи определяется статусом последнего запуска.
 * Логи привязываются к конкретному запуску через run_id.
 */
#[ORM\Entity(repositoryClass: TaskRunRepository::class)]
#[ORM\Table(name: 'task_runs')]
class TaskRun
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private ?string $id = null;

    #[ORM\Column(type: 'guid')]
    private string $taskId;

    #[ORM\Column(type: 'integer')]
    private int $runNumber = 1;

    #[ORM\Column(length: 20)]
    private string $status = 'pending';

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(type: 'integer')]
    private int $parsedItems = 0;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $error = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $identityId = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->id = \Symfony\Component\Uid\Uuid::v4()->toRfc4122();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getTaskId(): string
    {
        return $this->taskId;
    }

    public function setTaskId(string $taskId): self
    {
        $this->taskId = $taskId;
        return $this;
    }

    public function getRunNumber(): int
    {
        return $this->runNumber;
    }

    public function setRunNumber(int $runNumber): self
    {
        $this->runNumber = $runNumber;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(?\DateTimeImmutable $startedAt): self
    {
        $this->startedAt = $startedAt;
        return $this;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function setFinishedAt(?\DateTimeImmutable $finishedAt): self
    {
        $this->finishedAt = $finishedAt;
        return $this;
    }

    public function getParsedItems(): int
    {
        return $this->parsedItems;
    }

    public function setParsedItems(int $parsedItems): self
    {
        $this->parsedItems = $parsedItems;
        return $this;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function setError(?string $error): self
    {
        $this->error = $error;
        return $this;
    }

    public function getIdentityId(): ?string
    {
        return $this->identityId;
    }

    public function setIdentityId(?string $identityId): self
    {
        $this->identityId = $identityId;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
