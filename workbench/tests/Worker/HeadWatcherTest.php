<?php

namespace Workbench\Tests\Worker;

use Saucy\Core\Subscriptions\Worker\HeadWatcher;
use Illuminate\Support\Facades\DB;

/**
 * the head watcher only releases positions up to the first hole an uncommitted transaction
 * may still fill.
 */
final class HeadWatcherTest extends WorkerTestCase
{
    use WritesEvents;

    private float $now = 1_000.0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->startAboveTheHead();
    }

    public function test_it_releases_new_events_with_their_types_and_aggregate_streams(): void
    {
        $watcher = $this->watcher();
        $this->event(1, 'ticket_sold', 'order###1');
        $this->event(2, 'ticket_scanned', 'order###1', 2);
        $this->event(3, 'ticket_sold');

        $window = $watcher->poll();

        $this->assertSame($this->base, $window->from);
        $this->assertSame($this->base + 3, $window->to);
        $this->assertSame(['ticket_sold', 'ticket_scanned'], $window->eventTypes);
        $this->assertSame(['ticket_sold', 'ticket_scanned'], $window->aggregateStreams['order###1']);
        $this->assertNull($watcher->poll());
    }

    public function test_it_holds_the_head_at_a_hole_until_the_transaction_commits(): void
    {
        $watcher = $this->watcher();
        $this->event(1, 'a');
        $this->event(3, 'b');

        $window = $watcher->poll();
        $this->assertSame($this->base + 1, $window->to);
        $this->assertSame(['a'], $window->eventTypes);
        $this->assertSame(1, $watcher->openGapCount());

        $this->now += 1;
        $this->assertNull($watcher->poll());

        $this->event(2, 'c');
        $window = $watcher->poll();
        $this->assertSame($this->base + 1, $window->from);
        $this->assertSame($this->base + 3, $window->to);
        $this->assertSame(['c', 'b'], $window->eventTypes);
        $this->assertSame(0, $watcher->openGapCount());
        $this->assertSame(0, $watcher->skippedGapCount());
    }

    public function test_a_hole_that_stays_open_past_the_timeout_is_skipped(): void
    {
        $watcher = $this->watcher();
        $this->event(1, 'a');
        $this->event(4, 'b');
        $watcher->poll();

        $this->now += 4.9;
        $this->assertNull($watcher->poll());
        $this->assertEqualsWithDelta(4.9, $watcher->oldestOpenGapAge(), 0.001);

        $this->now += 0.2;
        $window = $watcher->poll();
        $this->assertSame($this->base + 4, $window->to);
        $this->assertSame(['b'], $window->eventTypes);
        $this->assertSame(1, $watcher->skippedGapCount());
    }

    public function test_a_hole_that_partly_fills_keeps_its_age(): void
    {
        $watcher = $this->watcher();
        $this->event(1, 'a');
        $this->event(5, 'b');
        $watcher->poll();

        $this->now += 3;
        $this->event(3, 'c');
        $this->assertNull($watcher->poll());

        // Positions 2 and 4 are still open, both first seen 5 s ago.
        $this->now += 2;
        $window = $watcher->poll();
        $this->assertSame($this->base + 5, $window->to);
        $this->assertSame(['c', 'b'], $window->eventTypes);
        $this->assertSame(2, $watcher->skippedGapCount());
    }

    public function test_an_event_that_fills_a_skipped_hole_is_reported_once(): void
    {
        $watcher = $this->watcher();
        $this->event(1, 'a');
        $this->event(3, 'b');
        $watcher->poll();
        $this->now += 6;
        $watcher->poll();
        $this->assertSame([], $watcher->lateFills());

        $position = $this->event(2, 'late', 'order###9');

        $this->assertSame([['position' => $position, 'eventType' => 'late', 'streamName' => 'order###9']], $watcher->lateFills());
        $this->assertSame([], $watcher->lateFills());
        $this->assertNull($watcher->poll());
    }

    public function test_it_starts_below_the_head_so_holes_at_boot_still_wait(): void
    {
        $this->event(1, 'a');
        $this->event(3, 'b');
        $watcher = $this->watcher(bootLookback: 10);

        $this->assertSame($this->base + 3, $watcher->boot());
        $this->assertSame(max(0, $this->base + 3 - 10), $watcher->safeHead());
        $this->assertSame($this->base + 1, $watcher->poll()->to);
    }

    public function test_it_looks_back_by_time_and_to_a_deploy_pause(): void
    {
        $this->event(1, 'old');
        $this->event(2, 'old');
        $this->event(3, 'recent');
        DB::table('event_store')->where('global_position', '<=', $this->base + 2)->update(['created_at' => now()->subMinutes(10)]);
        $watcher = new HeadWatcher(
            connection: DB::connection(),
            bootLookback: 1000,
            bootLookbackSeconds: 120.0,
        );

        $watcher->boot();
        $this->assertSame($this->base + 2, $watcher->safeHead());

        $watcher->boot(rewindTo: $this->base);
        $this->assertSame($this->base, $watcher->safeHead());
    }

    public function test_when_the_database_rejects_the_probe_it_falls_back_to_the_timeout(): void
    {
        // E.g. a proxy (Vitess) that doesn't support FOR SHARE NOWAIT: not the 3572 "lock held" answer.
        $primary = $this->createMock(\Illuminate\Database\ConnectionInterface::class);
        $pdoException = new \PDOException('syntax not supported');
        $pdoException->errorInfo = ['42000', 1235, 'This version does not support FOR SHARE NOWAIT'];
        $primary->expects($this->once())->method('select')
            ->willThrowException(new \Illuminate\Database\QueryException('mysql', 'select …', [], $pdoException));
        $watcher = new HeadWatcher(
            connection: DB::connection(),
            gapTimeoutSeconds: 5.0,
            bootLookback: 0,
            clock: fn() => $this->now,
            primary: $primary,
        );
        $watcher->boot();
        $this->event(1, 'a');
        $this->event(3, 'b');

        $this->assertSame($this->base + 1, $watcher->poll()->to);
        $this->now += 1;
        $this->assertNull($watcher->poll()); // probe fails once, then the probe is off for a minute
        $this->now += 4.1;
        $this->assertSame($this->base + 3, $watcher->poll()->to);
        $this->assertSame(1, $watcher->timedOutGapCount());
    }

    public function test_a_full_window_says_more_is_waiting(): void
    {
        $watcher = $this->watcher(windowSize: 2);
        $this->event(1, 'a');
        $this->event(2, 'a');
        $this->event(3, 'a');

        $first = $watcher->poll();
        $this->assertTrue($first->full);
        $this->assertSame($this->base + 2, $first->to);

        $second = $watcher->poll();
        $this->assertFalse($second->full);
        $this->assertSame($this->base + 3, $second->to);
    }

    private function watcher(int $windowSize = 1000, int $bootLookback = 0): HeadWatcher
    {
        $watcher = new HeadWatcher(
            connection: DB::connection(),
            gapTimeoutSeconds: 5.0,
            windowSize: $windowSize,
            bootLookback: $bootLookback,
            clock: fn() => $this->now,
        );

        if ($bootLookback === 0) {
            $watcher->boot();
        }

        return $watcher;
    }
}
