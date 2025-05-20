<?php

namespace Saucy\Core\Framework;

use Illuminate\Support\Facades\Cache;
use League\ConstructFinder\ConstructFinder;
use Saucy\Core\Events\EventTypeMapBuilder;
use Saucy\Core\Events\Streams\AggregateStreamName;
use Saucy\Core\EventSourcing\CommandHandling\EventSourcingCommandMapBuilder;
use Saucy\Core\EventSourcing\TypeMap\AggregateRootTypeMapBuilder;
use Saucy\Core\Projections\ProjectorMapBuilder;
use Saucy\Core\Query\QueryHandlerMapBuilder;
use Saucy\Core\Serialisation\TypeMap;

final readonly class BuildSaucyProjectMappings
{
    public function __construct(
        public ?string $path,
    ) {}

    public function get(bool $forceNew = false): SaucyProjectMaps
    {
        if ($this->path === null || $forceNew) {
            return $this->generate();
        }

        $data = $this->getFromFile($this->path);
        if ($data === null) {
            return $this->build();
        }
        return SaucyProjectMaps::fromPayload($data);
    }

    public function build(): SaucyProjectMaps
    {
        $map = $this->generate();
        $this->writeToFile($this->path, $map->toPayload());
        return $map;
    }

    private function generate(): SaucyProjectMaps
    {
        $classes = ConstructFinder::locatedIn(...config('saucy.directories'))
            ->exclude(...config('saucy.exclude_files', ['*Test.php', '*/Tests/*', '*TestCase.php']))
            ->findClassNames(); // @phpstan-ignore-line

        // build type map
        $typeMap = TypeMap::of(
            AggregateRootTypeMapBuilder::make()->create($classes),
            EventTypeMapBuilder::make()->create($classes),
            new TypeMap([
                AggregateStreamName::class => 'aggregate_stream_name',
            ]),
        );

        $projectorMap = ProjectorMapBuilder::buildForClasses($classes, $typeMap);

        $commandTaskMap = EventSourcingCommandMapBuilder::buildTaskMapForClasses($classes);

        $queryMap = QueryHandlerMapBuilder::buildQueryMapForClasses($classes);

        return new SaucyProjectMaps(
            typeMap: $typeMap,
            projectorMap: $projectorMap,
            commandTaskMap: $commandTaskMap,
            queryMap: $queryMap,
        );
    }

    public function writeToFile(string $filePath, array $data): void
    {
        $dir = dirname($filePath);

        if (!is_dir($dir)) {
            if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException("Failed to create directory: {$dir}");
            }
        }

        file_put_contents($filePath, serialize($data));
    }

    public function getFromFile($path): ?array
    {
        if (!file_exists($path)) {
            return null;
        }

        $contents = file_get_contents($path);
        $data = @unserialize($contents);

        return is_array($data) ? $data : null;
    }
}
