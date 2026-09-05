<?php

declare(strict_types=1);

namespace Saucy\Core\Subscriptions\Lanes;

use Saucy\Core\Projections\Replay\ReplaySubscriptionFactory;
use Saucy\Core\Subscriptions\AllStream\AllStreamSubscription;
use Saucy\Core\Subscriptions\AllStream\AllStreamSubscriptionRegistry;

/**
 * Maps all-stream subscriptions onto lanes.
 *
 * Resolution order for a subscription: `saucy.lane_assignments` (operator override) →
 * `#[Projector(lane: ...)]` → the `default` lane. A lane named by an attribute or an assignment
 * that is not configured falls back to `default`, which is created with the built-in defaults
 * when it is not configured explicitly.
 */
final class LaneRegistry
{
    /** @var array<string, LaneConfig> */
    private array $lanes;

    /** @var array<string, string>|null subscription id => lane name */
    private ?array $resolved = null;

    /**
     * @param array<string, LaneConfig> $lanes
     * @param array<string, string> $laneAssignments subscription id => lane name
     * @param array<string, string|null> $attributeLanes subscription id => lane name from the attribute
     */
    public function __construct(
        array $lanes,
        private readonly array $laneAssignments,
        private readonly array $attributeLanes,
        private readonly AllStreamSubscriptionRegistry $allStreamSubscriptionRegistry,
    ) {
        // Any lane referenced by config implies a 'default' lane exists to catch the rest.
        if ($lanes !== [] && !isset($lanes[LaneConfig::DEFAULT_LANE])) {
            $lanes[LaneConfig::DEFAULT_LANE] = LaneConfig::fromArray(LaneConfig::DEFAULT_LANE, []);
        }

        $this->lanes = $lanes;
    }

    /**
     * @param array<string, mixed> $lanesConfig
     * @param array<string, string> $laneAssignments
     * @param array<string, string|null> $attributeLanes
     */
    public static function fromConfig(
        array $lanesConfig,
        array $laneAssignments,
        array $attributeLanes,
        AllStreamSubscriptionRegistry $allStreamSubscriptionRegistry,
    ): self {
        $lanes = [];
        foreach ($lanesConfig as $name => $settings) {
            $lanes[(string) $name] = LaneConfig::fromArray((string) $name, is_array($settings) ? $settings : []);
        }

        return new self($lanes, $laneAssignments, $attributeLanes, $allStreamSubscriptionRegistry);
    }

    public function enabled(): bool
    {
        return $this->lanes !== [];
    }

    /**
     * @return array<string, LaneConfig>
     */
    public function lanes(): array
    {
        return $this->lanes;
    }

    public function lane(string $name): LaneConfig
    {
        return $this->lanes[$name] ?? throw new \RuntimeException("Lane not configured: {$name}");
    }

    public function laneFor(string $subscriptionId): ?LaneConfig
    {
        if (!$this->enabled()) {
            return null;
        }

        $name = $this->resolveAssignments()[$subscriptionId] ?? null;

        return $name === null ? null : $this->lanes[$name];
    }

    /**
     * @return array<string, AllStreamSubscription> keyed by subscription id
     */
    public function members(string $lane): array
    {
        $members = [];
        foreach ($this->resolveAssignments() as $subscriptionId => $name) {
            if ($name === $lane) {
                $members[$subscriptionId] = $this->allStreamSubscriptionRegistry->streams[$subscriptionId];
            }
        }

        return $members;
    }

    /**
     * Union of the members' event types. Null when any member subscribes to everything.
     *
     * @return array<string>|null
     */
    public function eventTypesFor(string $lane): ?array
    {
        $types = [];
        foreach ($this->members($lane) as $member) {
            if ($member->streamOptions->eventTypes === null) {
                return null;
            }
            foreach ($member->streamOptions->eventTypes as $type) {
                $types[$type] = true;
            }
        }

        return array_keys($types);
    }

    public function laneSubscriptionId(string $lane): string
    {
        return 'lane__' . $lane;
    }

    /**
     * @return array<string, string> subscription id => lane name
     */
    private function resolveAssignments(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $resolved = [];
        foreach ($this->allStreamSubscriptionRegistry->streams as $subscriptionId => $subscription) {
            // Background replay subscriptions run standalone and are never lane members.
            if (str_starts_with($subscriptionId, ReplaySubscriptionFactory::replaySubscriptionId(''))) {
                continue;
            }

            $name = $this->laneAssignments[$subscriptionId]
                ?? $this->attributeLanes[$subscriptionId]
                ?? LaneConfig::DEFAULT_LANE;

            $resolved[$subscriptionId] = isset($this->lanes[$name]) ? $name : LaneConfig::DEFAULT_LANE;
        }

        return $this->resolved = $resolved;
    }
}
