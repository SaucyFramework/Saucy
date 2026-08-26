<?php

namespace Saucy\Core\Subscriptions\PoisonMessages;

use Illuminate\Database\ConnectionInterface;

final readonly class IlluminatePoisonMessageStore implements PoisonMessageStore
{
    public function __construct(
        private ConnectionInterface $connection,
        private string $tableName = 'poison_messages',
    ) {}

    /**
     * Records a poisoned event, collapsing repeats of the SAME event onto the
     * row that is already open for it.
     *
     * A subscription in FailureMode::Halt rethrows after poisoning, which
     * aborts the poll WITHOUT advancing the checkpoint — deliberately, so it
     * stays parked on the bad event and heals itself once the handler is
     * fixed. But the crashed poll job also releases its RunningProcesses lock,
     * so the next tick starts a fresh process that re-reads the same
     * checkpoint, hits the same event and records it again. A blind INSERT
     * therefore writes one row per poll cycle for as long as the projector
     * stays stuck: an application running this saw 9,003 rows covering only
     * 103 distinct (subscription, event) pairs, 2,910 of them for a single
     * event over two days.
     *
     * Only a still-`poisoned` row is reused. A resolved or skipped row is
     * closed history, so an event that poisons again after being cleared opens
     * a new one.
     *
     * `poisoned_at` keeps the FIRST failure — the row reads as "stuck since",
     * and a retention purge ages it from then. `updated_at` is the latest
     * failure, and `retry_count` accumulates handler attempts across every
     * cycle rather than counting cycles.
     */
    public function store(PoisonMessage $message): void
    {
        $openRowId = $this->connection->table($this->tableName)
            ->where('subscription_id', $message->subscriptionId)
            ->where('message_id', $message->messageId)
            ->where('status', PoisonMessageStatus::Poisoned->value)
            ->orderByDesc('id')
            ->value('id');

        if ($openRowId !== null) {
            $this->connection->table($this->tableName)
                ->where('id', $openRowId)
                ->update([
                    'error_message' => $message->errorMessage,
                    'stack_trace' => $message->stackTrace,
                    'global_position' => $message->globalPosition,
                    'retry_count' => $this->connection->raw('retry_count + ' . max(1, $message->retryCount)),
                    'updated_at' => now()->format('Y-m-d H:i:s'),
                ]);

            return;
        }

        $this->connection->table($this->tableName)->insert([
            'subscription_id' => $message->subscriptionId,
            'global_position' => $message->globalPosition,
            'message_id' => $message->messageId,
            'stream_name' => $message->streamName,
            'error_message' => $message->errorMessage,
            'stack_trace' => $message->stackTrace,
            'retry_count' => $message->retryCount,
            'status' => $message->status->value,
            'poisoned_at' => $message->poisonedAt->format('Y-m-d H:i:s'),
            'resolved_at' => $message->resolvedAt?->format('Y-m-d H:i:s'),
            'created_at' => now()->format('Y-m-d H:i:s'),
            'updated_at' => now()->format('Y-m-d H:i:s'),
        ]);
    }

    public function resolve(int $id): void
    {
        $this->connection->table($this->tableName)
            ->where('id', $id)
            ->update([
                'status' => PoisonMessageStatus::Resolved->value,
                'resolved_at' => now()->format('Y-m-d H:i:s'),
                'updated_at' => now()->format('Y-m-d H:i:s'),
            ]);
    }

    public function skip(int $id): void
    {
        $this->connection->table($this->tableName)
            ->where('id', $id)
            ->update([
                'status' => PoisonMessageStatus::Skipped->value,
                'resolved_at' => now()->format('Y-m-d H:i:s'),
                'updated_at' => now()->format('Y-m-d H:i:s'),
            ]);
    }

    public function updateAfterFailedRetry(int $id, string $errorMessage, string $stackTrace): void
    {
        $this->connection->table($this->tableName)
            ->where('id', $id)
            ->update([
                'error_message' => $errorMessage,
                'stack_trace' => $stackTrace,
                'retry_count' => $this->connection->raw('retry_count + 1'),
                'updated_at' => now()->format('Y-m-d H:i:s'),
            ]);
    }

    public function get(int $id): PoisonMessage
    {
        $row = $this->connection->table($this->tableName)->find($id);

        if (!$row) {
            throw new \RuntimeException("Poison message with id {$id} not found");
        }

        /** @var \stdClass $row */
        return $this->rowToMessage($row);
    }

    public function getUnresolved(?string $subscriptionId = null): array
    {
        $query = $this->connection->table($this->tableName)
            ->where('status', PoisonMessageStatus::Poisoned->value);

        if ($subscriptionId !== null) {
            $query->where('subscription_id', $subscriptionId);
        }

        return array_map(
            fn(object $row) => $this->rowToMessage($row),
            $query->orderBy('id')->get()->all(),
        );
    }

    public function getUnresolvedForStream(string $subscriptionId, string $streamName): array
    {
        return array_map(
            fn(object $row) => $this->rowToMessage($row),
            $this->connection->table($this->tableName)
                ->where('subscription_id', $subscriptionId)
                ->where('stream_name', $streamName)
                ->where('status', PoisonMessageStatus::Poisoned->value)
                ->orderBy('id')
                ->get()
                ->all(),
        );
    }

    public function hasUnresolvedForStream(string $subscriptionId, string $streamName): bool
    {
        return $this->connection->table($this->tableName)
            ->where('subscription_id', $subscriptionId)
            ->where('stream_name', $streamName)
            ->where('status', PoisonMessageStatus::Poisoned->value)
            ->exists();
    }

    private function rowToMessage(\stdClass $row): PoisonMessage
    {
        return new PoisonMessage(
            id: $row->id,
            subscriptionId: $row->subscription_id,
            globalPosition: $row->global_position,
            messageId: $row->message_id,
            streamName: $row->stream_name,
            errorMessage: $row->error_message,
            stackTrace: $row->stack_trace,
            retryCount: $row->retry_count,
            status: PoisonMessageStatus::from($row->status),
            poisonedAt: new \DateTimeImmutable($row->poisoned_at),
            resolvedAt: $row->resolved_at ? new \DateTimeImmutable($row->resolved_at) : null,
        );
    }
}
