<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Thrown when the request body isn't valid JSON or is missing the fields the provider
 * needs (event id, event type). Translated to HTTP 400 — providers will retry on 5xx but
 * usually NOT on 4xx, which is what we want for a malformed payload.
 */
final class MalformedPayloadException extends \RuntimeException
{
}
