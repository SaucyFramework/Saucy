<?php

declare(strict_types=1);

namespace Saucy\Core\Subscriptions\Metrics;

use Illuminate\Support\Facades\DB;

final readonly class IlluminateProjectionSnapshotStore implements ProjectionSnapshotStore
{
    public function record(ProjectionSnapshot ...$snapshots): void
    {
        DB::table('projection_snapshots')->insert(
            array_map(
                fn(ProjectionSnapshot $snapshot) => [
                    'stream_id' => $snapshot->streamId,
                    'position' => $snapshot->position,
                    'max_position' => $snapshot->maxPosition,
                    'recorded_at' => $snapshot->recordedAt->format('Y-m-d H:i:s'),
                ],
                $snapshots,
            ),
        );
    }

    /**
     * @return array<ProjectionSnapshot>
     */
    public function getForStream(string $streamId, int $limit = 100): array
    {
        /** @var array<ProjectionSnapshot> */
        return DB::table('projection_snapshots')
            ->where('stream_id', $streamId)
            ->orderBy('recorded_at', 'desc')
            ->limit($limit)
            ->get()
            ->map(fn(object $row) => new ProjectionSnapshot(
                streamId: $row->stream_id,
                position: (int) $row->position,
                maxPosition: (int) $row->max_position,
                recordedAt: new \DateTime($row->recorded_at),
            ))
            ->toArray();
    }

    /**
     * @return array<ProjectionSnapshot>
     */
    public function getForStreamBetween(string $streamId, \DateTime $from, \DateTime $to): array
    {
        /** @var array<ProjectionSnapshot> */
        return DB::table('projection_snapshots')
            ->where('stream_id', $streamId)
            ->where('recorded_at', '>=', $from->format('Y-m-d H:i:s'))
            ->where('recorded_at', '<=', $to->format('Y-m-d H:i:s'))
            ->orderBy('recorded_at', 'asc')
            ->get()
            ->map(fn(object $row) => new ProjectionSnapshot(
                streamId: $row->stream_id,
                position: (int) $row->position,
                maxPosition: (int) $row->max_position,
                recordedAt: new \DateTime($row->recorded_at),
            ))
            ->toArray();
    }
}
