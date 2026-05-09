<?php

declare(strict_types=1);

namespace App\Signature;

use App\Exception\InvalidSignatureException;

/**
 * Verifies a webhook request was actually sent by the provider it claims to come from.
 *
 * Implementations MUST:
 *   - Operate on the raw, unparsed request body (json_decode mutates whitespace and
 *     re-encoding produces a different byte string than the provider signed).
 *   - Use a constant-time comparison (hash_equals) to prevent timing-based oracles.
 *   - Throw InvalidSignatureException — never return false. The controller treats
 *     anything thrown as 401 and logs the attempt; we want one clear failure path.
 */
interface SignatureVerifierInterface
{
    /**
     * @param array<string, list<string|null>> $headers request headers as returned by
     *                                                  Symfony's HeaderBag::all() — keys
     *                                                  are lowercased, values are lists
     *
     * @throws InvalidSignatureException
     */
    public function verify(string $rawBody, array $headers, string $secret): void;
}
