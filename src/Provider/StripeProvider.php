<?php

declare(strict_types=1);

namespace App\Provider;

use App\Exception\MalformedPayloadException;
use App\Signature\SignatureVerifierInterface;
use App\Signature\StripeSignatureVerifier;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class StripeProvider implements WebhookProviderInterface
{
    public function __construct(
        private readonly StripeSignatureVerifier $signatureVerifier,
        #[Autowire('%env(WEBHOOK_STRIPE_SECRET)%')]
        private readonly string $signingSecret,
    ) {
    }

    public function getName(): string
    {
        return 'stripe';
    }

    public function getSignatureVerifier(): SignatureVerifierInterface
    {
        return $this->signatureVerifier;
    }

    public function getSigningSecret(): string
    {
        return $this->signingSecret;
    }

    public function extractEventId(array $payload): string
    {
        $id = $payload['id'] ?? null;
        if (!is_string($id) || '' === $id) {
            throw new MalformedPayloadException('Stripe payload missing string `id`.');
        }

        return $id;
    }

    public function extractEventType(array $payload): string
    {
        $type = $payload['type'] ?? null;
        if (!is_string($type) || '' === $type) {
            throw new MalformedPayloadException('Stripe payload missing string `type`.');
        }

        return $type;
    }
}
