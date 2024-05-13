<?php

namespace Saucy\Core\Tracing;

final class Tracer
{
    /**
     * @var array<Trace>
     */
    private array $traces = [];

    public function trace(string $type, int|string|array $value): void
    {
        $this->traces[] = new Trace($type, $value);
    }

    /**
     * @return Trace[]
     */
    public function getTraces(): array
    {
        return $this->traces;
    }

    public function getTraceOfType(string $type): array
    {
        return array_filter($this->traces, fn(Trace $trace) => $trace->type === $type);
    }

    public function clearAll(): void
    {
        $this->traces = [];
    }
}
