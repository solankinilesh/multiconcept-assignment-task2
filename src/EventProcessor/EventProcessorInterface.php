<?php

declare(strict_types=1);

namespace App\EventProcessor;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Domain-side handler for one (provider, eventType) pair.
 *
 * This is the integration seam where the framework's responsibility ends and the
 * application's begins: a real PaymentSucceededProcessor would update an Order entity,
 * trigger receipts, etc. Adding a new business rule = one new class implementing this
 * interface; no changes to controller, registry, or transport.
 *
 * Implementations MUST be idempotent in their effects: the worker may re-execute the
 * same payload (after a crash mid-processing, or after a retry). Use upserts, idempotency
 * keys on downstream calls, or guard checks before mutating state.
 */
#[AutoconfigureTag('app.event_processor')]
interface EventProcessorInterface
{
    public function getProvider(): string;

    public function getEventType(): string;

    /**
     * @param array<string, mixed> $payload
     */
    public function process(array $payload): void;
}
