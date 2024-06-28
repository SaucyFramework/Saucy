<?php

namespace Saucy\Core\Subscriptions\Metrics;

use Illuminate\Support\Facades\DB;

final readonly class IlluminateActivityStreamLogger implements ActivityStreamLogger
{
    public function log(SubscriptionActivity ...$subscriptionActivity): void
    {
        DB::table('subscription_activity_stream_log')->insert(
            array_map(
                fn(SubscriptionActivity $activity) => [
                    'stream_id' => $activity->streamId,
                    'type' => $activity->type,
                    'message' => $activity->message,
                    'occurred_at' => $activity->occurredAt->format('Y-m-d H:i:s'),
                    'data' => json_encode($activity->data),
                ],
                $subscriptionActivity,
            ),
        );
    }

    public function getLog(?string $streamId): array
    {
        return DB::table('subscription_activity_stream_log')
            ->when($streamId !== null, fn($query) => $query->where('stream_id', $streamId))
            ->orderBy('id', 'desc')
            ->take(100)
            ->get()->map(
                fn($activity) => new SubscriptionActivity(
                    $activity->stream_id,
                    $activity->type,
                    $activity->message,
                    new \DateTime($activity->occurred_at),
                    json_decode($activity->data, true),
                ),
            )->toArray();
    }

    public function purgeOld(?\DateTime $before = null): void
    {
        $before ??= (new \DateTime('now'))->sub(new \DateInterval('P1W'));
        DB::table('subscription_activity_stream_log')
            ->where('occurred_at', '<', $before->format('Y-m-d H:i:s'))
            ->delete();
    }
}
