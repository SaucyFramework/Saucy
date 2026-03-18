<?php

declare(strict_types=1);

namespace Saucy\Core\Laravel\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Saucy\Core\Subscriptions\StreamSubscription\AggregateInstanceRepository;

final class BackfillAggregateInstancesCommand extends Command
{
    protected $signature = 'saucy:backfill-aggregate-instances';

    protected $description = 'Backfill the aggregate_instances table from the event_store';

    public function handle(
        DatabaseManager $databaseManager,
        AggregateInstanceRepository $repository,
    ): int {
        $this->info('Backfilling aggregate instances from event_store...');

        $connection = $databaseManager->connection();
        $cursor = $connection->table('event_store')
            ->selectRaw('stream_type, stream_name, MAX(stream_position) as max_position')
            ->groupBy('stream_type', 'stream_name')
            ->cursor();

        $count = 0;
        $skipped = 0;
        foreach ($cursor as $row) {
            // Stream names use '###' delimiter (see AggregateStreamName::DELIMITER)
            $parts = explode('###', $row->stream_name);
            if (count($parts) !== 2) {
                $skipped++;
                $this->warn("Skipped stream with unexpected name format: {$row->stream_name}");
                continue;
            }

            $repository->record($row->stream_type, $parts[1], (int) $row->max_position);
            $count++;

            if ($count % 1000 === 0) {
                $this->info("  Processed {$count} aggregate instances...");
            }
        }

        if ($skipped > 0) {
            $this->warn("Skipped {$skipped} streams with unexpected name format.");
        }
        $this->info("Done. Backfilled {$count} aggregate instances.");
        return Command::SUCCESS;
    }
}
