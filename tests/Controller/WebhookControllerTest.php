<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ProcessedEvent;
use App\Entity\ReceivedWebhook;
use App\Enum\ProcessedEventStatus;
use App\Message\ProcessWebhook;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class WebhookControllerTest extends WebTestCase
{
    private const STRIPE_SECRET = 'whsec_test_only_stripe';
    private const MAILGUN_SECRET = 'whsec_test_only_mailgun';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    #[Test]
    public function returns_404_for_an_unknown_provider(): void
    {
        $this->client->request('POST', '/webhooks/paypal', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        self::assertJsonResponse(['error' => 'unknown provider'], $this->client->getResponse()->getContent());
    }

    #[Test]
    public function returns_401_when_the_signature_is_invalid(): void
    {
        $body = '{"id":"evt_x","type":"payment_intent.succeeded"}';
        $this->client->request(
            'POST',
            '/webhooks/stripe',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => 't='.time().',v1='.str_repeat('0', 64),
            ],
            content: $body,
        );

        self::assertSame(401, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, $this->countProcessedEvents(), 'invalid signature must NOT create a ProcessedEvent');
        self::assertSame(1, $this->countAuditRows(signatureValid: false), 'but it MUST audit the attempt');
    }

    #[Test]
    public function returns_400_for_a_malformed_payload(): void
    {
        $body = 'not json at all';
        $this->client->request(
            'POST',
            '/webhooks/stripe',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => $this->stripeSignature($body),
            ],
            content: $body,
        );

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, $this->countProcessedEvents());
    }

    #[Test]
    public function accepts_a_valid_first_time_event_and_dispatches_async(): void
    {
        $body = json_encode(['id' => 'evt_first', 'type' => 'payment_intent.succeeded']);
        self::assertNotFalse($body);

        $this->client->request(
            'POST',
            '/webhooks/stripe',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => $this->stripeSignature($body),
            ],
            content: $body,
        );

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertJsonResponse(
            ['status' => 'accepted', 'event_id' => 'evt_first'],
            $this->client->getResponse()->getContent(),
        );

        $events = $this->em->getRepository(ProcessedEvent::class)->findAll();
        self::assertCount(1, $events);
        self::assertSame('evt_first', $events[0]->getExternalEventId());
        self::assertSame(ProcessedEventStatus::Queued, $events[0]->getStatus());

        self::assertCount(1, $this->em->getRepository(ReceivedWebhook::class)->findAll());

        // The in-memory transport keeps dispatched envelopes for inspection.
        $transport = self::getContainer()->get('messenger.transport.webhooks');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $sent = $transport->getSent();
        self::assertCount(1, $sent);
        self::assertInstanceOf(ProcessWebhook::class, $sent[0]->getMessage());
    }

    #[Test]
    public function returns_duplicate_for_a_second_delivery_of_the_same_event_id(): void
    {
        $body = json_encode(['id' => 'evt_dup', 'type' => 'charge.refunded']);
        self::assertNotFalse($body);
        $signature = $this->stripeSignature($body);

        $first = $this->postStripeEvent($body, $signature);
        self::assertSame(200, $first->getStatusCode());
        self::assertStringContainsString('"status":"accepted"', (string) $first->getContent());

        // Reset the EntityManager — the controller's beginTransaction()/commit() inside the
        // first request leaves identity-map state that would interfere with a second hit.
        $this->em->clear();

        $second = $this->postStripeEvent($body, $signature);
        self::assertSame(200, $second->getStatusCode());
        self::assertJsonResponse(
            ['status' => 'duplicate', 'event_id' => 'evt_dup'],
            $second->getContent(),
        );

        self::assertSame(1, $this->countProcessedEvents(), 'duplicate must not create a second row');
    }

    #[Test]
    public function pre_existing_row_is_treated_as_duplicate(): void
    {
        // Simulates the race: another process already wrote the row before us. The flush
        // should hit the unique index, the controller catches it, returns 200 duplicate.
        $existing = new ProcessedEvent('stripe', 'evt_race', 'payment_intent.succeeded');
        $this->em->persist($existing);
        $this->em->flush();
        $this->em->clear();

        $body = json_encode(['id' => 'evt_race', 'type' => 'payment_intent.succeeded']);
        self::assertNotFalse($body);

        $response = $this->postStripeEvent($body, $this->stripeSignature($body));
        self::assertSame(200, $response->getStatusCode());
        self::assertJsonResponse(
            ['status' => 'duplicate', 'event_id' => 'evt_race'],
            $response->getContent(),
        );
        self::assertSame(1, $this->countProcessedEvents());
    }

    #[Test]
    public function mailgun_provider_works_end_to_end(): void
    {
        $body = json_encode(['event-data' => ['id' => 'mg_evt_1', 'event' => 'failed']]);
        self::assertNotFalse($body);

        $this->client->request(
            'POST',
            '/webhooks/mailgun',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_MAILGUN_SIGNATURE' => hash_hmac('sha256', $body, self::MAILGUN_SECRET),
            ],
            content: $body,
        );

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(1, $this->countProcessedEvents());
    }

    private function postStripeEvent(string $body, string $signature): \Symfony\Component\HttpFoundation\Response
    {
        $this->client->request(
            'POST',
            '/webhooks/stripe',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => $signature,
            ],
            content: $body,
        );

        return $this->client->getResponse();
    }

    private function stripeSignature(string $body): string
    {
        $timestamp = time();

        return sprintf(
            't=%d,v1=%s',
            $timestamp,
            hash_hmac('sha256', $timestamp.'.'.$body, self::STRIPE_SECRET),
        );
    }

    private function countProcessedEvents(): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->from(ProcessedEvent::class, 'e')
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function countAuditRows(?bool $signatureValid = null): int
    {
        $qb = $this->em->createQueryBuilder()
            ->select('COUNT(a.id)')
            ->from(ReceivedWebhook::class, 'a');
        if (null !== $signatureValid) {
            $qb->andWhere('a.signatureValid = :v')->setParameter('v', $signatureValid);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * @param array<string, mixed> $expected
     */
    private static function assertJsonResponse(array $expected, string|false $actual): void
    {
        self::assertNotFalse($actual);
        self::assertSame($expected, json_decode($actual, associative: true));
    }
}
