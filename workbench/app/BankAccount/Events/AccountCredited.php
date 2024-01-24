<?php

namespace Workbench\App\BankAccount\Events;

use EventSauce\EventSourcing\Serialization\SerializablePayload;
use Saucy\Core\Events\Event;

#[Event('AccountCredited')]
final readonly class AccountCredited implements SerializablePayload
{
    public function __construct(
        public int $amount
    ) {
    }

    public function toPayload(): array
    {
        return [
            'amount' => $this->amount,
        ];
    }

    public static function fromPayload(array $payload): static
    {
        return new static(
            $payload['amount'],
        );
    }
}
