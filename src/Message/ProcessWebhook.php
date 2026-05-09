<?php

declare(strict_types=1);

namespace App\Message;

use Symfony\Component\Uid\Uuid;

/**
 * Async command to process a previously-persisted webhook.
 *
 * Carries only the ProcessedEvent id — the handler reloads the row to get a fresh view of
 * status/attemptCount, since the worker may pick up the message after a delay or retry.
 */
final readonly class ProcessWebhook
{
    public function __construct(
        public Uuid $processedEventId,
    ) {
    }
}
