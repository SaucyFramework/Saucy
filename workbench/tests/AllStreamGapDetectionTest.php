<?php

namespace Workbench\Tests;

use Illuminate\Support\Facades\DB;
use Saucy\Core\Serialisation\TypeMap;
use Saucy\Core\Subscriptions\AllStream\AllStreamSubscription;
use Saucy\Core\Subscriptions\Checkpoints\Checkpoint;
use Saucy\Core\Subscriptions\Checkpoints\CheckpointStore;
use Saucy\Core\Subscriptions\Checkpoints\IlluminateCheckpointStore;
use Saucy\Core\Subscriptions\Gaps\GapStore;
use Saucy\Core\Subscriptions\Gaps\IlluminateGapStore;
use Saucy\Core\Subscriptions\MessageConsumption\MessageConsumeContext;
use Saucy\Core\Subscriptions\MessageConsumption\MessageConsumer;
use Saucy\Core\Subscriptions\Metrics\NoOpLogger;
use Saucy\Core\Subscriptions\StreamOptions;
use Saucy\MessageStorage\Serialization\ConstructingPayloadSerializer;
use Workbench\App\BankAccount\Events\AccountCredited;
use Workbench\App\BankAccount\Events\AccountDebited;

/**
 * Validates the gap-detection algorithm in AllStreamSubscription::poll().
 *
 * Setup uses raw event_store inserts so we can simulate the auto-increment
 * commit-order race: rows can appear with non-contiguous global_position
 * values, exactly as they would when one writer's transaction commits while
 * another's is still in flight.
 */
final class AllStreamGapDetectionTest extends WithDatabaseTestCase
{
    private RecordingConsumer $consumer;
    private CheckpointStore $checkpointStore;
    private GapStore $gapStore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->consumer = new RecordingConsumer();
        $this->checkpointStore = new IlluminateCheckpointStore(DB::connection());
        $this->gapStore = new IlluminateGapStore(DB::connection());
    }

    /** @test */
    public function detects_gap_in_observed_page_and_recovers_when_row_arrives(): void
    {
        // checkpoint at 0; rows visible at 1, 2, _, 4 (3 in-flight, invisible)
        $this->insertEventAt(1, AccountCredited::class);
        $this->insertEventAt(2, AccountCredited::class);
        $this->insertEventAt(4, AccountCredited::class); // skips position 3

        $sub = $this->buildSubscription();

        $sub->poll();

        // Three events visible, one position tracked as a gap
        $this->assertSame(3, count($this->consumer->seen));
        $this->assertSame([1, 2, 4], array_column($this->consumer->seen, 'globalPosition'));
        $this->assertSame(4, $this->checkpointStore->get('test_sub')->position);
        $this->assertSame([3], $this->openGapPositions('test_sub'));

        // Now the in-flight transaction commits: row 3 becomes visible.
        $this->insertEventAt(3, AccountCredited::class);

        $sub->poll();

        // Gap resolved: row 3 delivered out of order, gap registry empty.
        $this->assertSame(4, count($this->consumer->seen));
        $this->assertSame(3, $this->consumer->seen[3]['globalPosition']);
        $this->assertSame([], $this->openGapPositions('test_sub'));
    }

    /** @test */
    public function gap_at_page_edge_is_not_detected_until_a_higher_position_appears(): void
    {
        // The gap-detection algorithm only marks positions that lie between
        // the previous checkpoint and the highest position observed in the
        // page. Positions ABOVE the highest observed position are simply
        // "we haven't read that far yet" — when their row eventually
        // becomes visible we deliver it via `> checkpoint`.
        $this->insertEventAt(1, AccountCredited::class);
        $this->insertEventAt(2, AccountCredited::class);
        // position 3 is in-flight; nothing higher is visible

        $sub = $this->buildSubscription();
        $sub->poll();

        $this->assertSame(2, $this->checkpointStore->get('test_sub')->position);
        $this->assertSame([], $this->openGapPositions('test_sub'));

        // Now position 3 commits.
        $this->insertEventAt(3, AccountCredited::class);
        $sub->poll();

        $this->assertSame(3, count($this->consumer->seen));
        $this->assertSame(3, $this->checkpointStore->get('test_sub')->position);
    }

    /** @test */
    public function rolled_back_gap_expires_after_gap_timeout(): void
    {
        $this->insertEventAt(1, AccountCredited::class);
        $this->insertEventAt(3, AccountCredited::class); // 2 was rolled back

        $sub = $this->buildSubscription(gapTimeoutSeconds: 1);
        $sub->poll();

        $this->assertSame([2], $this->openGapPositions('test_sub'));

        // Backdate the gap so it's now older than gapTimeoutSeconds.
        DB::table('subscription_gaps')
            ->where('subscription_id', 'test_sub')
            ->update(['first_seen_at' => '2020-01-01 00:00:00']);

        // Subsequent poll with no new events: expired gap is forgotten
        // and checkpoint stays where it is (no new events to advance to).
        $sub->poll();

        $this->assertSame([], $this->openGapPositions('test_sub'));
        $this->assertSame(3, $this->checkpointStore->get('test_sub')->position);
    }

    /** @test */
    public function event_type_filter_does_not_create_false_gaps(): void
    {
        // Projector cares about AccountCredited only. Other event types in
        // the global stream must NOT be tracked as gaps — they're just
        // events the projector skips.
        $this->insertEventAt(1, AccountCredited::class);
        $this->insertEventAt(2, AccountDebited::class); // skipped by filter
        $this->insertEventAt(3, AccountCredited::class);

        $typeMap = $this->app->make(TypeMap::class);
        $sub = $this->buildSubscription(eventTypes: [
            $typeMap->classNameToType(AccountCredited::class),
        ]);

        $sub->poll();

        $this->assertSame(2, count($this->consumer->seen));
        $this->assertSame([1, 3], array_column($this->consumer->seen, 'globalPosition'));
        $this->assertSame(3, $this->checkpointStore->get('test_sub')->position);
        $this->assertSame([], $this->openGapPositions('test_sub'));
    }

    /** @test */
    public function multiple_gaps_in_one_page_are_all_tracked(): void
    {
        $this->insertEventAt(1, AccountCredited::class);
        $this->insertEventAt(4, AccountCredited::class); // skips 2, 3
        $this->insertEventAt(7, AccountCredited::class); // skips 5, 6

        $sub = $this->buildSubscription();
        $sub->poll();

        $this->assertSame(3, count($this->consumer->seen));
        $this->assertSame(7, $this->checkpointStore->get('test_sub')->position);
        $this->assertSame([2, 3, 5, 6], $this->openGapPositions('test_sub'));
    }

    /** @test */
    public function commit_is_atomic_checkpoint_and_gaps_advance_together(): void
    {
        // After a poll, checkpoint and gap registry must be consistent —
        // checkpoint is past gap positions iff the gaps are recorded.
        $this->insertEventAt(1, AccountCredited::class);
        $this->insertEventAt(5, AccountCredited::class);

        $sub = $this->buildSubscription();
        $sub->poll();

        $checkpoint = $this->checkpointStore->get('test_sub')->position;
        $gaps = $this->openGapPositions('test_sub');

        $this->assertSame(5, $checkpoint);
        $this->assertSame([2, 3, 4], $gaps);

        // Sanity: every gap position is below the checkpoint.
        foreach ($gaps as $pos) {
            $this->assertLessThan($checkpoint, $pos);
        }
    }

    /** @test */
    public function unfiltered_paginate_returns_all_event_types_ordered_by_global_position(): void
    {
        // Sanity for the storage-layer change: paginate() must not filter
        // by event type; gap detection depends on observing the full
        // global_position sequence.
        $this->insertEventAt(1, AccountCredited::class);
        $this->insertEventAt(2, AccountDebited::class);
        $this->insertEventAt(3, AccountCredited::class);

        $reader = $this->app->make(\Saucy\MessageStorage\AllStreamReader::class);
        $rows = iterator_to_array($reader->paginate(
            new \Saucy\MessageStorage\AllStreamQuery(fromPosition: 0, limit: 10),
        ));

        $this->assertSame([1, 2, 3], array_map(fn($r) => $r->globalPosition, $rows));
    }

    /**
     * @param array<string>|null $eventTypes
     */
    private function buildSubscription(
        ?array $eventTypes = null,
        int $gapTimeoutSeconds = 60,
    ): AllStreamSubscription {
        $typeMap = $this->app->make(TypeMap::class);

        return new AllStreamSubscription(
            subscriptionId: 'test_sub',
            streamOptions: new StreamOptions(
                pageSize: 100,
                commitBatchSize: 100,
                eventTypes: $eventTypes,
                gapTimeoutSeconds: $gapTimeoutSeconds,
            ),
            messageConsumer: $this->consumer,
            eventReader: $this->app->make(\Saucy\MessageStorage\AllStreamReader::class),
            eventSerializer: new ConstructingPayloadSerializer($typeMap),
            checkpointStore: $this->checkpointStore,
            gapStore: $this->gapStore,
            streamNameTypeMap: $typeMap,
            activityStreamLogger: new NoOpLogger(),
        );
    }

    private function insertEventAt(int $globalPosition, string $eventClass): void
    {
        $typeMap = $this->app->make(TypeMap::class);
        DB::table('event_store')->insert([
            'global_position' => $globalPosition,
            'message_id' => bin2hex(random_bytes(13)),
            'message_type' => $typeMap->classNameToType($eventClass),
            'stream_name_type' => 'aggregate_stream_name',
            'stream_type' => 'bank_account',
            'stream_name' => 'bank_account###stream-' . $globalPosition,
            'stream_position' => 1,
            'payload' => json_encode(['amount' => 1]),
            'metadata' => '{}',
            'created_at' => now(),
        ]);
    }

    /**
     * @return array<int>
     */
    private function openGapPositions(string $subscriptionId): array
    {
        $gaps = $this->gapStore->getOpen($subscriptionId);
        return array_map(fn($gap) => $gap->position, $gaps);
    }
}

final class RecordingConsumer implements MessageConsumer
{
    /** @var array<int, array<string, mixed>> */
    public array $seen = [];

    public function handle(MessageConsumeContext $context): void
    {
        $this->seen[$context->globalPosition] = [
            'globalPosition' => $context->globalPosition,
            'eventType' => $context->eventType,
        ];
    }

    public static function getMessages(): array
    {
        return [];
    }
}
