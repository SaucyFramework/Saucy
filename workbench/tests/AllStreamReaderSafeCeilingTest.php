<?php

declare(strict_types=1);

namespace Workbench\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use Saucy\Core\Serialisation\TypeMap;
use Saucy\MessageStorage\AllStreamQuery;
use Saucy\MessageStorage\IlluminateMessageStorage;
use Saucy\MessageStorage\Serialization\EventSerializer;
use Saucy\MessageStorage\Serialization\SerializationResult;

/**
 * `global_position` is reserved at INSERT and only visible at COMMIT, so position 3 can be
 * readable while position 2 is still in flight. `safeCeiling()` is what keeps a reader from
 * checkpointing past such a row - while still not stalling on the permanent holes that every
 * optimistic-concurrency conflict leaves behind.
 */
final class AllStreamReaderSafeCeilingTest extends TestCase
{
    private const string OLD = '2026-01-01 00:00:00';
    private const string YOUNG = '2026-01-01 00:05:00';
    private const string CUTOFF = '2026-01-01 00:01:00';

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('database.connections.prefixed', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => 'sc_',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('event_store', function (Blueprint $table) {
            $table->unsignedBigInteger('global_position')->primary();
            $table->ulid('message_id');
            $table->string('message_type');
            $table->string('stream_name_type');
            $table->string('stream_type');
            $table->string('stream_name');
            $table->unsignedInteger('stream_position');
            $table->json('payload');
            $table->json('metadata')->nullable();
            $table->dateTime('created_at');

            $table->index(['created_at', 'message_type'], 'event_analytics_index');
        });
    }

    private function storage(?int $scanCap = null, ?string $connection = null): IlluminateMessageStorage
    {
        $serializer = new class implements EventSerializer {
            public function serialize(object $event): SerializationResult
            {
                return new SerializationResult($event::class, '{}');
            }

            public function deserialize(SerializationResult $serializationResult): object
            {
                return (object) [];
            }
        };

        return new IlluminateMessageStorage(
            DB::connection($connection),
            $serializer,
            new TypeMap([]),
            'event_store',
            $scanCap ?? IlluminateMessageStorage::SAFE_CEILING_SCAN_CAP,
        );
    }

    /**
     * @param array<int> $positions
     */
    private function seedEvents(array $positions, string $createdAt, string $type = 'order.created'): void
    {
        foreach ($positions as $position) {
            DB::table('event_store')->insert([
                'global_position' => $position,
                'message_id' => str_pad((string) $position, 26, '0', STR_PAD_LEFT),
                'message_type' => $type,
                'stream_name_type' => 'order',
                'stream_type' => 'order',
                'stream_name' => 'order-' . $position,
                'stream_position' => 1,
                'payload' => json_encode([]),
                'metadata' => json_encode([]),
                'created_at' => $createdAt,
            ]);
        }
    }

    private function ceiling(): int
    {
        return $this->storage()->safeCeiling(new \DateTimeImmutable(self::CUTOFF));
    }

    /** @test (h) */
    public function an_empty_store_has_a_ceiling_of_zero(): void
    {
        $this->assertSame(0, $this->ceiling());
    }

    /** @test (a) nothing young means nothing can still be in flight */
    public function with_no_young_rows_the_ceiling_is_the_head(): void
    {
        $this->seedEvents([1, 2, 3], self::OLD);

        $this->assertSame(3, $this->ceiling());
    }

    /** @test (a) even a permanent hole is ignored once every row around it is settled */
    public function an_old_hole_between_old_rows_does_not_hold_the_ceiling_back(): void
    {
        // 3 was burned by an optimistic-concurrency conflict and will never arrive.
        $this->seedEvents([1, 2, 4, 5], self::OLD);

        $this->assertSame(5, $this->ceiling());
    }

    /** @test (b) */
    public function a_contiguous_young_region_lifts_the_ceiling_to_the_head(): void
    {
        $this->seedEvents([1, 2, 3], self::OLD);
        $this->seedEvents([4, 5], self::YOUNG);

        $this->assertSame(5, $this->ceiling());
    }

    /** @test (c) the case a naive "stop below the first young row" rule gets wrong */
    public function a_young_hole_directly_below_the_first_young_row_stops_the_ceiling_beneath_it(): void
    {
        // T1 allocated 5 and is still in flight; T2 allocated 6 and committed first.
        $this->seedEvents([1, 2, 3, 4], self::OLD);
        $this->seedEvents([6], self::YOUNG);

        $this->assertSame(4, $this->ceiling(), 'must not consume past the in-flight 5');
        $this->assertNotSame(5, $this->ceiling());
    }

    /** @test (d) */
    public function a_hole_in_the_middle_of_the_young_region_stops_the_ceiling_before_it(): void
    {
        $this->seedEvents([1, 2, 3, 4, 5], self::OLD);
        $this->seedEvents([6, 7, 9], self::YOUNG);

        $this->assertSame(7, $this->ceiling());
    }

    /** @test (e) */
    public function an_old_hole_below_the_settled_boundary_is_ignored(): void
    {
        // 3 is a permanent hole, but 5 is settled above it, so 3 is known abandoned.
        $this->seedEvents([1, 2, 4, 5], self::OLD);
        $this->seedEvents([6], self::YOUNG);

        $this->assertSame(6, $this->ceiling());
    }

    /** @test (f) a hole that fills releases the ceiling */
    public function once_the_in_flight_row_commits_the_ceiling_moves_to_the_head(): void
    {
        $this->seedEvents([1, 2, 3, 4], self::OLD);
        $this->seedEvents([6], self::YOUNG);
        $this->assertSame(4, $this->ceiling());

        $this->seedEvents([5], self::YOUNG);

        $this->assertSame(6, $this->ceiling());
    }

    /** @test (g) a hole that never fills ages out of the window */
    public function once_the_row_above_a_hole_becomes_old_the_hole_is_abandoned(): void
    {
        $this->seedEvents([1, 2, 3, 4], self::OLD);
        $this->seedEvents([6], self::YOUNG);
        $this->assertSame(4, $this->ceiling());

        // The same store, read later: 6 is now older than the grace window.
        $ceiling = $this->storage()->safeCeiling(new \DateTimeImmutable('2026-01-01 00:10:00'));

        $this->assertSame(6, $ceiling, 'the hole at 5 is abandoned and no longer blocks');
    }

    /** @test (i) */
    public function a_missing_first_position_with_no_settled_rows_yields_zero(): void
    {
        // Everything is young and position 1 has not committed yet.
        $this->seedEvents([2, 3], self::YOUNG);

        $this->assertSame(0, $this->ceiling());
    }

    /** @test the head is returned when the only young rows sit contiguously on top of nothing */
    public function an_entirely_young_contiguous_store_lifts_the_ceiling_to_the_head(): void
    {
        $this->seedEvents([1, 2, 3], self::YOUNG);

        $this->assertSame(3, $this->ceiling());
    }

    /**
     * @test a DOCUMENTED LIMIT, not a bug: the guard classifies rows by `created_at`, which is
     * stamped from the app clock, so a backdated write defeats it.
     */
    public function a_backdated_row_above_an_in_flight_hole_defeats_the_guard_by_design(): void
    {
        $this->seedEvents([1, 2, 3], self::OLD);
        // 4 is allocated by a live transaction that has not committed yet.
        // 5 is written by an importer that stamps a historical created_at.
        $this->seedEvents([5], '2020-06-01 00:00:00');

        // Nothing looks young, so the whole store looks settled and the ceiling clears the hole.
        // When 4 finally commits it will already have been passed.
        $this->assertSame(5, $this->ceiling());

        // The mitigation is operational: never backdate created_at on a store taking live
        // writes, and run importers/seeders with the guard disabled on a quiet store.
    }

    /** @test a bulk import inside the grace window must not make every poll scan it */
    public function the_ceiling_advances_in_capped_steps_rather_than_scanning_a_bulk_import(): void
    {
        $this->seedEvents([1], self::OLD);
        $this->seedEvents(range(2, 26), self::YOUNG);

        // lastOld is 1, so a cap of 10 bounds this call to positions 2..11.
        $this->assertSame(11, $this->storage(scanCap: 10)->safeCeiling(new \DateTimeImmutable(self::CUTOFF)));

        // Uncapped, the same store resolves straight to the head.
        $this->assertSame(26, $this->ceiling());
    }

    /** @test the hole probe must survive a connection table prefix */
    public function the_ceiling_is_correct_on_a_prefixed_connection(): void
    {
        Schema::connection('prefixed')->create('event_store', function (Blueprint $table) {
            $table->unsignedBigInteger('global_position')->primary();
            $table->ulid('message_id');
            $table->string('message_type');
            $table->string('stream_name_type');
            $table->string('stream_type');
            $table->string('stream_name');
            $table->unsignedInteger('stream_position');
            $table->json('payload');
            $table->json('metadata')->nullable();
            $table->dateTime('created_at');
        });

        foreach ([[1, self::OLD], [2, self::OLD], [3, self::OLD], [4, self::OLD], [6, self::YOUNG]] as [$position, $createdAt]) {
            DB::connection('prefixed')->table('event_store')->insert([
                'global_position' => $position,
                'message_id' => str_pad((string) $position, 26, '0', STR_PAD_LEFT),
                'message_type' => 'order.created',
                'stream_name_type' => 'order',
                'stream_type' => 'order',
                'stream_name' => 'order-' . $position,
                'stream_position' => 1,
                'payload' => json_encode([]),
                'metadata' => json_encode([]),
                'created_at' => $createdAt,
            ]);
        }

        // Exercises the NOT EXISTS probe, whose aliases must not collide with the prefix.
        $ceiling = $this->storage(connection: 'prefixed')->safeCeiling(new \DateTimeImmutable(self::CUTOFF));

        $this->assertSame(4, $ceiling);
    }

    /** @test (j) */
    public function paginate_honours_the_inclusive_up_to_position_bound(): void
    {
        $this->seedEvents([1, 2, 3, 4, 5], self::OLD);

        $events = iterator_to_array($this->storage()->paginate(new AllStreamQuery(
            fromPosition: 0,
            limit: 500,
            eventTypes: null,
            upToPosition: 3,
        )));

        $this->assertSame([1, 2, 3], array_map(static fn($e) => $e->globalPosition, $events));

        $unbounded = iterator_to_array($this->storage()->paginate(new AllStreamQuery(
            fromPosition: 0,
            limit: 500,
            eventTypes: null,
        )));

        $this->assertSame([1, 2, 3, 4, 5], array_map(static fn($e) => $e->globalPosition, $unbounded));
    }
}
