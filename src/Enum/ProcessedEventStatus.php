<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Lifecycle of a webhook from controller acceptance through worker completion.
 *
 * QUEUED → PROCESSING → (COMPLETED | SKIPPED | FAILED)
 *
 * SKIPPED means we received and accepted the event but have no processor registered for
 * its type — that's normal: providers send many event types we don't care about. Distinct
 * from FAILED so operators can filter real problems from boring no-ops.
 */
enum ProcessedEventStatus: string
{
    case Queued = 'QUEUED';
    case Processing = 'PROCESSING';
    case Completed = 'COMPLETED';
    case Skipped = 'SKIPPED';
    case Failed = 'FAILED';
}
