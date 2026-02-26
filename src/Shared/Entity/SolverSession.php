<?php

declare(strict_types=1);

namespace App\Shared\Entity;

use App\Shared\Repository\SolverSessionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Запись сессии solver — хранит cookies и метаданные полученной сессии.
 *
 * Используется для аудита и отладки: показывает какие cookies были
 * получены для каждой задачи парсинга, позволяет воспроизвести
 * запрос через curl.
 */
#[ORM\Entity(repositoryClass: SolverSessionRepository::class)]
#[ORM\Table(name: 'solver_sessions')]
class SolverSession
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    /** Ссылка на задачу парсинга */
    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $parseTaskId = null;

    /** Cookies в формате JSON */
    #[ORM\Column(type: 'json')]
    private array $cookies = [];

    /** User-Agent использованного браузера */
    #[ORM\Column(type: 'text')]
    private string $userAgent = '';

    /** Client Hints из браузера */
    #[ORM\Column(type: 'json')]
    private array $clientHints = [];

    /** Идентификатор прокси (URL или 'direct') */
    #[ORM\Column(length: 255)]
    private string $proxy = 'direct';

    /** Статус: success или error */
    #[ORM\Column(length: 20)]
    private string $status = 'success';

    /** Сообщение об ошибке (если status=error) */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $errorMessage = null;

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

    public function getParseTaskId(): ?string
    {
        return $this->parseTaskId;
    }
    public function setParseTaskId(?string $parseTaskId): self
    {
        $this->parseTaskId = $parseTaskId;
        return $this;
    }

    public function getCookies(): array
    {
        return $this->cookies;
    }
    public function setCookies(array $cookies): self
    {
        $this->cookies = $cookies;
        return $this;
    }

    public function getUserAgent(): string
    {
        return $this->userAgent;
    }
    public function setUserAgent(string $userAgent): self
    {
        $this->userAgent = $userAgent;
        return $this;
    }

    public function getClientHints(): array
    {
        return $this->clientHints;
    }
    public function setClientHints(array $clientHints): self
    {
        $this->clientHints = $clientHints;
        return $this;
    }

    public function getProxy(): string
    {
        return $this->proxy;
    }
    public function setProxy(string $proxy): self
    {
        $this->proxy = $proxy;
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

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }
    public function setErrorMessage(?string $errorMessage): self
    {
        $this->errorMessage = $errorMessage;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
