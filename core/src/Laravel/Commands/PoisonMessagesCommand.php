<?php

namespace Saucy\Core\Laravel\Commands;

use Illuminate\Console\Command;
use Saucy\Core\Subscriptions\PoisonMessages\PoisonMessageManager;

final class PoisonMessagesCommand extends Command
{
    protected $signature = 'saucy:poison-messages
        {action=list : The action to perform (list, retry, skip)}
        {id? : The poison message ID (required for retry and skip)}
        {--subscription= : Filter by subscription ID (for list)}';

    protected $description = 'Manage poison messages';

    public function handle(PoisonMessageManager $manager): int
    {
        /** @var string $action */
        $action = $this->argument('action');

        return match ($action) {
            'list' => $this->listMessages($manager),
            'retry' => $this->retryMessage($manager),
            'skip' => $this->skipMessage($manager),
            default => $this->invalidAction($action),
        };
    }

    private function listMessages(PoisonMessageManager $manager): int
    {
        /** @var string|null $subscriptionId */
        $subscriptionId = $this->option('subscription');
        $messages = $manager->listUnresolved($subscriptionId);

        if ($messages->isEmpty()) {
            $this->info('No unresolved poison messages.');
            return Command::SUCCESS;
        }

        $this->table(
            ['ID', 'Subscription', 'Stream', 'Global Pos', 'Error', 'Retries', 'Poisoned At'],
            $messages->map(fn($m) => [
                $m->id,
                $m->subscriptionId,
                $m->streamName,
                $m->globalPosition,
                mb_substr($m->errorMessage, 0, 60) . (mb_strlen($m->errorMessage) > 60 ? '...' : ''),
                $m->retryCount,
                $m->poisonedAt->format('Y-m-d H:i:s'),
            ])->toArray(),
        );

        return Command::SUCCESS;
    }

    private function retryMessage(PoisonMessageManager $manager): int
    {
        /** @var string|null $id */
        $id = $this->argument('id');
        if ($id === null) {
            $this->error('The id argument is required for the retry action.');
            return Command::FAILURE;
        }

        try {
            $manager->retry((int) $id);
            $this->info("Poison message #{$id} retried successfully and marked as resolved.");
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("Retry failed for poison message #{$id}: " . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function skipMessage(PoisonMessageManager $manager): int
    {
        /** @var string|null $id */
        $id = $this->argument('id');
        if ($id === null) {
            $this->error('The id argument is required for the skip action.');
            return Command::FAILURE;
        }

        $manager->skip((int) $id);
        $this->info("Poison message #{$id} has been skipped.");
        return Command::SUCCESS;
    }

    private function invalidAction(string $action): int
    {
        $this->error("Unknown action: {$action}. Valid actions are: list, retry, skip");
        return Command::FAILURE;
    }
}
