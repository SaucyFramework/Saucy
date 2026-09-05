<?php

declare(strict_types=1);

namespace Workbench\Tests\Lanes\Fixtures;

use Saucy\Core\Subscriptions\MessageConsumption\MessageConsumeContext;
use Saucy\Core\Subscriptions\MessageConsumption\MessageConsumer;

class RecordingConsumer implements MessageConsumer
{
    /** @var array<int, int> global positions, in the order they were handled */
    public array $handled = [];

    /** @var array<int, string> global position => subscription id it was handled under */
    public array $subscriptionIds = [];

    /** @var array<int, true> global positions the handler should throw on */
    public array $throwAt = [];

    public function handle(MessageConsumeContext $context): void
    {
        if (isset($this->throwAt[$context->globalPosition])) {
            throw new \RuntimeException('boom at ' . $context->globalPosition);
        }

        $this->handled[] = $context->globalPosition;
        $this->subscriptionIds[$context->globalPosition] = $context->subscriptionId;
    }

    /**
     * @return array<class-string>
     */
    public static function getMessages(): array
    {
        return [];
    }
}
