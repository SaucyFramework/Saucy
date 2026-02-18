<?php

namespace Saucy\Core\Subscriptions\PoisonMessages;

use Illuminate\Notifications\Notifiable;
use Saucy\Core\Notifications\PoisonMessageDetected;
use Saucy\MessageStorage\StoredEvent;

final readonly class PoisonMessageRecorder
{
    public function __construct(
        private PoisonMessageStore $poisonMessageStore,
        private ?object $notifiable = null,
    ) {}

    public function record(string $subscriptionId, StoredEvent $storedEvent, \Throwable $exception, int $retryCount): void
    {
        $poisonMessage = new PoisonMessage(
            id: null,
            subscriptionId: $subscriptionId,
            globalPosition: $storedEvent->globalPosition,
            messageId: $storedEvent->eventId,
            streamName: $storedEvent->streamName,
            errorMessage: $exception->getMessage(),
            stackTrace: $exception->getTraceAsString(),
            retryCount: $retryCount,
            status: PoisonMessageStatus::Poisoned,
            poisonedAt: new \DateTimeImmutable(),
        );

        $this->poisonMessageStore->store($poisonMessage);

        report($exception);

        if ($this->notifiable !== null) {
            /** @var Notifiable $this->notifiable */
            $this->notifiable->notify(new PoisonMessageDetected($poisonMessage)); // @phpstan-ignore-line
        }
    }
}
