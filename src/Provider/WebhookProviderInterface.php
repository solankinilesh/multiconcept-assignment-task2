<?php

declare(strict_types=1);

namespace App\Provider;

use App\Exception\MalformedPayloadException;
use App\Signature\SignatureVerifierInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One implementation per supported source (Stripe, Mailgun, …).
 *
 * Adding a third provider should be exactly one new class implementing this interface
 * — autoconfiguration picks it up via the tag, no YAML edits required.
 *
 * Each provider owns its identity (name → URL slug), its signature scheme + secret, and
 * the rules for finding event id / event type inside the provider-specific payload shape.
 */
#[AutoconfigureTag('app.webhook_provider')]
interface WebhookProviderInterface
{
    /**
     * Slug used in the URL: `/webhooks/{name}`. Lowercase, no spaces.
     */
    public function getName(): string;

    public function getSignatureVerifier(): SignatureVerifierInterface;

    /**
     * Secret used to verify signatures. Loaded from env at construction time so the
     * controller never sees raw env values.
     */
    public function getSigningSecret(): string;

    /**
     * @param array<string, mixed> $payload
     *
     * @throws MalformedPayloadException when the expected field is missing
     */
    public function extractEventId(array $payload): string;

    /**
     * @param array<string, mixed> $payload
     *
     * @throws MalformedPayloadException
     */
    public function extractEventType(array $payload): string;
}
