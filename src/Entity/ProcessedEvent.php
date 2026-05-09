<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ProcessedEventStatus;
use App\Repository\ProcessedEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Idempotency record — one row per (provider, externalEventId).
 *
 * The unique constraint on (provider, external_event_id) is the SOURCE OF TRUTH for
 * "have we seen this event before?". The controller relies on it to win/lose the race
 * between concurrent deliveries — whichever insert wins becomes canonical, the other
 * catches UniqueConstraintViolationException and returns 200 immediately.
 *
 * Kept separate from ReceivedWebhook (the audit log) so we can prune the audit history
 * without losing dedup state.
 */
#[ORM\Entity(repositoryClass: ProcessedEventRepository::class)]
#[ORM\Table(name: 'processed_event')]
#[ORM\UniqueConstraint(name: 'uniq_provider_external_event', columns: ['provider', 'external_event_id'])]
#[ORM\Index(name: 'idx_status', columns: ['status'])]
#[ORM\Index(name: 'idx_received_at', columns: ['received_at'])]
final class ProcessedEvent
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $provider;

    #[ORM\Column(type: Types::STRING, length: 255, name: 'external_event_id')]
    private string $externalEventId;

    #[ORM\Column(type: Types::STRING, length: 128, name: 'event_type')]
    private string $eventType;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: ProcessedEventStatus::class)]
    private ProcessedEventStatus $status;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, name: 'received_at')]
    private \DateTimeImmutable $receivedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, name: 'processed_at', nullable: true)]
    private ?\DateTimeImmutable $processedAt = null;

    #[ORM\Column(type: Types::INTEGER, name: 'attempt_count', options: ['default' => 0])]
    private int $attemptCount = 0;

    #[ORM\Column(type: Types::TEXT, name: 'last_error', nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column(type: Types::STRING, length: 255, name: 'last_error_class', nullable: true)]
    private ?string $lastErrorClass = null;

    public function __construct(string $provider, string $externalEventId, string $eventType)
    {
        $this->id = Uuid::v7();
        $this->provider = $provider;
        $this->externalEventId = $externalEventId;
        $this->eventType = $eventType;
        $this->status = ProcessedEventStatus::Queued;
        $this->receivedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getExternalEventId(): string
    {
        return $this->externalEventId;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function getStatus(): ProcessedEventStatus
    {
        return $this->status;
    }

    public function getReceivedAt(): \DateTimeImmutable
    {
        return $this->receivedAt;
    }

    public function getProcessedAt(): ?\DateTimeImmutable
    {
        return $this->processedAt;
    }

    public function getAttemptCount(): int
    {
        return $this->attemptCount;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function getLastErrorClass(): ?string
    {
        return $this->lastErrorClass;
    }

    public function markProcessing(): void
    {
        $this->status = ProcessedEventStatus::Processing;
        ++$this->attemptCount;
    }

    public function markCompleted(): void
    {
        $this->status = ProcessedEventStatus::Completed;
        $this->processedAt = new \DateTimeImmutable();
        $this->lastError = null;
        $this->lastErrorClass = null;
    }

    public function markSkipped(): void
    {
        $this->status = ProcessedEventStatus::Skipped;
        $this->processedAt = new \DateTimeImmutable();
    }

    public function markFailed(\Throwable $exception): void
    {
        $this->status = ProcessedEventStatus::Failed;
        $this->lastError = $exception->getMessage();
        $this->lastErrorClass = $exception::class;
    }
}
