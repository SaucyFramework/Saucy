<?php

declare(strict_types=1);

namespace Workbench\Tests\Lanes;

use Illuminate\Support\Facades\Bus;
use Saucy\Core\Subscriptions\Lanes\LaneConfig;
use Saucy\Core\Subscriptions\Lanes\LanePollJob;
use Saucy\Core\Subscriptions\PoisonMessages\PoisonMessageManager;
use Saucy\Core\Subscriptions\PoisonMessages\PoisonMessageStore;
use Saucy\Core\Subscriptions\StreamSubscription\StreamSubscriptionProcessManager;
use Saucy\Core\Subscriptions\StreamSubscription\StreamSubscriptionRegistry;
use Saucy\Core\Subscriptions\StreamSubscription\SyncStreamSubscriptionRegistry;
use Saucy\MessageStorage\ReadEventData;
use Workbench\Tests\Lanes\Fixtures\PassThroughSerializer;
use Workbench\Tests\Lanes\Fixtures\RecordingConsumer;

final class LanePoisonResolutionTest extends LaneTestCase
{
    /** @test resolving a poison message wakes the member's lane instead of its own poll job */
    public function skipping_a_poison_message_bumps_the_lane_and_wakes_it(): void
    {
        Bus::fake();

        $this->insertEvent('type.a'); // 1
        $this->insertEvent('type.a'); // 2

        $consumer = new RecordingConsumer();
        $consumer->throwAt[2] = true;

        $members = ['member_a' => $this->member('member_a', $consumer, ['type.a'])];
        $config = new LaneConfig(name: 'default');

        // Poison event 2 through a real lane poll.
        $this->runner($members, $config)->poll(30);

        $store = $this->app->make(PoisonMessageStore::class);
        $poison = $store->getUnresolved('member_a');
        $this->assertCount(1, $poison);

        $laneRegistry = $this->laneRegistry($members, ['default' => $config]);
        $processManager = $this->allStreamProcessManager($members, $laneRegistry);

        $manager = new PoisonMessageManager(
            poisonMessageStore: $store,
            allStreamRegistry: $this->subscriptionRegistry($members),
            streamRegistry: new StreamSubscriptionRegistry(),
            syncStreamRegistry: new SyncStreamSubscriptionRegistry(),
            allStreamProcessManager: $processManager,
            streamProcessManager: $this->app->make(StreamSubscriptionProcessManager::class),
            readEventData: $this->app->make(ReadEventData::class),
            eventSerializer: new PassThroughSerializer(),
            typeMap: $this->typeMap(),
        );

        $versionBefore = $this->coordinator->membershipVersion('default');

        $manager->skip((int) $poison[0]->id);

        $this->assertGreaterThan(
            $versionBefore,
            $this->coordinator->membershipVersion('default'),
            'the lane must re-evaluate now that the member is no longer halted',
        );
        Bus::assertDispatchedTimes(LanePollJob::class, 1);
        $this->assertSame([], $store->getUnresolved('member_a'));
    }
}
