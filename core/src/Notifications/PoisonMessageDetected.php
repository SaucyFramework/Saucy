<?php

namespace Saucy\Core\Notifications;

use Illuminate\Notifications\AnonymousNotifiable;
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
        if ($notifiable instanceof AnonymousNotifiable) {
            return array_keys($notifiable->routes);
        }

        return ['mail'];
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
     * Requires laravel/slack-notification-channel to be installed.
     */
    public function toSlack(object $notifiable): mixed
    {
        $class = \Illuminate\Notifications\Slack\SlackMessage::class; // @phpstan-ignore-line
        if (!class_exists($class)) {
            throw new \RuntimeException('laravel/slack-notification-channel is required for Slack notifications. Install it via: composer require laravel/slack-notification-channel');
        }

        /** @var mixed $message */
        $message = new $class();

        return $message /** @phpstan-ignore-line */
            ->headerBlock('Poison Message Detected')
            ->sectionBlock(function ($block) {
                $block->text('A poison message was detected in subscription *' . $this->poisonMessage->subscriptionId . '*.');
            })
            ->sectionBlock(function ($block) {
                $block->field("*Stream:*\n" . $this->poisonMessage->streamName)->markdown();
                $block->field("*Event ID:*\n" . $this->poisonMessage->messageId)->markdown();
            })
            ->sectionBlock(function ($block) {
                $block->field("*Error:*\n" . $this->poisonMessage->errorMessage)->markdown();
                $block->field("*Retries:*\n" . $this->poisonMessage->retryCount)->markdown();
            });
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
