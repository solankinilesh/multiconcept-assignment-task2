<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\ProcessedEventStatus;
use App\Repository\ProcessedEventRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Operator-facing inspection tool. Filters mirror the columns operators care about most:
 *   - status: failed events first when triaging
 *   - provider: scoped down when investigating one integration
 *   - since: post-incident windowing
 *
 * `--json` outputs newline-delimited records, designed for `| jq` scripting.
 */
#[AsCommand(
    name: 'app:webhooks:list',
    description: 'List recently received webhooks with optional filters.',
)]
final class ListWebhooksCommand extends Command
{
    public function __construct(private readonly ProcessedEventRepository $events)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Filter by status (queued, processing, completed, skipped, failed)')
            ->addOption('provider', null, InputOption::VALUE_REQUIRED, 'Filter by provider name (e.g. stripe)')
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Show events received at or after this date (any strtotime-compatible string)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum rows to return', '20')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output one JSON object per line instead of a table');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $status = $this->parseStatus($input->getOption('status'));
        if (false === $status) {
            $io->error('Invalid --status. Allowed: queued, processing, completed, skipped, failed.');

            return Command::INVALID;
        }

        $since = $this->parseSince($input->getOption('since'));
        if (false === $since) {
            $io->error('Invalid --since: could not parse as a date.');

            return Command::INVALID;
        }

        $provider = $input->getOption('provider');
        $limit = max(1, (int) $input->getOption('limit'));

        $events = $this->events->findFiltered(
            status: $status,
            provider: is_string($provider) ? $provider : null,
            since: $since,
            limit: $limit,
        );

        if ($input->getOption('json')) {
            foreach ($events as $event) {
                $output->writeln((string) json_encode([
                    'id' => (string) $event->getId(),
                    'provider' => $event->getProvider(),
                    'event_type' => $event->getEventType(),
                    'status' => $event->getStatus()->value,
                    'received_at' => $event->getReceivedAt()->format(DATE_ATOM),
                    'processed_at' => $event->getProcessedAt()?->format(DATE_ATOM),
                    'attempt_count' => $event->getAttemptCount(),
                    'last_error' => $event->getLastError(),
                ], JSON_THROW_ON_ERROR));
            }

            return Command::SUCCESS;
        }

        if ([] === $events) {
            $io->info('No webhooks match the given filters.');

            return Command::SUCCESS;
        }

        $rows = array_map(
            static fn ($e) => [
                substr((string) $e->getId(), 0, 8).'…',
                $e->getProvider(),
                $e->getEventType(),
                $e->getStatus()->value,
                $e->getReceivedAt()->format('Y-m-d H:i:s'),
                $e->getProcessedAt()?->format('Y-m-d H:i:s') ?? '-',
                self::truncate($e->getLastError(), 40),
            ],
            $events,
        );

        $io->table(
            ['id', 'provider', 'event_type', 'status', 'received_at', 'processed_at', 'last_error'],
            $rows,
        );

        return Command::SUCCESS;
    }

    private function parseStatus(mixed $raw): ProcessedEventStatus|false|null
    {
        if (null === $raw || '' === $raw) {
            return null;
        }
        if (!is_string($raw)) {
            return false;
        }

        return ProcessedEventStatus::tryFrom(strtoupper($raw)) ?? false;
    }

    private function parseSince(mixed $raw): \DateTimeImmutable|false|null
    {
        if (null === $raw || '' === $raw) {
            return null;
        }
        if (!is_string($raw)) {
            return false;
        }

        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception) {
            return false;
        }
    }

    private static function truncate(?string $value, int $max): string
    {
        if (null === $value) {
            return '-';
        }

        return strlen($value) > $max ? substr($value, 0, $max - 1).'…' : $value;
    }
}
