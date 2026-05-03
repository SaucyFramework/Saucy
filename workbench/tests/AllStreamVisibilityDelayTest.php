<?php

namespace Workbench\Tests;

use Illuminate\Support\Facades\DB;
use Saucy\Core\Serialisation\TypeMap;
use Saucy\MessageStorage\AllStreamQuery;
use Saucy\MessageStorage\AllStreamReader;
use Saucy\MessageStorage\IlluminateMessageStorage;
use Saucy\MessageStorage\Serialization\ConstructingPayloadSerializer;
use Workbench\App\BankAccount\Events\AccountCredited;

/**
 * Guards the visibility-delay filter that protects against the auto-increment
 * commit-order gap. The bug it prevents: MySQL reserves auto-increment values
 * at INSERT time but rows only become visible at COMMIT — under concurrent
 * writers, an in-flight commit at position N can land *after* a poll has
 * already advanced past N+1, permanently stranding N below the checkpoint.
 *
 * The fix is to only return rows whose `created_at` is older than
 * `visibilityDelaySeconds`, giving any in-flight commit time to settle.
 */
final class AllStreamVisibilityDelayTest extends WithDatabaseTestCase
{
    /** @test */
    public function paginate_filters_out_recently_inserted_rows_when_visibility_delay_is_set(): void
    {
        $reader = $this->buildReader();

        // Two rows: one well in the past, one just now.
        $this->insertEventAt('past', '2020-01-01 00:00:00');
        $this->insertEventAt('recent', now('UTC')->toDateTimeString());

        $withoutDelay = iterator_to_array($reader->paginate(new AllStreamQuery(
            fromPosition: 0,
            limit: 10,
            eventTypes: null,
            visibilityDelaySeconds: 0,
        )));
        $this->assertCount(2, $withoutDelay, 'baseline: no delay returns both rows');

        $withDelay = iterator_to_array($reader->paginate(new AllStreamQuery(
            fromPosition: 0,
            limit: 10,
            eventTypes: null,
            visibilityDelaySeconds: 30,
        )));
        $this->assertCount(1, $withDelay, 'with 30s delay only the past row is returned');
        $this->assertSame('past', $withDelay[0]->streamName);
    }

    /** @test */
    public function max_event_id_with_visibility_delay_excludes_recent_rows(): void
    {
        $reader = $this->buildReader();

        $this->insertEventAt('past', '2020-01-01 00:00:00');
        $pastPosition = (int) DB::table('event_store')->where('stream_name', 'past')->value('global_position');

        $this->insertEventAt('recent', now('UTC')->toDateTimeString());

        $this->assertGreaterThan($pastPosition, $reader->maxEventId());
        $this->assertSame($pastPosition, $reader->maxEventIdWithVisibilityDelay(30));
    }

    /** @test */
    public function max_event_id_with_visibility_delay_returns_zero_when_no_rows_pass_the_filter(): void
    {
        $reader = $this->buildReader();
        $this->insertEventAt('recent', now('UTC')->toDateTimeString());

        $this->assertSame(0, $reader->maxEventIdWithVisibilityDelay(30));
    }

    private function buildReader(): AllStreamReader
    {
        return new IlluminateMessageStorage(
            connection: DB::connection(),
            eventSerializer: new ConstructingPayloadSerializer($this->app->make(TypeMap::class)),
            streamNameTypeMap: $this->app->make(TypeMap::class),
        );
    }

    private function insertEventAt(string $streamName, string $createdAt): void
    {
        DB::table('event_store')->insert([
            'message_id' => bin2hex(random_bytes(13)),
            'message_type' => (new \ReflectionClass(AccountCredited::class))->getShortName(),
            'stream_name_type' => 'aggregate_stream_name',
            'stream_type' => 'bank_account',
            'stream_name' => $streamName,
            'stream_position' => 1,
            'payload' => '{}',
            'metadata' => '{}',
            'created_at' => $createdAt,
        ]);
    }
}
