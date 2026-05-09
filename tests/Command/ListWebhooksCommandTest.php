<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\ProcessedEvent;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ListWebhooksCommandTest extends KernelTestCase
{
    private CommandTester $tester;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->tester = new CommandTester((new Application($kernel))->find('app:webhooks:list'));
    }

    #[Test]
    public function reports_no_results_on_an_empty_database(): void
    {
        self::assertSame(Command::SUCCESS, $this->tester->execute([]));
        self::assertStringContainsString('No webhooks match', $this->tester->getDisplay());
    }

    #[Test]
    public function shows_a_table_of_recent_events(): void
    {
        $this->seedThree();

        $this->tester->execute([]);

        $output = $this->tester->getDisplay();
        self::assertStringContainsString('payment_intent.succeeded', $output);
        self::assertStringContainsString('charge.refunded', $output);
        self::assertStringContainsString('failed', $output);
    }

    #[Test]
    public function filters_by_status(): void
    {
        $this->seedThree();

        $this->tester->execute(['--status' => 'failed']);

        $output = $this->tester->getDisplay();
        self::assertStringContainsString('charge.refunded', $output, 'the FAILED row must appear');
        self::assertStringNotContainsString('payment_intent.succeeded', $output, 'COMPLETED rows must be filtered out');
    }

    #[Test]
    public function filters_by_provider(): void
    {
        $this->seedThree();

        $this->tester->execute(['--provider' => 'mailgun']);

        $output = $this->tester->getDisplay();
        self::assertStringContainsString('mailgun', $output);
        self::assertStringNotContainsString('stripe', $output);
    }

    #[Test]
    public function rejects_an_invalid_status_value(): void
    {
        // Wrong --status is operator typo, not a system fault — fail with non-zero exit
        // and a clear message rather than silently returning everything.
        self::assertSame(Command::INVALID, $this->tester->execute(['--status' => 'nonsense']));
        self::assertStringContainsString('Invalid --status', $this->tester->getDisplay());
    }

    #[Test]
    public function emits_one_json_object_per_line_with_the_json_flag(): void
    {
        $this->seedThree();

        $this->tester->execute(['--json' => true]);

        $lines = array_filter(explode("\n", trim($this->tester->getDisplay())));
        self::assertCount(3, $lines);
        foreach ($lines as $line) {
            $decoded = json_decode($line, associative: true);
            self::assertIsArray($decoded);
            self::assertArrayHasKey('id', $decoded);
            self::assertArrayHasKey('status', $decoded);
        }
    }

    private function seedThree(): void
    {
        $completed = new ProcessedEvent('stripe', 'evt_a', 'payment_intent.succeeded');
        $completed->markProcessing();
        $completed->markCompleted();

        $failed = new ProcessedEvent('stripe', 'evt_b', 'charge.refunded');
        $failed->markProcessing();
        $failed->markFailed(new \RuntimeException('boom'));

        $queued = new ProcessedEvent('mailgun', 'evt_c', 'failed');

        $this->em->persist($completed);
        $this->em->persist($failed);
        $this->em->persist($queued);
        $this->em->flush();
    }
}
