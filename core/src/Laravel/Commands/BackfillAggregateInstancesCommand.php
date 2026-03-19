<?php

declare(strict_types=1);

namespace Saucy\Core\Laravel\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Saucy\Core\Subscriptions\StreamSubscription\AggregateInstanceRepository;

final class BackfillAggregateInstancesCommand extends Command
{
    protected $signature = 'saucy:backfill-aggregate-instances
        {--stream-type= : Only backfill a specific stream type}
        {--list : List available stream types instead of backfilling}';

    protected $description = 'Backfill the aggregate_instances table from the event_store';

    public function handle(
        DatabaseManager $databaseManager,
        AggregateInstanceRepository $repository,
    ): int {
        $connection = $databaseManager->connection();

        if ($this->option('list')) {
            /** @var list<string> $types */
            $types = $connection->table('event_store')
                ->distinct()
                ->pluck('stream_type')
                ->all();

            $this->info('Available stream types:');
            foreach ($types as $type) {
                $this->line("  - {$type}");
            }
            return Command::SUCCESS;
        }

        /** @var string|null $streamType */
        $streamType = $this->option('stream-type');

        $this->info('Backfilling aggregate instances from event_store...' . ($streamType ? " (stream type: {$streamType})" : ''));

        $count = 0;
        $skipped = 0;
        $chunkSize = 5000;
        $offset = 0;

        do {
            $query = $connection->table('event_store')
                ->selectRaw('stream_type, stream_name, MAX(stream_position) as max_position')
                ->groupBy('stream_type', 'stream_name')
                ->orderBy('stream_type')
                ->orderBy('stream_name')
                ->offset($offset)
                ->limit($chunkSize);

            if ($streamType !== null) {
                $query->where('stream_type', $streamType);
            }

            $rows = $query->get();

            foreach ($rows as $row) {
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

            $offset += $chunkSize;
        } while ($rows->count() === $chunkSize);

        if ($skipped > 0) {
            $this->warn("Skipped {$skipped} streams with unexpected name format.");
        }
        $this->info("Done. Backfilled {$count} aggregate instances.");
        return Command::SUCCESS;
    }
}
