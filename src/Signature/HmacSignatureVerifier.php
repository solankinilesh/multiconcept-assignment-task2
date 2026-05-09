<?php

declare(strict_types=1);

namespace App\Signature;

use App\Exception\InvalidSignatureException;

/**
 * Generic HMAC-SHA256 verifier — body is signed directly with the shared secret and the
 * resulting hex digest is sent in a single header. Used by Mailgun-style providers.
 *
 * Configurable header name (Mailgun uses `X-Mailgun-Signature`, others differ) so this
 * class is reusable for any "raw HMAC of body in a header" scheme without subclassing.
 */
final class HmacSignatureVerifier implements SignatureVerifierInterface
{
    public function __construct(
        private readonly string $headerName,
        private readonly string $algorithm = 'sha256',
    ) {
    }

    public function verify(string $rawBody, array $headers, string $secret): void
    {
        $signature = $this->extractHeader($headers);
        $expected = hash_hmac($this->algorithm, $rawBody, $secret);

        if (!hash_equals($expected, $signature)) {
            throw new InvalidSignatureException('HMAC signature did not match the computed value.');
        }
    }

    /**
     * @param array<string, list<string|null>|string> $headers
     */
    private function extractHeader(array $headers): string
    {
        $lower = strtolower($this->headerName);
        $value = $headers[$lower] ?? $headers[$this->headerName] ?? null;
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        if (!is_string($value) || '' === $value) {
            throw new InvalidSignatureException(sprintf('Missing %s header.', $this->headerName));
        }

        return $value;
    }
}
