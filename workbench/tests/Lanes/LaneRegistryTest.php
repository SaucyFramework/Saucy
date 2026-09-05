<?php

declare(strict_types=1);

namespace Workbench\Tests\Lanes;

use Saucy\Core\Projections\ProjectorConfig;
use Saucy\Core\Projections\ProjectorType;
use Saucy\Core\Subscriptions\Lanes\LaneConfig;
use Saucy\Core\Subscriptions\Lanes\LaneRegistry;
use Workbench\Tests\Lanes\Fixtures\RecordingConsumer;

final class LaneRegistryTest extends LaneTestCase
{
    /** @test */
    public function it_resolves_lanes_from_assignments_then_the_attribute_then_the_default(): void
    {
        $members = [
            'member_plain' => $this->member('member_plain', new RecordingConsumer(), ['type.a']),
            'member_attr' => $this->member('member_attr', new RecordingConsumer(), ['type.b']),
            'member_assigned' => $this->member('member_assigned', new RecordingConsumer(), ['type.c']),
            'member_unknown_lane' => $this->member('member_unknown_lane', new RecordingConsumer(), ['type.d']),
            'replay__member_plain' => $this->member('replay__member_plain', new RecordingConsumer(), ['type.a']),
        ];

        $registry = new LaneRegistry(
            ['money' => new LaneConfig(name: 'money')],
            ['member_assigned' => 'money'],
            ['member_attr' => 'money', 'member_assigned' => 'default', 'member_unknown_lane' => 'nope'],
            $this->subscriptionRegistry($members),
        );

        $this->assertTrue($registry->enabled());
        // A 'default' lane is created implicitly to catch everything that names no lane.
        $this->assertSame(['money', 'default'], array_keys($registry->lanes()));

        $this->assertSame('default', $registry->laneFor('member_plain')?->name);
        $this->assertSame('money', $registry->laneFor('member_attr')?->name, 'the attribute wins over the default');
        $this->assertSame('money', $registry->laneFor('member_assigned')?->name, 'an assignment wins over the attribute');
        $this->assertSame('default', $registry->laneFor('member_unknown_lane')?->name, 'an unconfigured lane falls back');

        // Background replay subscriptions are never lane members.
        $this->assertNull($registry->laneFor('replay__member_plain'));

        $this->assertSame(
            ['member_attr', 'member_assigned'],
            array_keys($registry->members('money')),
        );
        $this->assertSame(['type.b', 'type.c'], $registry->eventTypesFor('money'));
        $this->assertSame('lane__money', $registry->laneSubscriptionId('money'));
    }

    /** @test */
    public function a_member_that_subscribes_to_everything_makes_the_lane_union_null(): void
    {
        $members = [
            'member_typed' => $this->member('member_typed', new RecordingConsumer(), ['type.a']),
            'member_all' => $this->member('member_all', new RecordingConsumer(), null),
        ];

        $registry = $this->laneRegistry($members, ['default' => new LaneConfig(name: 'default')]);

        $this->assertNull($registry->eventTypesFor('default'));
    }

    /** @test */
    public function lane_settings_come_from_the_config_block_with_defaults_filled_in(): void
    {
        $config = LaneConfig::fromArray('money', [
            'queue' => 'money-projections',
            'page_size' => 25,
            'sleep_ms' => 100,
            'commit_batch_size' => 1,
            'retry_budget_seconds' => 3,
            'quiesce_wait_seconds' => 5,
        ]);

        $this->assertSame('money', $config->name);
        $this->assertSame('money-projections', $config->queue);
        $this->assertSame(25, $config->pageSize);
        $this->assertSame(100_000, $config->sleepInMicroseconds);
        $this->assertSame(1, $config->effectiveCommitBatchSize());
        $this->assertSame(3, $config->retryPolicy()->maxTotalSeconds);
        $this->assertSame(5, $config->quiesceWaitInSeconds);

        // Untouched keys keep their documented defaults.
        $this->assertSame(240, $config->processTimeoutInSeconds);
        $this->assertSame(30, $config->keepAliveInSeconds);
        $this->assertSame(1000, $config->catchUpThreshold);
        $this->assertSame(20, LaneConfig::fromArray('default', [])->quiesceWaitInSeconds);

        // commit_batch_size defaults to the page size.
        $this->assertSame(100, LaneConfig::fromArray('default', [])->effectiveCommitBatchSize());
    }

    /** @test a projector map cached before lanes existed has no 'lane' key */
    public function projector_config_defaults_the_lane_to_null_for_old_cached_payloads(): void
    {
        $legacyPayload = (new ProjectorConfig(
            projectorClass: RecordingConsumer::class,
            handlingEventClasses: [],
            projectorType: ProjectorType::AllStream,
        ))->toPayload();

        unset($legacyPayload['lane']);

        $this->assertNull(ProjectorConfig::fromPayload($legacyPayload)->lane);
        $this->assertSame('money', ProjectorConfig::fromPayload($legacyPayload + ['lane' => 'money'])->lane);
    }
}
