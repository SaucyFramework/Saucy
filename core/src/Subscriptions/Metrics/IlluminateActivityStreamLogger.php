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

    public function getLog(?string $streamId, int $limit = 100, int $offset = 0): array
    {
        /** @var array<SubscriptionActivity> */
        return DB::table('subscription_activity_stream_log')
            ->when($streamId !== null, fn($query) => $query->where('stream_id', $streamId))
            ->orderBy('id', 'desc')
            ->offset($offset)
            ->limit($limit)
            ->get()->map(
                fn($activity) => new SubscriptionActivity(
                    $activity->stream_id,
                    $activity->type,
                    $activity->message,
                    new \DateTime($activity->occurred_at),
                    /** @phpstan-ignore-next-line */
                    json_decode($activity->data, true),
                ),
            )->toArray();
    }

    public function getLogBetween(?string $streamId, \DateTime $from, \DateTime $to, int $limit = 100, int $offset = 0): array
    {
        /** @var array<SubscriptionActivity> */
        return DB::table('subscription_activity_stream_log')
            ->when($streamId !== null, fn($query) => $query->where('stream_id', $streamId))
            ->where('occurred_at', '>=', $from->format('Y-m-d H:i:s'))
            ->where('occurred_at', '<=', $to->format('Y-m-d H:i:s'))
            ->orderBy('occurred_at', 'desc')
            ->offset($offset)
            ->limit($limit)
            ->get()->map(
                fn($activity) => new SubscriptionActivity(
                    $activity->stream_id,
                    $activity->type,
                    $activity->message,
                    new \DateTime($activity->occurred_at),
                    /** @phpstan-ignore-next-line */
                    json_decode($activity->data, true),
                ),
            )->toArray();
    }

    public function purgeOld(?\DateTime $before = null): void
    {
        if ($before === null) {
            /** @var int|null $retentionDays */
            $retentionDays = config('saucy.activity_log_retention_days');
            if ($retentionDays !== null) {
                $before = (new \DateTime('now'))->sub(new \DateInterval("P{$retentionDays}D"));
            }
        }

        $before ??= (new \DateTime('now'))->sub(new \DateInterval('P1W'));
        $dateThreshold = $before->format('Y-m-d H:i:s');

        // Use a batch size smaller than the 100,000 row limit
        $batchSize = 10000;

        do {
            // Delete records in small batches
            $affectedRows = DB::table('subscription_activity_stream_log')
                ->where('occurred_at', '<', $dateThreshold)
                ->limit($batchSize)
                ->delete();

            // If we deleted fewer rows than the batch size, we're done
        } while ($affectedRows > 0);
    }
}
