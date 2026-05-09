<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Thrown by the provider registry when no provider matches the URL slug. Translated to
 * HTTP 404 — distinct from a malformed payload because the URL itself is wrong.
 */
final class UnknownProviderException extends \RuntimeException
{
    public static function forName(string $name): self
    {
        return new self(sprintf('Unknown webhook provider "%s".', $name));
    }
}
