<?php

declare(strict_types=1);

namespace App\EventProcessor\Stripe;

use App\EventProcessor\EventProcessorInterface;
use Psr\Log\LoggerInterface;

final class ChargeRefundedProcessor implements EventProcessorInterface
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function getProvider(): string
    {
        return 'stripe';
    }

    public function getEventType(): string
    {
        return 'charge.refunded';
    }

    public function process(array $payload): void
    {
        $chargeId = $this->extractObjectId($payload);

        // In production: locate the original Order, transition it to REFUNDED, restock
        // inventory, send a customer-facing notification.
        $this->logger->info('stripe charge refunded', [
            'charge_id' => $chargeId,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractObjectId(array $payload): ?string
    {
        $object = $payload['data']['object'] ?? null;
        if (!is_array($object)) {
            return null;
        }

        $id = $object['id'] ?? null;

        return is_string($id) ? $id : null;
    }
}
