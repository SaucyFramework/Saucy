<?php

namespace Workbench\Tests\Worker;

use Saucy\Core\Subscriptions\Worker\RendezvousAssignment;
use PHPUnit\Framework\TestCase;

/**
 * workers agree on who owns what without talking, and a scale event moves little.
 */
final class RendezvousAssignmentTest extends TestCase
{
    public function test_every_worker_computes_the_same_owner_whatever_the_list_order(): void
    {
        $this->assertSame(
            RendezvousAssignment::owner('order_projector', ['w1', 'w2', 'w3']),
            RendezvousAssignment::owner('order_projector', ['w3', 'w1', 'w2']),
        );
        $this->assertNull(RendezvousAssignment::owner('order_projector', []));
    }

    public function test_subscriptions_spread_and_only_the_new_workers_share_moves(): void
    {
        $subscriptions = array_map(fn(int $i) => "projector_{$i}", range(1, 300));

        $before = [];
        foreach ($subscriptions as $subscription) {
            $before[$subscription] = RendezvousAssignment::owner($subscription, ['w1', 'w2']);
        }
        $counts = array_count_values($before);
        $this->assertGreaterThan(120, $counts['w1']);
        $this->assertGreaterThan(120, $counts['w2']);

        foreach ($subscriptions as $subscription) {
            $after = RendezvousAssignment::owner($subscription, ['w1', 'w2', 'w3']);
            // Adding w3 only ever moves a subscription TO w3.
            $this->assertContains($after, [$before[$subscription], 'w3']);
        }
    }
}
