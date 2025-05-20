<?php

namespace Saucy\Core\Laravel\Commands;

use Illuminate\Console\Command;
use Saucy\Core\Framework\BuildSaucyProjectMappings;

final class BuildSaucyCache extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'saucy:build-cache';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Builds new cache from discovered annotations';

    /**
     * Execute the console command.
     */
    public function handle(
        BuildSaucyProjectMappings $mappings
    ): int {
        $this->info('Building cache...');

        $mappings->build();

        $this->info('Cache built successfully.');

        return Command::SUCCESS;
    }
}
