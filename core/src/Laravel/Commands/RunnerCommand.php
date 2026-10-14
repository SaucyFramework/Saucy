<?php

declare(strict_types=1);

namespace Saucy\Core\Laravel\Commands;

use Illuminate\Console\Command;
use Saucy\Core\Subscriptions\AllStream\AllStreamSubscriptionProcessManager;
use Saucy\Core\Subscriptions\AllStream\AllStreamSubscriptionRegistry;
use Saucy\Core\Subscriptions\StreamSubscription\StreamSubscriptionRegistry;
use Saucy\Core\Subscriptions\Worker\Runner;
use Saucy\Core\Subscriptions\Worker\SubscriptionRunners;

/**
 * Moves subscriptions between the queue and the worker at runtime, without a deploy.
 *
 *   saucy:runner                              list every subscription and its runner
 *   saucy:runner order_projector worker       run order_projector on `saucy:work`
 *   saucy:runner order_projector queue        back to queue jobs (the rollback)
 *   saucy:runner order_projector --clear      back to the default
 *   saucy:runner --default=worker             change the default for every subscription
 *
 * Checkpoints and locks are shared, so a switch is safe at any moment; the other runner picks up
 * from the checkpoint within a few seconds.
 */
final class RunnerCommand extends Command
{
    protected $signature = 'saucy:runner
        {subscription? : Subscription id}
        {runner? : queue or worker}
        {--clear : Remove the override, so the default applies}
        {--default= : Set (queue/worker) or, with "clear", remove the default override}';

    protected $description = 'Show or switch which runner (queue or worker) runs each subscription';

    public function handle(
        SubscriptionRunners $runners,
        AllStreamSubscriptionRegistry $allStreams,
        StreamSubscriptionRegistry $streams,
        AllStreamSubscriptionProcessManager $processManager,
    ): int {
        $known = array_merge(array_keys($allStreams->streams), array_keys($streams->streams));

        $default = $this->option('default');
        if (is_string($default) && $default !== '') {
            $runners->set(SubscriptionRunners::DEFAULT_KEY, $default === 'clear' ? null : $this->runner($default));
            $this->info('Default runner: ' . $runners->runner(SubscriptionRunners::DEFAULT_KEY)->value);
            $this->kickQueue($runners, $processManager, array_keys($allStreams->streams));

            return self::SUCCESS;
        }

        $subscription = $this->argument('subscription');
        if (!is_string($subscription) || $subscription === '') {
            $overrides = $runners->overrides();
            $this->table(['Subscription', 'Runner', 'Source'], array_map(fn(string $id) => [
                $id,
                $runners->runner($id)->value,
                isset($overrides[$id]) ? 'override' : 'default',
            ], $known));

            return self::SUCCESS;
        }

        if (!in_array($subscription, $known, true)) {
            $this->error("Unknown subscription '{$subscription}'.");

            return self::FAILURE;
        }

        $runner = $this->argument('runner');
        if ($this->option('clear')) {
            $runners->set($subscription, null);
        } elseif (is_string($runner)) {
            $runners->set($subscription, $this->runner($runner));
        } else {
            $this->error('Give a runner (queue or worker) or --clear.');

            return self::FAILURE;
        }

        $this->info("{$subscription}: " . $runners->runner($subscription)->value);
        $this->kickQueue($runners, $processManager, isset($allStreams->streams[$subscription]) ? [$subscription] : []);

        return self::SUCCESS;
    }

    private function runner(string $value): Runner
    {
        return Runner::tryFrom($value) ?? throw new \InvalidArgumentException("Runner must be queue or worker, got '{$value}'");
    }

    /**
     * A subscription handed back to the queue would otherwise wait for its next event to be
     * triggered; start a poll for it now. (Per-aggregate projectors are triggered per stream: the
     * worker still finishes the triggers it holds.)
     *
     * @param list<string> $subscriptionIds
     */
    private function kickQueue(SubscriptionRunners $runners, AllStreamSubscriptionProcessManager $processManager, array $subscriptionIds): void
    {
        foreach ($subscriptionIds as $subscriptionId) {
            if (!$runners->runsOnWorker($subscriptionId)) {
                $processManager->startProcess($subscriptionId);
            }
        }
    }
}
