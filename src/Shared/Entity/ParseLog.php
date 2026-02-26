<?php

declare(strict_types=1);

namespace App\Shared\Entity;

use App\Shared\Repository\ParseLogRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Запись лога парсинга.
 *
 * Хранит детальную информацию о каждом шаге парсинга
 * с привязкой к trace_id для сквозной трассировки.
 */
#[ORM\Entity(repositoryClass: ParseLogRepository::class)]
#[ORM\Table(name: 'parse_logs')]
class ParseLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    /** Идентификатор трассировки для сквозного отслеживания запроса */
    #[ORM\Column(length: 64)]
    private string $traceId;

    /** Ссылка на задачу парсинга (может отсутствовать для системных логов) */
    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $parseTaskId = null;

    /** Ссылка на запуск задачи (для группировки логов по запускам) */
    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $runId = null;

    /** Уровень лога: debug, info, warning, error */
    #[ORM\Column(length: 10)]
    private string $level = 'info';

    /** Канал лога: parser, admin, solver, system */
    #[ORM\Column(length: 50)]
    private string $channel = 'parser';

    /** Текст сообщения лога */
    #[ORM\Column(type: 'text')]
    private string $message;

    /** Дополнительный контекст в формате JSON */
    #[ORM\Column(type: 'json')]
    private array $context = [];

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }
    public function getTraceId(): string
    {
        return $this->traceId;
    }
    public function setTraceId(string $traceId): self
    {
        $this->traceId = $traceId;
        return $this;
    }
    public function getParseTaskId(): ?string
    {
        return $this->parseTaskId;
    }
    public function setParseTaskId(?string $parseTaskId): self
    {
        $this->parseTaskId = $parseTaskId;
        return $this;
    }
    public function getLevel(): string
    {
        return $this->level;
    }
    public function setLevel(string $level): self
    {
        $this->level = $level;
        return $this;
    }
    public function getChannel(): string
    {
        return $this->channel;
    }
    public function setChannel(string $channel): self
    {
        $this->channel = $channel;
        return $this;
    }
    public function getMessage(): string
    {
        return $this->message;
    }
    public function setMessage(string $message): self
    {
        $this->message = $message;
        return $this;
    }
    public function getContext(): array
    {
        return $this->context;
    }
    public function setContext(array $context): self
    {
        $this->context = $context;
        return $this;
    }
    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
    public function getRunId(): ?string
    {
        return $this->runId;
    }
    public function setRunId(?string $runId): self
    {
        $this->runId = $runId;
        return $this;
    }
}
