<?php

declare(strict_types=1);

namespace App\Tests\EventProcessor;

use App\Entity\ProcessedEvent;
use App\Entity\ReceivedWebhook;
use App\Enum\ProcessedEventStatus;
use App\EventProcessor\EventProcessorInterface;
use App\Message\ProcessWebhook;
use App\MessageHandler\ProcessWebhookHandler;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class ProcessWebhookHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ProcessWebhookHandler $handler;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->handler = self::getContainer()->get(ProcessWebhookHandler::class);
    }

    #[Test]
    public function marks_completed_when_a_processor_runs_successfully(): void
    {
        $event = $this->seed(
            provider: 'stripe',
            externalEventId: 'evt_completed',
            eventType: 'payment_intent.succeeded',
            payload: ['id' => 'evt_completed', 'type' => 'payment_intent.succeeded', 'data' => ['object' => ['id' => 'pi_1']]],
        );

        ($this->handler)(new ProcessWebhook($event->getId()));

        $reloaded = $this->reload($event->getId());
        self::assertSame(ProcessedEventStatus::Completed, $reloaded->getStatus());
        self::assertNotNull($reloaded->getProcessedAt());
        self::assertSame(1, $reloaded->getAttemptCount());
    }

    #[Test]
    public function marks_skipped_when_no_processor_is_registered_for_the_event_type(): void
    {
        // `customer.created` has no processor wired, so the handler should treat this as
        // a known-uninteresting event — SKIPPED, not FAILED.
        $event = $this->seed(
            provider: 'stripe',
            externalEventId: 'evt_skipped',
            eventType: 'customer.created',
            payload: ['id' => 'evt_skipped', 'type' => 'customer.created'],
        );

        ($this->handler)(new ProcessWebhook($event->getId()));

        $reloaded = $this->reload($event->getId());
        self::assertSame(ProcessedEventStatus::Skipped, $reloaded->getStatus());
        self::assertNotNull($reloaded->getProcessedAt());
    }

    #[Test]
    public function marks_failed_and_rethrows_when_a_processor_throws(): void
    {
        // Register a processor that always throws, by adding it to the registry on the fly.
        // We swap the registered Stripe processor for a throwing one for THIS test only.
        $this->registerThrowingProcessor('stripe', 'invoice.payment_failed', new \RuntimeException('downstream HTTP 503'));

        $event = $this->seed(
            provider: 'stripe',
            externalEventId: 'evt_failed',
            eventType: 'invoice.payment_failed',
            payload: ['id' => 'evt_failed', 'type' => 'invoice.payment_failed'],
        );

        try {
            ($this->handler)(new ProcessWebhook($event->getId()));
            self::fail('handler should have rethrown so messenger retries');
        } catch (\RuntimeException $e) {
            self::assertSame('downstream HTTP 503', $e->getMessage());
        }

        $reloaded = $this->reload($event->getId());
        self::assertSame(ProcessedEventStatus::Failed, $reloaded->getStatus());
        self::assertSame('downstream HTTP 503', $reloaded->getLastError());
        self::assertSame(\RuntimeException::class, $reloaded->getLastErrorClass());
        self::assertSame(1, $reloaded->getAttemptCount());
    }

    #[Test]
    public function is_safe_to_re_invoke_for_a_completed_event(): void
    {
        $event = $this->seed(
            provider: 'stripe',
            externalEventId: 'evt_reentry',
            eventType: 'payment_intent.succeeded',
            payload: ['id' => 'evt_reentry', 'type' => 'payment_intent.succeeded', 'data' => ['object' => ['id' => 'pi_2']]],
        );

        $message = new ProcessWebhook($event->getId());
        ($this->handler)($message);
        ($this->handler)($message); // second pass should be a no-op

        $reloaded = $this->reload($event->getId());
        self::assertSame(ProcessedEventStatus::Completed, $reloaded->getStatus());
        self::assertSame(1, $reloaded->getAttemptCount(), 'second invocation must not re-run the processor');
    }

    #[Test]
    public function does_nothing_when_the_processed_event_id_is_unknown(): void
    {
        // Operator deleted the row between dispatch and consumption, or message arrived
        // before persistence finished. We log and move on — no exception.
        ($this->handler)(new ProcessWebhook(Uuid::v7()));
        $this->expectNotToPerformAssertions();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function seed(string $provider, string $externalEventId, string $eventType, array $payload): ProcessedEvent
    {
        $event = new ProcessedEvent($provider, $externalEventId, $eventType);
        $this->em->persist($event);

        $audit = new ReceivedWebhook(
            processedEvent: $event,
            provider: $provider,
            payload: json_encode($payload, JSON_THROW_ON_ERROR),
            headers: ['content-type' => ['application/json']],
            ipAddress: '127.0.0.1',
            signatureValid: true,
        );
        $this->em->persist($audit);
        $this->em->flush();

        return $event;
    }

    private function reload(Uuid $id): ProcessedEvent
    {
        $this->em->clear();
        $reloaded = $this->em->getRepository(ProcessedEvent::class)->find($id);
        self::assertNotNull($reloaded);

        return $reloaded;
    }

    private function registerThrowingProcessor(string $provider, string $eventType, \Throwable $error): void
    {
        // Rebuild a registry containing only our throwing processor and re-inject it into
        // the handler. We do this by constructing a fresh handler with the swapped registry,
        // since the real registry is built at compile time.
        $throwingProcessor = new class($provider, $eventType, $error) implements EventProcessorInterface {
            public function __construct(
                private readonly string $provider,
                private readonly string $eventType,
                private readonly \Throwable $error,
            ) {
            }

            public function getProvider(): string
            {
                return $this->provider;
            }

            public function getEventType(): string
            {
                return $this->eventType;
            }

            public function process(array $payload): void
            {
                throw $this->error;
            }
        };

        $this->handler = new ProcessWebhookHandler(
            self::getContainer()->get('App\\Repository\\ProcessedEventRepository'),
            self::getContainer()->get('App\\Repository\\ReceivedWebhookRepository'),
            new \App\EventProcessor\EventProcessorRegistry([$throwingProcessor]),
            $this->em,
            self::getContainer()->get('logger'),
        );
    }
}
