<?php

namespace Saucy\Core\Subscriptions\PoisonMessages;

use Illuminate\Notifications\AnonymousNotifiable;
use Saucy\Core\Notifications\PoisonMessageDetected;
use Saucy\MessageStorage\StoredEvent;

final readonly class PoisonMessageRecorder
{
    /**
     * @param array<array{channel: string, route: string}> $notificationRoutes
     */
    public function __construct(
        private PoisonMessageStore $poisonMessageStore,
        private array $notificationRoutes = [],
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

        if ($this->notificationRoutes !== []) {
            $notifiable = new AnonymousNotifiable();
            foreach ($this->notificationRoutes as $route) {
                $notifiable->route($route['channel'], $route['route']);
            }
            $notifiable->notify(new PoisonMessageDetected($poisonMessage));
        }
    }
}
