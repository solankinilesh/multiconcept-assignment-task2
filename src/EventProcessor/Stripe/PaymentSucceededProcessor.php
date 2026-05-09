<?php

declare(strict_types=1);

namespace App\EventProcessor\Stripe;

use App\EventProcessor\EventProcessorInterface;
use Psr\Log\LoggerInterface;

final class PaymentSucceededProcessor implements EventProcessorInterface
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
        return 'payment_intent.succeeded';
    }

    public function process(array $payload): void
    {
        $paymentIntentId = $this->extractObjectId($payload);

        // In production: load Order by payment_intent id, mark PAID, dispatch a
        // SendReceiptEmail message, kick off fulfillment. Kept intentionally tiny here:
        // the integration seam is the interface, not this method's body.
        $this->logger->info('stripe payment succeeded', [
            'payment_intent_id' => $paymentIntentId,
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
