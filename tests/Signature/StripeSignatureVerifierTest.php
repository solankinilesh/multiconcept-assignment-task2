<?php

declare(strict_types=1);

namespace App\Tests\Signature;

use App\Exception\InvalidSignatureException;
use App\Signature\StripeSignatureVerifier;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

final class StripeSignatureVerifierTest extends TestCase
{
    private const SECRET = 'whsec_test_super_secret';
    private const BODY = '{"id":"evt_123","type":"payment_intent.succeeded"}';

    #[Test]
    public function passes_for_a_valid_signature_within_the_window(): void
    {
        $verifier = new StripeSignatureVerifier(toleranceSeconds: 300, clock: $this->clockAt(1700000000));
        $headers = $this->headers(timestamp: 1700000000, body: self::BODY, secret: self::SECRET);

        $verifier->verify(self::BODY, $headers, self::SECRET);
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function rejects_a_tampered_body(): void
    {
        $verifier = new StripeSignatureVerifier(clock: $this->clockAt(1700000000));
        // Header is signed for the original body; we hand the verifier a different one.
        $headers = $this->headers(timestamp: 1700000000, body: self::BODY, secret: self::SECRET);

        $this->expectException(InvalidSignatureException::class);
        $verifier->verify(self::BODY.' tampered', $headers, self::SECRET);
    }

    #[Test]
    public function rejects_when_the_secret_does_not_match(): void
    {
        $verifier = new StripeSignatureVerifier(clock: $this->clockAt(1700000000));
        $headers = $this->headers(timestamp: 1700000000, body: self::BODY, secret: 'wrong_secret');

        $this->expectException(InvalidSignatureException::class);
        $verifier->verify(self::BODY, $headers, self::SECRET);
    }

    #[Test]
    public function rejects_a_timestamp_older_than_the_tolerance_window(): void
    {
        $verifier = new StripeSignatureVerifier(toleranceSeconds: 300, clock: $this->clockAt(1700001000));
        $headers = $this->headers(timestamp: 1700000000, body: self::BODY, secret: self::SECRET);

        $this->expectException(InvalidSignatureException::class);
        $this->expectExceptionMessage('tolerance window');
        $verifier->verify(self::BODY, $headers, self::SECRET);
    }

    #[Test]
    public function rejects_a_future_dated_timestamp_outside_the_window(): void
    {
        // Defensive: replay protection is two-sided, so a stolen-secret attacker can't
        // mint signatures dated years into the future to bypass retention-based defences.
        $verifier = new StripeSignatureVerifier(toleranceSeconds: 300, clock: $this->clockAt(1700000000));
        $headers = $this->headers(timestamp: 1700001000, body: self::BODY, secret: self::SECRET);

        $this->expectException(InvalidSignatureException::class);
        $this->expectExceptionMessage('tolerance window');
        $verifier->verify(self::BODY, $headers, self::SECRET);
    }

    #[Test]
    public function rejects_a_request_with_no_signature_header(): void
    {
        $verifier = new StripeSignatureVerifier();

        $this->expectException(InvalidSignatureException::class);
        $this->expectExceptionMessage('Missing Stripe-Signature');
        $verifier->verify(self::BODY, [], self::SECRET);
    }

    #[Test]
    public function rejects_a_malformed_header(): void
    {
        $verifier = new StripeSignatureVerifier();

        $this->expectException(InvalidSignatureException::class);
        $this->expectExceptionMessage('malformed');
        $verifier->verify(self::BODY, ['stripe-signature' => ['this-is-garbage']], self::SECRET);
    }

    #[Test]
    public function accepts_when_any_of_multiple_v1_signatures_matches(): void
    {
        // Stripe sends multiple v1 values during secret rotation; we must accept if ANY matches.
        $verifier = new StripeSignatureVerifier(clock: $this->clockAt(1700000000));
        $valid = hash_hmac('sha256', '1700000000.'.self::BODY, self::SECRET);
        $headers = ['stripe-signature' => [sprintf('t=1700000000,v1=%s,v1=%s', str_repeat('0', 64), $valid)]];

        $verifier->verify(self::BODY, $headers, self::SECRET);
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function uses_constant_time_comparison(): void
    {
        // We can't directly observe timing, but we can assert the source uses hash_equals,
        // which is the documented constant-time primitive in PHP. Detecting absence of this
        // call would catch a regression to == or strcmp.
        $source = file_get_contents(__DIR__.'/../../src/Signature/StripeSignatureVerifier.php');
        self::assertNotFalse($source);
        self::assertStringContainsString('hash_equals(', $source);
    }

    private function clockAt(int $timestamp): ClockInterface
    {
        return new class($timestamp) implements ClockInterface {
            public function __construct(private readonly int $timestamp)
            {
            }

            public function now(): \DateTimeImmutable
            {
                return (new \DateTimeImmutable())->setTimestamp($this->timestamp);
            }
        };
    }

    /**
     * @return array<string, list<string>>
     */
    private function headers(int $timestamp, string $body, string $secret): array
    {
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

        return ['stripe-signature' => [sprintf('t=%d,v1=%s', $timestamp, $signature)]];
    }
}
