<?php

declare(strict_types=1);

namespace App\Tests\Signature;

use App\Exception\InvalidSignatureException;
use App\Signature\HmacSignatureVerifier;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HmacSignatureVerifierTest extends TestCase
{
    private const SECRET = 'mailgun_test_secret';
    private const BODY = '{"event-data":{"id":"evt_abc","event":"failed"}}';
    private const HEADER = 'X-Mailgun-Signature';

    #[Test]
    public function passes_for_a_valid_signature(): void
    {
        $verifier = new HmacSignatureVerifier(self::HEADER);
        $headers = [strtolower(self::HEADER) => [hash_hmac('sha256', self::BODY, self::SECRET)]];

        $verifier->verify(self::BODY, $headers, self::SECRET);
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function rejects_a_tampered_body(): void
    {
        $verifier = new HmacSignatureVerifier(self::HEADER);
        $headers = [strtolower(self::HEADER) => [hash_hmac('sha256', self::BODY, self::SECRET)]];

        $this->expectException(InvalidSignatureException::class);
        $verifier->verify(self::BODY.'tamper', $headers, self::SECRET);
    }

    #[Test]
    public function rejects_when_the_secret_does_not_match(): void
    {
        $verifier = new HmacSignatureVerifier(self::HEADER);
        $headers = [strtolower(self::HEADER) => [hash_hmac('sha256', self::BODY, 'wrong')]];

        $this->expectException(InvalidSignatureException::class);
        $verifier->verify(self::BODY, $headers, self::SECRET);
    }

    #[Test]
    public function rejects_a_request_with_no_signature_header(): void
    {
        $verifier = new HmacSignatureVerifier(self::HEADER);

        $this->expectException(InvalidSignatureException::class);
        $this->expectExceptionMessage(self::HEADER);
        $verifier->verify(self::BODY, [], self::SECRET);
    }

    #[Test]
    public function accepts_either_lowercased_or_canonical_header_casing(): void
    {
        // HeaderBag::all() returns lowercased keys; some callers (tests) use original case.
        $signature = hash_hmac('sha256', self::BODY, self::SECRET);
        $verifier = new HmacSignatureVerifier(self::HEADER);

        $verifier->verify(self::BODY, [strtolower(self::HEADER) => [$signature]], self::SECRET);
        $verifier->verify(self::BODY, [self::HEADER => [$signature]], self::SECRET);
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function uses_constant_time_comparison(): void
    {
        $source = file_get_contents(__DIR__.'/../../src/Signature/HmacSignatureVerifier.php');
        self::assertNotFalse($source);
        self::assertStringContainsString('hash_equals(', $source);
    }
}
