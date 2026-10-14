<?php

namespace Workbench\Tests\Worker;

use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\Ulid;

/**
 * Writes event_store rows at chosen positions, so a test can leave holes the way an uncommitted
 * transaction does.
 */
trait WritesEvents
{
    private int $base;

    private function startAboveTheHead(): void
    {
        $this->base = (int) (DB::table('event_store')->max('global_position') ?? 0);
    }

    private function event(int $offset, string $type, ?string $streamName = null, int $streamPosition = 1): int
    {
        $position = $this->base + $offset;
        DB::table('event_store')->insert([
            'global_position' => $position,
            'message_id' => (string) new Ulid(),
            'message_type' => $type,
            'stream_name_type' => 'aggregate_stream_name',
            'stream_type' => 'worker_test',
            'stream_name' => $streamName ?? "worker_test###{$position}",
            'stream_position' => $streamPosition,
            'payload' => '{}',
            'metadata' => '{}',
            'created_at' => now(),
        ]);

        return $position;
    }
}
