<?php

declare(strict_types=1);

namespace App\EventProcessor\Mailgun;

use App\EventProcessor\EventProcessorInterface;
use Psr\Log\LoggerInterface;

final class EmailBouncedProcessor implements EventProcessorInterface
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function getProvider(): string
    {
        return 'mailgun';
    }

    public function getEventType(): string
    {
        // Mailgun's "permanently failed" event — what most platforms call a hard bounce.
        return 'failed';
    }

    public function process(array $payload): void
    {
        $recipient = $this->extractRecipient($payload);

        // In production: mark the EmailAddress entity as undeliverable, suppress further
        // sends to it, surface in the user's account UI. The `severity` field on Mailgun's
        // `failed` event distinguishes permanent vs temporary; we'd respect that.
        $this->logger->info('mailgun email bounced', [
            'recipient' => $recipient,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractRecipient(array $payload): ?string
    {
        $eventData = $payload['event-data'] ?? null;
        if (!is_array($eventData)) {
            return null;
        }

        $recipient = $eventData['recipient'] ?? null;

        return is_string($recipient) ? $recipient : null;
    }
}
