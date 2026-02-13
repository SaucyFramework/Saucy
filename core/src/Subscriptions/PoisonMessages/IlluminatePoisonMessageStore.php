<?php

namespace Saucy\Core\Subscriptions\PoisonMessages;

use Illuminate\Database\ConnectionInterface;

final readonly class IlluminatePoisonMessageStore implements PoisonMessageStore
{
    public function __construct(
        private ConnectionInterface $connection,
        private string $tableName = 'poison_messages',
    ) {}

    public function store(PoisonMessage $message): void
    {
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
