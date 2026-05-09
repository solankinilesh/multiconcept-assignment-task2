<?php

declare(strict_types=1);

namespace App\Signature;

use App\Exception\InvalidSignatureException;
use Psr\Clock\ClockInterface;

/**
 * Implements Stripe's webhook signature scheme.
 *
 * Header format: `Stripe-Signature: t=1492774577,v1=5257a869...,v1=...`
 *
 * Verification:
 *   1. Parse the header into timestamp + one-or-more `v1` signatures (the scheme allows
 *      multiple signatures during secret rotation).
 *   2. Reject if `now - timestamp > toleranceSeconds` (replay protection).
 *   3. Compute HMAC-SHA256 of `{timestamp}.{rawBody}` with the secret.
 *   4. Constant-time compare against each `v1` value; pass if any match.
 *
 * See https://docs.stripe.com/webhooks#verify-manually for the spec we mirror.
 */
final class StripeSignatureVerifier implements SignatureVerifierInterface
{
    public const HEADER = 'stripe-signature';
    private const SCHEME = 'v1';

    public function __construct(
        private readonly int $toleranceSeconds = 300,
        private readonly ?ClockInterface $clock = null,
    ) {
    }

    public function verify(string $rawBody, array $headers, string $secret): void
    {
        $header = $this->extractHeader($headers);
        [$timestamp, $signatures] = $this->parseHeader($header);

        $now = $this->clock?->now()->getTimestamp() ?? time();
        // Two-sided window: rejects past replays AND future-dated timestamps. The future
        // case isn't theoretical — an attacker who learns the secret could otherwise mint
        // signatures dated years ahead and bypass any retention-based defence.
        if (abs($now - $timestamp) > $this->toleranceSeconds) {
            throw new InvalidSignatureException('Signature timestamp is outside the tolerance window.');
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);

        foreach ($signatures as $candidate) {
            if (hash_equals($expected, $candidate)) {
                return;
            }
        }

        throw new InvalidSignatureException('No signature in the header matched the computed value.');
    }

    /**
     * @param array<string, list<string|null>|string> $headers
     */
    private function extractHeader(array $headers): string
    {
        // Symfony lowercases header names in HeaderBag::all(); also accept the raw casing
        // and a plain string for direct callers (tests) that didn't go through the bag.
        $value = $headers[self::HEADER] ?? $headers['Stripe-Signature'] ?? null;
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        if (!is_string($value) || '' === $value) {
            throw new InvalidSignatureException('Missing Stripe-Signature header.');
        }

        return $value;
    }

    /**
     * @return array{0: int, 1: list<string>}
     */
    private function parseHeader(string $header): array
    {
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            $kv = explode('=', $part, 2);
            if (2 !== count($kv)) {
                continue;
            }
            [$key, $value] = $kv;
            if ('t' === $key) {
                $timestamp = $value;
            } elseif (self::SCHEME === $key) {
                $signatures[] = $value;
            }
        }

        if (null === $timestamp || !ctype_digit($timestamp) || [] === $signatures) {
            throw new InvalidSignatureException('Stripe-Signature header is malformed.');
        }

        return [(int) $timestamp, $signatures];
    }
}
