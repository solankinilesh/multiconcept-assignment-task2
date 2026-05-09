<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ReceivedWebhookRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Audit log for every inbound webhook attempt — including signature failures.
 *
 * Separate table from ProcessedEvent so that:
 *   - Trimming old audit rows doesn't risk wiping the dedup state.
 *   - We can record requests that failed signature verification (security signal:
 *     repeated invalid signatures from the same IP suggest an attacker).
 *
 * The FK to ProcessedEvent is nullable: signature-failed rows have no ProcessedEvent.
 */
#[ORM\Entity(repositoryClass: ReceivedWebhookRepository::class)]
#[ORM\Table(name: 'received_webhook')]
#[ORM\Index(name: 'idx_received_at_received', columns: ['received_at'])]
#[ORM\Index(name: 'idx_signature_valid', columns: ['signature_valid'])]
final class ReceivedWebhook
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: ProcessedEvent::class)]
    #[ORM\JoinColumn(name: 'processed_event_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?ProcessedEvent $processedEvent;

    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $provider;

    #[ORM\Column(type: Types::TEXT)]
    private string $payload;

    /** @var array<string, list<string|null>> */
    #[ORM\Column(type: Types::JSON)]
    private array $headers;

    #[ORM\Column(type: Types::STRING, length: 45, name: 'ip_address', nullable: true)]
    private ?string $ipAddress;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, name: 'received_at')]
    private \DateTimeImmutable $receivedAt;

    #[ORM\Column(type: Types::BOOLEAN, name: 'signature_valid')]
    private bool $signatureValid;

    /**
     * @param array<string, list<string|null>> $headers
     */
    public function __construct(
        ?ProcessedEvent $processedEvent,
        string $provider,
        string $payload,
        array $headers,
        ?string $ipAddress,
        bool $signatureValid,
    ) {
        $this->id = Uuid::v7();
        $this->processedEvent = $processedEvent;
        $this->provider = $provider;
        $this->payload = $payload;
        $this->headers = $headers;
        $this->ipAddress = $ipAddress;
        $this->signatureValid = $signatureValid;
        $this->receivedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getProcessedEvent(): ?ProcessedEvent
    {
        return $this->processedEvent;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getPayload(): string
    {
        return $this->payload;
    }

    /**
     * @return array<string, list<string|null>>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function getReceivedAt(): \DateTimeImmutable
    {
        return $this->receivedAt;
    }

    public function isSignatureValid(): bool
    {
        return $this->signatureValid;
    }
}
