<?php

declare(strict_types=1);

namespace Saucy\Core\Laravel\Commands;

use Illuminate\Console\Command;
use Saucy\Core\Subscriptions\Worker\WorkerCoordination;
use Saucy\Core\Subscriptions\Worker\WorkerInfo;
use Saucy\MessageStorage\AllStreamReader;

/**
 * The deploy side of the projection worker's version gate.
 *
 *   pause --sha=<commit>  before migrations: workers on any other commit finish their poll, hand
 *                         their locks back and take no new ones. The first worker on <commit> lifts
 *                         the pause. New workers look back to the head recorded here.
 *   status                show the gate and the live workers.
 *   clear                 remove the gate; every worker may take locks again.
 */
final class WorkerGateCommand extends Command
{
    protected $signature = 'saucy:worker-gate
        {action : pause, status or clear}
        {--sha= : The commit being deployed (pause)}';

    protected $description = 'Pause projection workers for a deploy, or show/clear the gate';

    public function handle(WorkerCoordination $coordination, AllStreamReader $reader): int
    {
        switch ($this->argument('action')) {
            case 'pause':
                $sha = $this->option('sha');
                if (!is_string($sha) || $sha === '') {
                    $this->error('pause needs --sha=<commit being deployed>');

                    return self::FAILURE;
                }
                $coordination->pauseForDeploy($sha, $reader->maxEventId());
                $this->info("Workers paused for {$sha}; the first worker on that commit resumes them.");

                return self::SUCCESS;

            case 'clear':
                $coordination->clearGate();
                $this->info('Gate cleared.');

                return self::SUCCESS;

            case 'status':
                $gate = $coordination->gate();
                $this->line($gate === null
                    ? 'Gate: none (every worker may take locks)'
                    : "Gate: {$gate->sha}" . ($gate->paused ? ' (paused, waiting for a worker on it)' : ''));

                $this->table(['Worker', 'SHA', 'Heartbeat expires'], array_map(fn(WorkerInfo $worker) => [
                    $worker->id,
                    $worker->sha ?? '-',
                    date('c', $worker->expiresAt),
                ], $coordination->liveWorkers(time())));

                return self::SUCCESS;

            default:
                $this->error('action must be pause, status or clear');

                return self::FAILURE;
        }
    }
}
