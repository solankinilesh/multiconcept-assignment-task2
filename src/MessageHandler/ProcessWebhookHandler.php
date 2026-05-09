<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Enum\ProcessedEventStatus;
use App\EventProcessor\EventProcessorRegistry;
use App\Message\ProcessWebhook;
use App\Repository\ProcessedEventRepository;
use App\Repository\ReceivedWebhookRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Worker-side state machine: QUEUED → PROCESSING → (COMPLETED | SKIPPED | FAILED).
 *
 * Re-entry safety: a handler may run more than once for the same ProcessedEvent (worker
 * crashed mid-execution → message retried; or a duplicate was somehow dispatched). If the
 * row is already COMPLETED we log and return early — the underlying processor is also
 * required (by EventProcessorInterface contract) to be idempotent for full safety.
 *
 * Exceptions: rethrown for transient failures so Messenger's retry strategy applies; only
 * UnrecoverableMessageHandlingException short-circuits to the failure transport.
 */
#[AsMessageHandler]
final class ProcessWebhookHandler
{
    public function __construct(
        private readonly ProcessedEventRepository $events,
        private readonly ReceivedWebhookRepository $webhooks,
        private readonly EventProcessorRegistry $processors,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ProcessWebhook $message): void
    {
        $event = $this->events->findOneById($message->processedEventId);
        if (null === $event) {
            // The dedup row was deleted between dispatch and consumption — operator action,
            // most likely. Don't retry, don't crash; just log and move on.
            $this->logger->warning('webhook handler: ProcessedEvent not found, ignoring', [
                'processed_event_id' => (string) $message->processedEventId,
            ]);

            return;
        }

        if (ProcessedEventStatus::Completed === $event->getStatus()) {
            $this->logger->info('webhook handler: already completed, ignoring re-entry', [
                'processed_event_id' => (string) $event->getId(),
            ]);

            return;
        }

        $event->markProcessing();
        $this->em->flush();

        $processor = $this->processors->findProcessor($event->getProvider(), $event->getEventType());
        if (null === $processor) {
            $event->markSkipped();
            $this->em->flush();
            $this->logger->info('webhook handler: no processor for event type, skipped', [
                'provider' => $event->getProvider(),
                'event_type' => $event->getEventType(),
            ]);

            return;
        }

        try {
            $processor->process($this->loadOriginalPayload($event->getId()));
        } catch (UnrecoverableMessageHandlingException $e) {
            $event->markFailed($e);
            $this->em->flush();
            throw $e;
        } catch (\Throwable $e) {
            $event->markFailed($e);
            $this->em->flush();
            $this->logger->error('webhook handler: processor threw', [
                'provider' => $event->getProvider(),
                'event_type' => $event->getEventType(),
                'attempt' => $event->getAttemptCount(),
                'exception' => $e,
            ]);

            throw $e;
        }

        $event->markCompleted();
        $this->em->flush();
    }

    /**
     * The full payload was stored on the audit row (the controller's only chance to capture
     * it before the request body is gone). Re-decoded here so the processor receives the
     * exact bytes we received from the provider.
     *
     * @return array<string, mixed>
     */
    private function loadOriginalPayload(\Symfony\Component\Uid\Uuid $processedEventId): array
    {
        $audit = $this->webhooks->findOneByProcessedEventId($processedEventId);
        if (null === $audit) {
            throw new \RuntimeException(sprintf('No audit row found for ProcessedEvent %s — cannot replay payload to processor.', (string) $processedEventId));
        }

        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($audit->getPayload(), associative: true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Audit payload is not a JSON object.');
        }

        return $decoded;
    }
}
