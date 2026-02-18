<?php

namespace Saucy\Core\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Saucy\Core\Subscriptions\PoisonMessages\PoisonMessage;

class PoisonMessageDetected extends Notification
{
    public function __construct(
        public readonly PoisonMessage $poisonMessage,
    ) {}

    /**
     * @return array<string>
     */
    public function via(object $notifiable): array
    {
        /** @var array<string> $channels */
        $channels = method_exists($notifiable, 'poisonMessageNotificationChannels')
            ? $notifiable->poisonMessageNotificationChannels()
            : ['mail'];

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->error()
            ->subject('Poison Message Detected: ' . $this->poisonMessage->subscriptionId)
            ->line('A poison message was detected in subscription **' . $this->poisonMessage->subscriptionId . '**.')
            ->line('**Stream:** ' . $this->poisonMessage->streamName)
            ->line('**Event ID:** ' . $this->poisonMessage->messageId)
            ->line('**Error:** ' . $this->poisonMessage->errorMessage)
            ->line('**Retries:** ' . $this->poisonMessage->retryCount)
            ->line('Use `php artisan saucy:poison-messages list` to view all poison messages.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'subscription_id' => $this->poisonMessage->subscriptionId,
            'stream_name' => $this->poisonMessage->streamName,
            'message_id' => $this->poisonMessage->messageId,
            'error_message' => $this->poisonMessage->errorMessage,
            'retry_count' => $this->poisonMessage->retryCount,
        ];
    }
}
