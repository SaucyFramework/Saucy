<?php

namespace Workbench\App\BankAccount\Events;

use EventSauce\EventSourcing\Serialization\SerializablePayload;
use Saucy\Core\Events\Event;

#[Event('AccountDebited')]
final readonly class AccountDebited implements SerializablePayload
{
    public function __construct(
        public int $amount,
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
