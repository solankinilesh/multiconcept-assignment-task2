<?php

declare(strict_types=1);

namespace App\EventProcessor;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Looks up the EventProcessor for a given (provider, eventType).
 *
 * Returns null when no processor is registered — the handler treats that as SKIPPED, not
 * as an error. Webhook providers send many event types we don't care about; quietly
 * ignoring them is correct, not a bug.
 */
final class EventProcessorRegistry
{
    /** @var array<string, EventProcessorInterface> */
    private array $processors = [];

    /**
     * @param iterable<EventProcessorInterface> $processors
     */
    public function __construct(
        #[AutowireIterator('app.event_processor')]
        iterable $processors,
    ) {
        foreach ($processors as $processor) {
            $this->processors[$this->key($processor->getProvider(), $processor->getEventType())] = $processor;
        }
    }

    public function findProcessor(string $provider, string $eventType): ?EventProcessorInterface
    {
        return $this->processors[$this->key($provider, $eventType)] ?? null;
    }

    private function key(string $provider, string $eventType): string
    {
        return $provider.':'.$eventType;
    }
}
