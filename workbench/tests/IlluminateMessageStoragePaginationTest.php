<?php

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
 * Regression coverage for the deferred-fetch pagination in IlluminateMessageStorage::paginate().
 *
 * paginate() resolves the matching `global_position` values from the covering index first and only
 * then fetches the full rows by primary key. This avoids the wide-row filesort that exhausted the
 * MySQL sort buffer (ER_OUT_OF_SORTMEMORY / errno 1038) when filtering on `message_type IN (...)`
 * and ordering by `global_position`. These tests pin the externally observable behaviour: correct
 * filtering, global ordering across interleaved message types, and `fromPosition`/`limit` handling.
 */
final class IlluminateMessageStoragePaginationTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('event_store', function (Blueprint $table) {
            $table->unsignedBigInteger('global_position', true);
            $table->ulid('message_id');
            $table->string('message_type');
            $table->string('stream_name_type');
            $table->string('stream_type');
            $table->string('stream_name');
            $table->unsignedInteger('stream_position');
            $table->json('payload');
            $table->json('metadata')->nullable();
            $table->dateTime('created_at');

            $table->index(['message_type', 'global_position'], 'all_stream_projection_index');
        });
    }

    private function storage(): IlluminateMessageStorage
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

        return new IlluminateMessageStorage(DB::connection(), $serializer, new TypeMap([]));
    }

    /**
     * @param array{int, string} ...$events tuples of [global_position, message_type]
     */
    private function seedEvents(array ...$events): void
    {
        foreach ($events as [$position, $type]) {
            DB::table('event_store')->insert([
                'global_position' => $position,
                'message_id' => str_pad((string) $position, 26, '0', STR_PAD_LEFT),
                'message_type' => $type,
                'stream_name_type' => 'order',
                'stream_type' => 'order',
                'stream_name' => 'order-' . $position,
                'stream_position' => 1,
                // A deliberately large payload mirrors the production rows whose width blew the
                // sort buffer when they were dragged through a SELECT * filesort.
                'payload' => json_encode(['blob' => str_repeat('x', 2048)]),
                'metadata' => json_encode([]),
                'created_at' => '2026-06-01 00:00:00',
            ]);
        }
    }

    /** @test */
    public function it_filters_by_event_type_and_returns_results_in_global_position_order(): void
    {
        // message types are interleaved across positions; the two we want are not contiguous.
        $this->seedEvents(
            [1, 'order.created'],
            [2, 'order.refunded'],
            [3, 'order.created_from_cart'],
            [4, 'order.refunded'],
            [5, 'order.created'],
            [6, 'order.created_from_cart'],
        );

        $events = iterator_to_array($this->storage()->paginate(new AllStreamQuery(
            fromPosition: 0,
            limit: 500,
            eventTypes: ['order.created_from_cart', 'order.created'],
        )));

        $this->assertSame(
            [1, 3, 5, 6],
            array_map(static fn($e) => $e->globalPosition, $events),
            'Events must come back filtered by type and globally ordered by position.',
        );
        $this->assertSame(
            ['order.created', 'order.created_from_cart', 'order.created', 'order.created_from_cart'],
            array_map(static fn($e) => $e->eventType, $events),
        );
    }

    /** @test */
    public function it_respects_from_position_and_limit(): void
    {
        $this->seedEvents(
            [1, 'order.created'],
            [2, 'order.created'],
            [3, 'order.created'],
            [4, 'order.created'],
        );

        $events = iterator_to_array($this->storage()->paginate(new AllStreamQuery(
            fromPosition: 1,
            limit: 2,
            eventTypes: ['order.created'],
        )));

        $this->assertSame([2, 3], array_map(static fn($e) => $e->globalPosition, $events));
    }

    /** @test */
    public function it_returns_all_events_when_no_event_types_are_given(): void
    {
        $this->seedEvents(
            [1, 'order.created'],
            [2, 'order.refunded'],
            [3, 'order.created_from_cart'],
        );

        $events = iterator_to_array($this->storage()->paginate(new AllStreamQuery(
            fromPosition: 0,
            limit: 500,
            eventTypes: null,
        )));

        $this->assertSame([1, 2, 3], array_map(static fn($e) => $e->globalPosition, $events));
    }

    /** @test */
    public function it_yields_nothing_when_no_events_match(): void
    {
        $this->seedEvents([1, 'order.created']);

        $events = iterator_to_array($this->storage()->paginate(new AllStreamQuery(
            fromPosition: 10,
            limit: 500,
            eventTypes: ['order.created'],
        )));

        $this->assertSame([], $events);
    }
}
