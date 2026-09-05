<?php

declare(strict_types=1);

namespace Workbench\Tests;

use Illuminate\Support\Facades\DB;
use Saucy\Core\Events\Streams\AggregateStreamName;
use Saucy\Core\Serialisation\TypeMap;
use Saucy\Core\Subscriptions\AllStream\AllStreamSubscription;
use Saucy\Core\Subscriptions\Checkpoints\Checkpoint;
use Saucy\Core\Subscriptions\Checkpoints\CheckpointStore;
use Saucy\Core\Subscriptions\Metrics\NoOpLogger;
use Saucy\Core\Subscriptions\StreamOptions;
use Saucy\MessageStorage\AllStreamReader;
use Workbench\Tests\Lanes\Fixtures\PassThroughSerializer;
use Workbench\Tests\Lanes\Fixtures\RecordingCheckpointStore;
use Workbench\Tests\Lanes\Fixtures\RecordingConsumer;

/**
 * The legacy per-subscription reader must not checkpoint past a position whose transaction is
 * still in flight. Scenario throughout: positions 1-3 are settled, 5 is visible and young, and
 * 4 has been allocated by a transaction that has not committed yet.
 */
final class AllStreamSubscriptionGapGuardTest extends WithDatabaseTestCase
{
    private RecordingCheckpointStore $checkpoints;

    protected function setUp(): void
    {
        parent::setUp();

        $this->checkpoints = new RecordingCheckpointStore();
    }

    private function insertEvent(int $position, string $createdAt): void
    {
        DB::table('event_store')->insert([
            'global_position' => $position,
            'message_id' => str_pad((string) $position, 26, '0', STR_PAD_LEFT),
            'message_type' => 'type.a',
            'stream_name_type' => 'test_stream',
            'stream_type' => 'test',
            'stream_name' => 'test###one',
            'stream_position' => $position,
            'payload' => json_encode(['n' => $position]),
            'metadata' => json_encode([]),
            'created_at' => $createdAt,
        ]);
    }

    private function old(): string
    {
        return (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s');
    }

    private function young(): string
    {
        return (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
    }

    private function subscription(RecordingConsumer $consumer, int $gapGraceInSeconds): AllStreamSubscription
    {
        return new AllStreamSubscription(
            subscriptionId: 'guarded',
            streamOptions: new StreamOptions(
                eventTypes: ['type.a'],
                gapGraceInSeconds: $gapGraceInSeconds,
            ),
            messageConsumer: $consumer,
            eventReader: $this->app->make(AllStreamReader::class),
            eventSerializer: new PassThroughSerializer(),
            checkpointStore: $this->checkpoints,
            streamNameTypeMap: new TypeMap([AggregateStreamName::class => 'test_stream']),
            activityStreamLogger: new NoOpLogger(),
        );
    }

    /** @test */
    public function it_stops_below_an_in_flight_position_and_resumes_in_order_once_it_commits(): void
    {
        $this->insertEvent(1, $this->old());
        $this->insertEvent(2, $this->old());
        $this->insertEvent(3, $this->old());
        // 4 is allocated but uncommitted; 5 committed first and is visible.
        $this->insertEvent(5, $this->young());

        $consumer = new RecordingConsumer();
        $subscription = $this->subscription($consumer, gapGraceInSeconds: 10);

        $this->assertSame(3, $subscription->poll(30));
        $this->assertSame([1, 2, 3], $consumer->handled, '5 must not be handled before 4');
        $this->assertSame(3, $this->checkpoints->positionOf('guarded'), 'the checkpoint stops below the hole');

        // The in-flight transaction commits.
        $this->insertEvent(4, $this->young());

        $this->assertSame(2, $subscription->poll(30));
        $this->assertSame([1, 2, 3, 4, 5], $consumer->handled, 'the store is consumed in global order');
        $this->assertSame(5, $this->checkpoints->positionOf('guarded'));
    }

    /** @test the idle advance is the dangerous path: it used to jump straight to the head */
    public function an_idle_poll_does_not_advance_the_checkpoint_over_the_hole(): void
    {
        $this->insertEvent(1, $this->old());
        $this->insertEvent(2, $this->old());
        $this->insertEvent(3, $this->old());
        $this->insertEvent(5, $this->young());

        $this->checkpoints->store(new Checkpoint('guarded', 3));

        $consumer = new RecordingConsumer();

        $this->assertSame(0, $this->subscription($consumer, gapGraceInSeconds: 10)->poll(30));
        $this->assertSame(3, $this->checkpoints->positionOf('guarded'), 'the idle advance stops at the ceiling');

        $this->insertEvent(4, $this->young());

        $this->assertSame(2, $this->subscription($consumer, gapGraceInSeconds: 10)->poll(30));
        $this->assertSame([4, 5], $consumer->handled);
    }

    /** @test with the guard disabled the legacy behaviour is preserved byte for byte */
    public function with_a_zero_grace_the_checkpoint_jumps_to_the_head_as_before(): void
    {
        $this->insertEvent(1, $this->old());
        $this->insertEvent(2, $this->old());
        $this->insertEvent(3, $this->old());
        $this->insertEvent(5, $this->young());

        $consumer = new RecordingConsumer();
        $subscription = $this->subscription($consumer, gapGraceInSeconds: 0);

        $this->assertSame(4, $subscription->poll(30));
        $this->assertSame([1, 2, 3, 5], $consumer->handled);
        $this->assertSame(5, $this->checkpoints->positionOf('guarded'));

        // ... and event 4 is now skipped forever, which is exactly what the guard prevents.
        $this->insertEvent(4, $this->young());
        $this->assertSame(0, $subscription->poll(30));
        $this->assertSame([1, 2, 3, 5], $consumer->handled);
    }

    /** @test a permanent hole (a burned auto-increment value) must not stall the reader */
    public function an_old_hole_does_not_hold_the_reader_back(): void
    {
        $this->insertEvent(1, $this->old());
        $this->insertEvent(2, $this->old());
        // 3 was burned by an optimistic-concurrency conflict and will never arrive.
        $this->insertEvent(4, $this->old());
        $this->insertEvent(5, $this->old());

        $consumer = new RecordingConsumer();

        $this->assertSame(4, $this->subscription($consumer, gapGraceInSeconds: 10)->poll(30));
        $this->assertSame([1, 2, 4, 5], $consumer->handled);
        $this->assertSame(5, $this->checkpoints->positionOf('guarded'));
    }
}
