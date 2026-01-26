<?php

declare(strict_types=1);

namespace Toppy\SymfonyAsyncTwigBundle\Profiler;

use Symfony\Contracts\Service\ResetInterface as SymfonyResetInterface;
use Toppy\AsyncViewModel\Context\RequestContext;
use Toppy\AsyncViewModel\Context\ViewContext;
use Toppy\AsyncViewModel\Profiler\TimeEpoch;
use Toppy\AsyncViewModel\Profiler\TimelineEntry;
use Toppy\AsyncViewModel\Profiler\ViewModelProfilerInterface;
use Toppy\AsyncViewModel\ResetInterface;

/**
 * Default profiler that collects timing data in memory.
 *
 * @mago-expect analysis:possibly-invalid-operand
 *
 * array_sum() and max() on mapped durations return int|float.
 * Mago cannot verify operand types for division in getParallelEfficiency().
 */
final class ViewModelProfiler implements ViewModelProfilerInterface, ResetInterface, SymfonyResetInterface
{
    /** @var array<string, array{start: float, dependencies: array<class-string>}> */
    private array $pending = [];

    /** @var array<TimelineEntry> */
    private array $entries = [];

    public function __construct(
        private readonly TimeEpoch $epoch,
    ) {}

    #[\Override]
    public function start(
        string $viewModelClass,
        ViewContext $viewContext,
        RequestContext $requestContext,
        array $dependencies = [],
    ): void {
        $this->pending[$viewModelClass] = [
            'start' => $this->epoch->getElapsed(),
            'dependencies' => $dependencies,
        ];
    }

    #[\Override]
    public function finish(string $viewModelClass, mixed $result): void
    {
        if (!isset($this->pending[$viewModelClass])) {
            return;
        }

        $pending = $this->pending[$viewModelClass];

        $this->entries[] = new TimelineEntry(
            viewModelClass: $viewModelClass,
            startTime: $pending['start'],
            endTime: $this->epoch->getElapsed(),
            status: 'success',
            errorMessage: null,
            dependencies: $pending['dependencies'],
        );

        unset($this->pending[$viewModelClass]);
    }

    #[\Override]
    public function fail(string $viewModelClass, \Throwable $exception): void
    {
        if (!isset($this->pending[$viewModelClass])) {
            return;
        }

        $pending = $this->pending[$viewModelClass];

        $this->entries[] = new TimelineEntry(
            viewModelClass: $viewModelClass,
            startTime: $pending['start'],
            endTime: $this->epoch->getElapsed(),
            status: 'error',
            errorMessage: $exception->getMessage(),
            dependencies: $pending['dependencies'],
        );

        unset($this->pending[$viewModelClass]);
    }

    #[\Override]
    public function recordCacheHit(string $viewModelClass, string $cacheStatus, float $startTime, float $endTime): void
    {
        $this->entries[] = new TimelineEntry(
            viewModelClass: $viewModelClass,
            startTime: $startTime,
            endTime: $endTime,
            status: $cacheStatus,
            errorMessage: null,
            dependencies: [],
        );
    }

    #[\Override]
    public function getEntries(): array
    {
        return $this->entries;
    }

    #[\Override]
    public function getParallelEfficiency(): float
    {
        if ($this->entries === []) {
            return 1.0;
        }

        $durations = array_map(static fn(TimelineEntry $e) => $e->getDuration(), $this->entries);
        $maxDuration = max($durations);
        $sumDurations = array_sum($durations);

        if ($sumDurations === 0.0) {
            return 1.0;
        }

        return $maxDuration / $sumDurations;
    }

    #[\Override]
    public function getTotalTime(): float
    {
        if ($this->entries === []) {
            return 0.0;
        }

        $starts = array_map(static fn(TimelineEntry $e) => $e->startTime, $this->entries);
        $ends = array_map(static fn(TimelineEntry $e) => $e->endTime, $this->entries);

        return max($ends) - min($starts);
    }

    #[\Override]
    public function reset(): void
    {
        $this->pending = [];
        $this->entries = [];
    }
}
