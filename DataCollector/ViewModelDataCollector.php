<?php

declare(strict_types=1);

namespace Toppy\SymfonyAsyncTwigBundle\DataCollector;

use Symfony\Bundle\FrameworkBundle\DataCollector\AbstractDataCollector;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\LateDataCollectorInterface;
use Toppy\AsyncViewModel\Profiler\TimelineEntry;
use Toppy\AsyncViewModel\Profiler\ViewModelProfilerInterface;

/**
 * Collects ViewModel resolution data for Symfony Profiler.
 *
 * Implements LateDataCollectorInterface because StreamedResponse callbacks
 * execute AFTER kernel.response (when collect() runs). The actual profiler
 * data is only available at kernel.terminate when lateCollect() runs.
 *
 * @mago-expect analysis:mixed-return-statement
 *
 * Symfony AbstractDataCollector $this->data is typed as mixed.
 * Getter methods return values from $this->data with null coalescing defaults.
 */
final class ViewModelDataCollector extends AbstractDataCollector implements LateDataCollectorInterface
{
    public function __construct(
        private readonly ViewModelProfilerInterface $profiler,
    ) {}

    #[\Override]
    public static function getTemplate(): ?string
    {
        return '@ToppySymfonyAsyncTwig/data_collector/view_model.html.twig';
    }

    #[\Override]
    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
        // Intentionally empty - data collected in lateCollect() for StreamedResponse support
    }

    #[\Override]
    public function lateCollect(): void
    {
        $entries = $this->profiler->getEntries();

        $this->data = [
            'entries' => array_map(static fn(TimelineEntry $e) => [
                'class' => $e->viewModelClass,
                'shortName' => $e->getShortName(),
                'start' => $e->startTime,
                'end' => $e->endTime,
                'duration' => $e->getDuration(),
                'status' => $e->status,
                'error' => $e->errorMessage,
                'dependencies' => $e->dependencies,
            ], $entries),
            'totalTime' => $this->profiler->getTotalTime(),
            'parallelEfficiency' => $this->profiler->getParallelEfficiency(),
            'count' => count($entries),
        ];
    }

    #[\Override]
    public function getName(): string
    {
        return 'toppy.view_model';
    }

    /**
     * @return array<array{class: string, shortName: string, start: float, end: float, duration: float, status: string, error: ?string, dependencies: array}>
     */
    public function getEntries(): array
    {
        return $this->data['entries'] ?? [];
    }

    public function getTotalTime(): float
    {
        return $this->data['totalTime'] ?? 0.0;
    }

    public function getParallelEfficiency(): float
    {
        return $this->data['parallelEfficiency'] ?? 1.0;
    }

    public function getCount(): int
    {
        return $this->data['count'] ?? 0;
    }
}
