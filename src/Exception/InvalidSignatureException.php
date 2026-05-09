<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Thrown by signature verifiers when the request's signature does not match what we
 * compute from the raw body and the configured secret. Translated to HTTP 401 by the
 * controller — never leak the reason to the caller (no oracle for forging).
 */
final class InvalidSignatureException extends \RuntimeException
{
}
