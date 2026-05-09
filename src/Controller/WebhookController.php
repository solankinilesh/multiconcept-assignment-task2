<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ProcessedEvent;
use App\Entity\ReceivedWebhook;
use App\Exception\InvalidSignatureException;
use App\Exception\MalformedPayloadException;
use App\Exception\UnknownProviderException;
use App\Message\ProcessWebhook;
use App\Provider\WebhookProviderInterface;
use App\Provider\WebhookProviderRegistry;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The single inbound endpoint. Designed to be FAST: verify, dedupe, persist, dispatch,
 * respond. All heavy lifting (downstream calls, business logic) happens in the worker.
 *
 * Flow per request:
 *   1. Resolve the provider from the URL slug                    → 404 if unknown
 *   2. Verify the signature against the raw body                 → 401 if invalid (audited)
 *   3. Decode JSON, extract event id + event type                → 400 if malformed
 *   4. Within ONE Doctrine transaction:
 *        a. INSERT processed_event (unique-constraint check)     → 200 duplicate on conflict
 *        b. INSERT received_webhook (audit)
 *        c. Dispatch ProcessWebhook to the messenger transport
 *           — Doctrine messenger transport writes to messenger_messages on the same
 *             connection, so the row insert + queue insert commit atomically (transactional
 *             outbox). A bus-dispatch failure rolls back the dedup row, so the provider's
 *             retry is treated as a first-time delivery rather than silently lost.
 *   5. Respond 200 accepted
 *
 * Idempotency is enforced by the unique index on processed_event(provider, external_event_id).
 * Two concurrent deliveries race at the database — one INSERT wins, the other catches
 * UniqueConstraintViolationException and returns a duplicate response. No application-level
 * locking, no distributed cache; the constraint IS the lock.
 */
final class WebhookController
{
    public function __construct(
        private readonly WebhookProviderRegistry $providers,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(
        path: '/webhooks/{provider}',
        name: 'webhook_receive',
        requirements: ['provider' => '[a-z0-9_-]+'],
        methods: ['POST'],
    )]
    public function receive(string $provider, Request $request): JsonResponse
    {
        try {
            $providerService = $this->providers->get($provider);
        } catch (UnknownProviderException) {
            return new JsonResponse(['error' => 'unknown provider'], 404);
        }

        $rawBody = $request->getContent();
        $headers = $request->headers->all();
        $ipAddress = $request->getClientIp();

        try {
            $providerService->getSignatureVerifier()->verify(
                $rawBody,
                $headers,
                $providerService->getSigningSecret(),
            );
        } catch (InvalidSignatureException $e) {
            $this->logger->warning('webhook signature rejected', [
                'provider' => $providerService->getName(),
                'ip' => $ipAddress,
                'reason' => $e->getMessage(),
            ]);
            $this->persistInvalidSignatureAudit($providerService, $rawBody, $headers, $ipAddress);

            return new JsonResponse(['error' => 'invalid signature'], 401);
        }

        try {
            $payload = $this->decodePayload($rawBody);
            $eventId = $providerService->extractEventId($payload);
            $eventType = $providerService->extractEventType($payload);
        } catch (MalformedPayloadException $e) {
            $this->logger->warning('webhook payload malformed', [
                'provider' => $providerService->getName(),
                'reason' => $e->getMessage(),
            ]);

            return new JsonResponse(['error' => 'malformed payload'], 400);
        }

        try {
            $processedEvent = $this->persistAndDispatch(
                $providerService,
                $eventId,
                $eventType,
                $rawBody,
                $headers,
                $ipAddress,
            );
        } catch (UniqueConstraintViolationException) {
            // Idempotent replay (provider retry, or two-worker race). The first writer won;
            // we just acknowledge so the provider stops retrying.
            $this->logger->info('webhook duplicate, acknowledging', [
                'provider' => $providerService->getName(),
                'event_id' => $eventId,
            ]);

            return new JsonResponse(['status' => 'duplicate', 'event_id' => $eventId], 200);
        } catch (\Throwable $e) {
            // DB connection drop, deadlock, transport down, etc. The transaction has rolled
            // back, so no row was committed — provider's retry will be treated as fresh.
            // Return 5xx so the provider DOES retry; never lose the event silently.
            $this->logger->error('webhook persistence failed, asking provider to retry', [
                'provider' => $providerService->getName(),
                'event_id' => $eventId,
                'exception' => $e,
            ]);

            return new JsonResponse(['error' => 'temporary server error'], 503);
        }

        return new JsonResponse(['status' => 'accepted', 'event_id' => $eventId], 200);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws MalformedPayloadException
     */
    private function decodePayload(string $rawBody): array
    {
        if ('' === $rawBody) {
            throw new MalformedPayloadException('Request body is empty.');
        }

        try {
            $decoded = json_decode($rawBody, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new MalformedPayloadException('Request body is not valid JSON: '.$e->getMessage(), previous: $e);
        }

        if (!is_array($decoded)) {
            throw new MalformedPayloadException('Request body must decode to a JSON object.');
        }

        /* @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Persists the dedup row + audit row + queue message in ONE transaction (transactional
     * outbox). Either everything commits or nothing does — no orphaned dedup rows whose
     * worker message vanished, and no queued messages whose dedup row was rolled back.
     *
     * @param array<string, list<string|null>> $headers
     */
    private function persistAndDispatch(
        WebhookProviderInterface $providerService,
        string $eventId,
        string $eventType,
        string $rawBody,
        array $headers,
        ?string $ipAddress,
    ): ProcessedEvent {
        $this->em->beginTransaction();
        try {
            $processedEvent = new ProcessedEvent($providerService->getName(), $eventId, $eventType);
            $this->em->persist($processedEvent);
            $this->em->flush();

            $audit = new ReceivedWebhook(
                processedEvent: $processedEvent,
                provider: $providerService->getName(),
                payload: $rawBody,
                headers: $headers,
                ipAddress: $ipAddress,
                signatureValid: true,
            );
            $this->em->persist($audit);
            $this->em->flush();

            // Doctrine messenger transport writes to messenger_messages on the SAME connection,
            // so this dispatch participates in our transaction.
            $this->bus->dispatch(new ProcessWebhook($processedEvent->getId()));

            $this->em->commit();
        } catch (\Throwable $e) {
            if ($this->em->getConnection()->isTransactionActive()) {
                $this->em->rollback();
            }

            throw $e;
        }

        return $processedEvent;
    }

    /**
     * @param array<string, list<string|null>> $headers
     */
    private function persistInvalidSignatureAudit(
        WebhookProviderInterface $providerService,
        string $rawBody,
        array $headers,
        ?string $ipAddress,
    ): void {
        // Best-effort: never let an audit-write failure mask the 401 we owe the caller.
        try {
            $audit = new ReceivedWebhook(
                processedEvent: null,
                provider: $providerService->getName(),
                payload: $rawBody,
                headers: $headers,
                ipAddress: $ipAddress,
                signatureValid: false,
            );
            $this->em->persist($audit);
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->logger->error('failed to write invalid-signature audit row', [
                'exception' => $e,
            ]);
        }
    }
}
