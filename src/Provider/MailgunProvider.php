<?php

declare(strict_types=1);

namespace App\Provider;

use App\Exception\MalformedPayloadException;
use App\Signature\HmacSignatureVerifier;
use App\Signature\SignatureVerifierInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class MailgunProvider implements WebhookProviderInterface
{
    private readonly HmacSignatureVerifier $signatureVerifier;

    public function __construct(
        #[Autowire('%env(WEBHOOK_MAILGUN_SECRET)%')]
        private readonly string $signingSecret,
    ) {
        // Header name is provider-specific; the HmacSignatureVerifier itself is generic
        // and reusable, so we configure it here rather than registering a Mailgun-specific
        // verifier service in the container.
        $this->signatureVerifier = new HmacSignatureVerifier(headerName: 'X-Mailgun-Signature');
    }

    public function getName(): string
    {
        return 'mailgun';
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
        $id = $payload['event-data']['id'] ?? null;
        if (!is_string($id) || '' === $id) {
            throw new MalformedPayloadException('Mailgun payload missing string `event-data.id`.');
        }

        return $id;
    }

    public function extractEventType(array $payload): string
    {
        $event = $payload['event-data']['event'] ?? null;
        if (!is_string($event) || '' === $event) {
            throw new MalformedPayloadException('Mailgun payload missing string `event-data.event`.');
        }

        return $event;
    }
}
