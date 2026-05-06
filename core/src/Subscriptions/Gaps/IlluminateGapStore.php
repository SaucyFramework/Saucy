<?php

namespace Saucy\Core\Subscriptions\Gaps;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;

final readonly class IlluminateGapStore implements GapStore
{
    public function __construct(
        private ConnectionInterface $connection,
        private string $checkpointTable = 'checkpoint_store',
        private string $gapsTable = 'subscription_gaps',
    ) {}

    public function getOpen(string $subscriptionId): array
    {
        $rows = $this->connection->table($this->gapsTable)
            ->where('subscription_id', $subscriptionId)
            ->orderBy('position')
            ->get();

        $gaps = [];
        foreach ($rows as $row) {
            $gaps[] = new Gap(
                position: (int) $row->position, // @phpstan-ignore-line
                firstSeenAt: new DateTimeImmutable($row->first_seen_at), // @phpstan-ignore-line
            );
        }

        return $gaps;
    }

    public function commit(
        string $subscriptionId,
        int $newCheckpointPosition,
        array $newGapPositions,
        array $closedGapPositions,
        DateTimeImmutable $now,
    ): void {
        $this->connection->transaction(function () use ($subscriptionId, $newCheckpointPosition, $newGapPositions, $closedGapPositions, $now) {
            $this->connection->table($this->checkpointTable)
                ->updateOrInsert(
                    ['stream_identifier' => $subscriptionId],
                    ['position' => $newCheckpointPosition],
                );

            if ($closedGapPositions !== []) {
                $this->connection->table($this->gapsTable)
                    ->where('subscription_id', $subscriptionId)
                    ->whereIn('position', $closedGapPositions)
                    ->delete();
            }

            if ($newGapPositions !== []) {
                $firstSeen = $now->format('Y-m-d H:i:s');
                $this->connection->table($this->gapsTable)->insert(
                    array_map(
                        fn(int $position) => [
                            'subscription_id' => $subscriptionId,
                            'position' => $position,
                            'first_seen_at' => $firstSeen,
                        ],
                        $newGapPositions,
                    ),
                );
            }
        });
    }

    public function deleteAll(string $subscriptionId): void
    {
        $this->connection->table($this->gapsTable)
            ->where('subscription_id', $subscriptionId)
            ->delete();
    }
}
