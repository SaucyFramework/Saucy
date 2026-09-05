<?php

declare(strict_types=1);

namespace Workbench\Tests\Lanes;

use Illuminate\Support\Facades\DB;
use Saucy\Core\Subscriptions\Infra\RunningProcesses;
use Saucy\Core\Subscriptions\Lanes\LaneConfig;
use Saucy\Core\Subscriptions\PoisonMessages\FailureMode;
use Saucy\Core\Subscriptions\PoisonMessages\PoisonMessageStore;
use Workbench\Tests\Lanes\Fixtures\BatchRecordingConsumer;
use Workbench\Tests\Lanes\Fixtures\RecordingConsumer;

final class LaneRunnerTest extends LaneTestCase
{
    /** @test case 1: fan-out once, in order */
    public function it_reads_the_all_stream_once_and_fans_out_to_every_member_in_order(): void
    {
        $this->insertEvent('type.a'); // 1
        $this->insertEvent('type.b'); // 2
        $this->insertEvent('type.a'); // 3
        $this->insertEvent('type.c'); // 4
        $this->insertEvent('type.b'); // 5

        $a = new RecordingConsumer();
        $b = new RecordingConsumer();
        $c = new RecordingConsumer();

        $runner = $this->runner([
            'member_a' => $this->member('member_a', $a, ['type.a', 'type.b']),
            'member_b' => $this->member('member_b', $b, ['type.b']),
            'member_c' => $this->member('member_c', $c, ['type.a', 'type.c']),
        ]);

        $this->assertSame(5, $runner->poll(30)->eventsRead);

        $this->assertSame([1, 2, 3, 5], $a->handled);
        $this->assertSame([2, 5], $b->handled);
        $this->assertSame([1, 3, 4], $c->handled);

        // Each member's context carries its OWN subscription id.
        $this->assertSame('member_a', $a->subscriptionIds[1]);
        $this->assertSame('member_b', $b->subscriptionIds[2]);

        foreach (['member_a', 'member_b', 'member_c'] as $memberId) {
            $this->assertSame(5, $this->checkpoints->positionOf($memberId), $memberId);
        }

        // One page read for the whole lane, not one per member.
        $this->assertSame(1, $this->reader->paginateCalls);
    }

    /** @test case 2: a member whose checkpoint is already past the first events skips them */
    public function it_skips_events_a_member_is_already_past(): void
    {
        foreach (range(1, 5) as $ignored) {
            $this->insertEvent('type.a');
        }

        $a = new RecordingConsumer();
        $ahead = new RecordingConsumer();

        $this->setCheckpoint('member_ahead', 3);

        $runner = $this->runner([
            'member_a' => $this->member('member_a', $a, ['type.a']),
            'member_ahead' => $this->member('member_ahead', $ahead, ['type.a']),
        ]);

        $runner->poll(30);

        $this->assertSame([1, 2, 3, 4, 5], $a->handled);
        $this->assertSame([4, 5], $ahead->handled);
    }

    /** @test case 3: a non-subscribing member still advances */
    public function it_advances_members_that_do_not_subscribe_to_the_events_on_the_page(): void
    {
        $this->insertEvent('type.a'); // 1
        $this->insertEvent('type.a'); // 2

        $a = new RecordingConsumer();
        $b = new RecordingConsumer();

        $runner = $this->runner([
            'member_a' => $this->member('member_a', $a, ['type.a']),
            'member_b' => $this->member('member_b', $b, ['type.b']),
        ]);

        $runner->poll(30);

        $this->assertSame([], $b->handled);
        $this->assertSame(2, $this->checkpoints->positionOf('member_b'));
    }

    /** @test case 4: idle poll advances to the head and rewrites nothing that did not move */
    public function an_idle_poll_advances_members_to_the_store_head_and_does_not_rewrite_them(): void
    {
        $this->insertEvent('type.a'); // 1
        $this->insertEvent('type.b'); // 2 — a type nobody in the lane subscribes to

        $a = new RecordingConsumer();
        $runner = $this->runner(['member_a' => $this->member('member_a', $a, ['type.a'])]);

        $this->assertSame(1, $runner->poll(30)->eventsRead);
        $this->assertSame(1, $this->checkpoints->positionOf('member_a'));

        // Nothing left of a type this lane wants, but the store head is at 2.
        $this->assertTrue($runner->poll(30)->isIdle(), 'the page is empty: the poll is idle');
        $this->assertSame(2, $this->checkpoints->positionOf('member_a'), 'idle advance goes to maxEventId');

        $this->checkpoints->writes = [];

        $this->assertSame(0, $runner->poll(30)->eventsRead);
        $this->assertSame([], $this->checkpoints->writes, 'checkpoints that did not move are not rewritten');
    }

    /** @test case 5: Halt isolates the member */
    public function a_halting_member_leaves_the_lane_and_the_others_keep_going(): void
    {
        foreach (range(1, 5) as $ignored) {
            $this->insertEvent('type.a');
        }

        $a = new RecordingConsumer();
        $b = new RecordingConsumer();
        $b->throwAt[3] = true;
        $c = new RecordingConsumer();

        $runner = $this->runner([
            'member_a' => $this->member('member_a', $a, ['type.a']),
            'member_b' => $this->member('member_b', $b, ['type.a'], FailureMode::Halt),
            'member_c' => $this->member('member_c', $c, ['type.a']),
        ]);

        $runner->poll(30);

        $this->assertSame([1, 2], $b->handled, 'B leaves the lane at the poison event');
        $this->assertSame(2, $this->checkpoints->positionOf('member_b'), 'B stays at the last success');
        $this->assertSame(5, $this->checkpoints->positionOf('member_a'));
        $this->assertSame(5, $this->checkpoints->positionOf('member_c'));

        $store = $this->app->make(PoisonMessageStore::class);
        $poison = $store->getUnresolved('member_b');
        $this->assertCount(1, $poison);
        $this->assertSame(3, $poison[0]->globalPosition);

        // A fresh evaluation keeps B out while the poison message is unresolved.
        $this->coordinator->bumpMembership('default');
        $b->handled = [];
        $runner->poll(30);
        $this->assertSame([], $b->handled);
        $this->assertSame(2, $this->checkpoints->positionOf('member_b'));

        // Resolve it and bump: B is back and re-handles 3.
        $store->resolve((int) $poison[0]->id);
        unset($b->throwAt[3]);
        $this->coordinator->bumpMembership('default');

        $runner->poll(30);
        $this->assertSame([3, 4, 5], $b->handled);
        $this->assertSame(5, $this->checkpoints->positionOf('member_b'));
    }

    /** @test case 6a: PauseStream skips the rest of the poisoned stream and advances */
    public function pause_stream_members_skip_the_rest_of_the_failing_stream(): void
    {
        $this->insertEvent('type.a', 'test###one');   // 1
        $this->insertEvent('type.a', 'test###two');   // 2
        $this->insertEvent('type.a', 'test###one');   // 3
        $this->insertEvent('type.a', 'test###two');   // 4

        $b = new RecordingConsumer();
        $b->throwAt[1] = true;

        $runner = $this->runner([
            'member_b' => $this->member('member_b', $b, ['type.a'], FailureMode::PauseStream),
        ]);

        $runner->poll(30);

        $this->assertSame([2, 4], $b->handled, 'events from test###one are skipped after the poison');
        $this->assertSame(4, $this->checkpoints->positionOf('member_b'), 'the checkpoint still advances');
        $this->assertCount(1, $this->app->make(PoisonMessageStore::class)->getUnresolved('member_b'));
    }

    /** @test item 1: a resolved PauseStream poison must stop the lane skipping that stream */
    public function a_resolved_pause_stream_poison_lets_the_stream_flow_again_on_the_next_poll(): void
    {
        $this->insertEvent('type.a', 'test###one'); // 1
        $this->insertEvent('type.a', 'test###one'); // 2

        $b = new RecordingConsumer();
        $b->throwAt[2] = true;

        $members = ['member_b' => $this->member('member_b', $b, ['type.a'], FailureMode::PauseStream)];
        $runner = $this->runner($members);

        $runner->poll(30);
        $this->assertSame([1], $b->handled);

        $store = $this->app->make(PoisonMessageStore::class);
        $poison = $store->getUnresolved('member_b');
        $this->assertCount(1, $poison);

        // An operator resolves it. The skip set must NOT survive into the next poll: it is
        // rebuilt from the store, otherwise event 3 is silently skipped while the checkpoint
        // marches past it.
        $store->resolve((int) $poison[0]->id);

        $this->insertEvent('type.a', 'test###one'); // 3
        $runner->poll(30);

        $this->assertSame([1, 3], $b->handled, 'the stream flows again once the poison is resolved');
        $this->assertSame(3, $this->checkpoints->positionOf('member_b'));
    }

    /** @test item 6: an event the TypeMap cannot resolve poisons the members, not the lane */
    public function an_undeserialisable_event_poisons_its_subscribers_and_the_lane_keeps_going(): void
    {
        $this->insertEvent('type.a');                              // 1
        $this->insertEvent('type.a', 'unmapped###two');            // 2 - unknown stream name type
        $this->insertEvent('type.a');                              // 3

        // Give event 2 a stream_name_type the TypeMap has no class for.
        DB::table('event_store')->where('global_position', 2)->update(['stream_name_type' => 'not_mapped']);

        $skipper = new RecordingConsumer();
        $halter = new RecordingConsumer();

        $members = [
            'member_skip' => $this->member('member_skip', $skipper, ['type.a'], FailureMode::SkipMessage),
            'member_halt' => $this->member('member_halt', $halter, ['type.a'], FailureMode::Halt),
        ];

        $runner = $this->runner($members);

        // Must not throw out of poll(): that would crash-loop the whole lane forever.
        $result = $runner->poll(30);

        $this->assertSame(3, $result->eventsRead);
        $this->assertSame([1, 3], $skipper->handled, 'SkipMessage steps over the bad event');
        $this->assertSame([1], $halter->handled, 'Halt leaves the lane at the bad event');
        $this->assertSame(3, $this->checkpoints->positionOf('member_skip'));
        $this->assertSame(1, $this->checkpoints->positionOf('member_halt'));

        $store = $this->app->make(PoisonMessageStore::class);
        $this->assertCount(1, $store->getUnresolved('member_skip'));
        $this->assertCount(1, $store->getUnresolved('member_halt'));
        $this->assertSame(2, $store->getUnresolved('member_halt')[0]->globalPosition);
    }

    /** @test item 10: an idle poll writes no activity rows at all */
    public function an_idle_poll_writes_nothing_to_the_activity_log(): void
    {
        $this->insertEvent('type.a'); // 1

        $logger = new Fixtures\RecordingActivityLogger();
        $members = ['member_a' => $this->member('member_a', new RecordingConsumer(), ['type.a'])];
        $runner = $this->runner($members, activityStreamLogger: $logger);

        $runner->poll(30);
        $this->assertContains('started_poll', $logger->types(), 'a poll that did work logs its trail');
        $this->assertContains('store_checkpoint', $logger->types());

        $logger->activities = [];

        // A type nobody in this lane subscribes to still moves the store head, so the idle poll
        // advances (and writes) member_a's checkpoint - but must still log nothing.
        $this->insertEvent('type.b'); // 2

        $this->assertTrue($runner->poll(30)->isIdle());
        $this->assertSame(2, $this->checkpoints->positionOf('member_a'), 'the idle advance moved it');
        $this->assertSame([], $logger->activities, 'an idle poll logs nothing, even when checkpoints moved');
    }

    /** @test item 11: a poll that timed out before its first event is not idle */
    public function a_poll_that_runs_out_of_budget_is_reported_as_timed_out_not_idle(): void
    {
        $this->insertEvent('type.a'); // 1
        $this->insertEvent('type.a'); // 2

        $members = ['member_a' => $this->member('member_a', new RecordingConsumer(), ['type.a'])];
        $runner = $this->runner($members);

        // A zero-second budget trips the time check before the first event is handled.
        $result = $runner->poll(0);

        $this->assertSame(0, $result->eventsRead);
        $this->assertTrue($result->timedOut);
        $this->assertFalse($result->isIdle(), 'a saturated lane must not start the keep-alive countdown');
        $this->assertNull($this->checkpoints->positionOf('member_a'), 'the idle advance did not run');
    }

    /** @test case 6b: SkipMessage advances past the failing event only */
    public function skip_message_members_advance_past_the_failing_event(): void
    {
        $this->insertEvent('type.a', 'test###one'); // 1
        $this->insertEvent('type.a', 'test###one'); // 2
        $this->insertEvent('type.a', 'test###one'); // 3

        $b = new RecordingConsumer();
        $b->throwAt[2] = true;

        $runner = $this->runner([
            'member_b' => $this->member('member_b', $b, ['type.a'], FailureMode::SkipMessage),
        ]);

        $runner->poll(30);

        $this->assertSame([1, 3], $b->handled);
        $this->assertSame(3, $this->checkpoints->positionOf('member_b'));
    }

    /** @test case 7: a paused member is excluded and a resumed one is taken back */
    public function a_paused_member_is_excluded_and_returns_after_resume(): void
    {
        $this->insertEvent('type.a'); // 1
        $this->insertEvent('type.a'); // 2

        $runningProcesses = $this->app->make(RunningProcesses::class);
        $runningProcesses->pause('member_b', 'paused');

        $a = new RecordingConsumer();
        $b = new RecordingConsumer();

        $runner = $this->runner([
            'member_a' => $this->member('member_a', $a, ['type.a']),
            'member_b' => $this->member('member_b', $b, ['type.a']),
        ]);

        $runner->poll(30);

        $this->assertSame([1, 2], $a->handled);
        $this->assertSame([], $b->handled);
        $this->assertNull($this->checkpoints->positionOf('member_b'));

        $runningProcesses->resume('member_b');
        $this->coordinator->bumpMembership('default');

        $runner->poll(30);

        $this->assertSame([1, 2], $b->handled);
        $this->assertSame(2, $this->checkpoints->positionOf('member_b'));
    }

    /** @test case 13: a member pinned above the store head stays in-lane and ejects nobody */
    public function a_member_pinned_above_the_store_head_does_not_eject_the_others(): void
    {
        $this->insertEvent('type.a'); // 1
        $this->insertEvent('type.a'); // 2

        $a = new RecordingConsumer();
        $pinned = new RecordingConsumer();

        $runner = $this->runner(
            [
                'member_a' => $this->member('member_a', $a, ['type.a']),
                // startFrom far above the store head, as happens on staging/demo.
                'member_pinned' => $this->member('member_pinned', $pinned, ['type.a'], startingFromPosition: 1_000_000),
            ],
            new LaneConfig(name: 'default', pageSize: 100, catchUpThreshold: 10),
        );

        $runner->poll(30);

        $this->assertSame([1, 2], $a->handled, 'the pinned member did not eject A');
        $this->assertSame([], $pinned->handled);
        $this->assertSame([], $this->catchUpCalls, 'a pinned-ahead member is not a catch-up member');
        // fromPosition is the min over the in-lane members, so it is A's 0, not the pin.
        $this->assertSame(0, $this->reader->queries[0]->fromPosition);
        $this->assertSame([], $this->checkpoints->writesFor('member_pinned'), 'a member that did not move is not written');

        // An idle poll must not drag the pinned member back down to the store head either.
        $runner->poll(30);
        $this->assertSame([], $this->checkpoints->writesFor('member_pinned'));
        $this->assertSame(2, $this->checkpoints->positionOf('member_a'));
    }

    /** @test an event committed between the empty page and the idle advance is not skipped */
    public function the_idle_advance_never_jumps_over_an_event_committed_during_the_page_read(): void
    {
        $this->insertEvent('type.a'); // 1

        $consumer = new RecordingConsumer();

        // Reading maxEventId() AFTER the page would let event 2 (inserted while the page was
        // being read) be skipped for good.
        $this->reader = new Fixtures\CountingAllStreamReader(
            new Fixtures\EventInsertingReader(
                $this->app->make(\Saucy\MessageStorage\AllStreamReader::class),
                fn() => $this->insertEvent('type.a'), // 2
            ),
        );

        $runner = $this->runner(['member_a' => $this->member('member_a', $consumer, ['type.a'])]);

        $runner->poll(30); // handles 1
        $this->assertSame([1], $consumer->handled);

        $runner->poll(30); // empty page; event 2 lands during the read
        $this->assertSame(1, $this->checkpoints->positionOf('member_a'), 'the idle advance stops at the head as it was before the page');

        $runner->poll(30);
        $this->assertSame([1, 2], $consumer->handled, 'event 2 is picked up on the next poll');
    }

    /** @test the gap guard: no member's checkpoint may pass a position still in flight */
    public function the_lane_stops_every_member_below_an_in_flight_position(): void
    {
        $old = (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s');
        $young = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');

        $this->insertEvent('type.a', createdAt: $old);  // 1
        $this->insertEvent('type.a', createdAt: $old);  // 2
        $this->insertEvent('type.a', createdAt: $old);  // 3
        // 4 is allocated but uncommitted; 5 committed first and is visible.
        $this->insertEvent('type.b', position: 5, createdAt: $young);

        $a = new RecordingConsumer();
        $b = new RecordingConsumer();

        $members = [
            'member_a' => $this->member('member_a', $a, ['type.a']),
            'member_b' => $this->member('member_b', $b, ['type.b']),
        ];
        $config = new LaneConfig(name: 'default', gapGraceInSeconds: 10);

        $runner = $this->runner($members, $config);
        $runner->poll(30);

        $this->assertSame([1, 2, 3], $a->handled);
        $this->assertSame([], $b->handled, 'the young row above the hole is not consumed yet');
        // Neither the subscriber of the missing row's type nor the member that ignores it may
        // move past the hole.
        $this->assertSame(3, $this->checkpoints->positionOf('member_a'));
        $this->assertSame(3, $this->checkpoints->positionOf('member_b'));

        // The in-flight transaction commits.
        $this->insertEvent('type.a', position: 4, createdAt: $young);

        $runner->poll(30);

        $this->assertSame([1, 2, 3, 4], $a->handled);
        $this->assertSame([5], $b->handled);
        $this->assertSame(5, $this->checkpoints->positionOf('member_a'));
        $this->assertSame(5, $this->checkpoints->positionOf('member_b'));
    }

    /** @test the idle advance must use the ceiling, not the head */
    public function an_idle_poll_advances_members_to_the_ceiling_rather_than_the_head(): void
    {
        $old = (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s');
        $young = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');

        $this->insertEvent('type.a', createdAt: $old);  // 1
        $this->insertEvent('type.a', createdAt: $old);  // 2
        $this->insertEvent('type.a', createdAt: $old);  // 3
        // A type nobody in the lane subscribes to, so the next page comes back empty, above a
        // hole at 4 that may still be in flight.
        $this->insertEvent('type.z', position: 5, createdAt: $young);

        $a = new RecordingConsumer();
        $members = ['member_a' => $this->member('member_a', $a, ['type.a'])];
        $config = new LaneConfig(name: 'default', gapGraceInSeconds: 10);

        $runner = $this->runner($members, $config);
        $runner->poll(30);
        $this->assertSame(3, $this->checkpoints->positionOf('member_a'));

        $this->assertTrue($runner->poll(30)->isIdle());
        $this->assertSame(
            3,
            $this->checkpoints->positionOf('member_a'),
            'the idle advance stops at the ceiling, not at maxEventId()',
        );
        $this->assertSame(5, $this->reader->maxEventId(), 'the head really is above the ceiling');
    }

    /** @test the catch-up window must be measured against the ceiling, not the raw head */
    public function a_hole_that_pins_the_ceiling_does_not_eject_the_lane_to_catch_up_jobs(): void
    {
        $old = (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s');
        $young = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');

        // A settled prefix up to 150, a hole at 151, and a long tail of young rows above it.
        foreach (range(1, 150) as $position) {
            $this->insertEvent('type.a', position: $position, createdAt: $old);
        }
        foreach (range(152, 2000) as $position) {
            $this->insertEvent('type.a', position: $position, createdAt: $young);
        }

        $a = new RecordingConsumer();
        $b = new RecordingConsumer();
        $members = [
            'member_a' => $this->member('member_a', $a, ['type.a']),
            'member_b' => $this->member('member_b', $b, ['type.a']),
        ];

        $this->setCheckpoint('member_a', 100);
        $this->setCheckpoint('member_b', 100);

        // The raw head is 2000, so measured against it both members are 1900 behind and would be
        // ejected. Measured against the ceiling (150) they are only 50 behind and stay in-lane.
        $config = new LaneConfig(name: 'default', pageSize: 500, catchUpThreshold: 1000, gapGraceInSeconds: 10);

        $runner = $this->runner($members, $config);
        $runner->poll(30);

        $this->assertSame([], $this->catchUpCalls, 'nobody is handed to a standalone catch-up job');
        $this->assertSame(2000, $this->reader->maxEventId(), 'the raw head really is far above');
        $this->assertSame(150, $this->checkpoints->positionOf('member_a'), 'consumed up to the ceiling');
        $this->assertSame(150, $this->checkpoints->positionOf('member_b'));
    }

    /** @test the ceiling is computed once per page for the whole lane, not once per member */
    public function the_gap_guard_costs_one_query_per_page_regardless_of_member_count(): void
    {
        $old = (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s');
        foreach (range(1, 3) as $ignored) {
            $this->insertEvent('type.a', createdAt: $old);
        }

        $members = [];
        foreach (range(1, 5) as $n) {
            $members['member_' . $n] = $this->member('member_' . $n, new RecordingConsumer(), ['type.a']);
        }

        $runner = $this->runner($members, new LaneConfig(name: 'default', gapGraceInSeconds: 10));

        $runner->poll(30);
        $this->assertSame(1, $this->reader->safeCeilingCalls, 'five members, one ceiling query');

        $runner->poll(30);
        $this->assertSame(2, $this->reader->safeCeilingCalls, 'one per page');
    }

    /** @test with no grace configured the lane keeps its previous behaviour */
    public function a_zero_gap_grace_leaves_the_lane_reading_up_to_the_head(): void
    {
        $old = (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s');
        $young = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');

        $this->insertEvent('type.a', createdAt: $old); // 1
        $this->insertEvent('type.a', position: 3, createdAt: $young); // 3, with 2 in flight

        $a = new RecordingConsumer();
        $members = ['member_a' => $this->member('member_a', $a, ['type.a'])];

        $runner = $this->runner($members, new LaneConfig(name: 'default'));
        $runner->poll(30);

        $this->assertSame(0, $this->reader->safeCeilingCalls, 'the guard is off');
        $this->assertSame([1, 3], $a->handled);
    }

    /** @test batch consumers are flushed once per page and never committed mid-page */
    public function batch_consumers_are_opened_once_and_flushed_before_their_checkpoint_is_written(): void
    {
        foreach (range(1, 4) as $ignored) {
            $this->insertEvent('type.a');
        }

        $batch = new BatchRecordingConsumer();

        $runner = $this->runner(
            ['member_batch' => $this->member('member_batch', $batch, ['type.a'])],
            new LaneConfig(name: 'default', pageSize: 100, commitBatchSize: 2),
        );

        $runner->poll(30);

        $this->assertSame(['before', 'after'], $batch->batchCalls);
        $this->assertSame([1, 2, 3, 4], $batch->handled);
        // One write at page end, never the mid-page commit at event 2.
        $this->assertSame([4], $this->checkpoints->writesFor('member_batch'));
    }
}
