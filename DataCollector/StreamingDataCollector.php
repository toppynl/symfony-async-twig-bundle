<?php

declare(strict_types=1);

namespace Toppy\SymfonyAsyncTwigBundle\DataCollector;

use Symfony\Bundle\FrameworkBundle\DataCollector\AbstractDataCollector;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\LateDataCollectorInterface;
use Toppy\AsyncViewModel\Profiler\HttpClientProfilerInterface;
use Toppy\AsyncViewModel\Profiler\HttpRequestEntry;
use Toppy\AsyncViewModel\Profiler\TimelineEntry;
use Toppy\AsyncViewModel\Profiler\ViewModelProfilerInterface;
use Toppy\TwigStreaming\Profiler\StreamingTimelineEvent;
use Toppy\TwigStreaming\Profiler\TemplateStreamProfilerInterface;

/**
 * Collects unified streaming timeline for Symfony Profiler.
 *
 * Implements LateDataCollectorInterface because StreamedResponse callbacks
 * execute AFTER kernel.response (when collect() runs). The actual profiler
 * data is only available at kernel.terminate when lateCollect() runs.
 *
 * @mago-expect analysis:mixed-return-statement
 * @mago-expect analysis:possibly-invalid-argument
 * @mago-expect analysis:possibly-invalid-operand
 *
 * Symfony AbstractDataCollector $this->data is typed as mixed.
 * Timeline array structure varies by event type (duration, statusCode optional).
 */
final class StreamingDataCollector extends AbstractDataCollector implements LateDataCollectorInterface
{
    public function __construct(
        private readonly TemplateStreamProfilerInterface $templateProfiler,
        private readonly ViewModelProfilerInterface $viewModelProfiler,
        private readonly ?HttpClientProfilerInterface $httpProfiler = null,
    ) {}

    #[\Override]
    public static function getTemplate(): ?string
    {
        return '@ToppySymfonyAsyncTwig/data_collector/streaming.html.twig';
    }

    #[\Override]
    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
        // Intentionally empty - data collected in lateCollect() for StreamedResponse support
    }

    #[\Override]
    public function lateCollect(): void
    {
        $templateEvents = $this->convertTemplateEvents($this->templateProfiler->getEvents());
        $viewModelEvents = $this->convertViewModelEntries($this->viewModelProfiler->getEntries());
        $httpEvents = $this->httpProfiler !== null ? $this->convertHttpEntries($this->httpProfiler->getEntries()) : [];

        $timeline = array_merge($templateEvents, $viewModelEvents, $httpEvents);
        usort($timeline, static fn($a, $b) => $a['timestamp'] <=> $b['timestamp']);

        $this->data = [
            'timeline' => $timeline,
            'markers' => $this->calculateMarkers($timeline),
            'stats' => $this->calculateStats($timeline),
        ];
    }

    #[\Override]
    public function getName(): string
    {
        return 'toppy.streaming';
    }

    /**
     * @return array<array{type: string, name: string, shortName: string, timestamp: float, parent: ?string, category: string}>
     */
    public function getTimeline(): array
    {
        return $this->data['timeline'] ?? [];
    }

    /**
     * @return array<string, array{timestamp: float, label: string}>
     */
    public function getMarkers(): array
    {
        return $this->data['markers'] ?? [];
    }

    /**
     * @return array{template_count: int, block_count: int, viewmodel_count: int, http_request_count: int, total_time: float, parallel_efficiency: float}
     */
    public function getStats(): array
    {
        return (
            $this->data['stats'] ?? [
                'template_count' => 0,
                'block_count' => 0,
                'viewmodel_count' => 0,
                'http_request_count' => 0,
                'total_time' => 0.0,
                'parallel_efficiency' => 1.0,
            ]
        );
    }

    /**
     * @param array<StreamingTimelineEvent> $events
     * @return array<array{type: string, name: string, shortName: string, timestamp: float, parent: ?string, category: string}>
     */
    private function convertTemplateEvents(array $events): array
    {
        return array_map(static fn(StreamingTimelineEvent $e) => [
            'type' => $e->type,
            'name' => $e->name,
            'shortName' => $e->getShortName(),
            'timestamp' => $e->timestamp,
            'parent' => $e->parent,
            'category' => str_starts_with($e->type, 'template') ? 'template' : 'block',
        ], $events);
    }

    /**
     * @param array<TimelineEntry> $entries
     * @return array<array{type: string, name: string, shortName: string, timestamp: float, parent: ?string, category: string, status: string, duration?: float}>
     */
    private function convertViewModelEntries(array $entries): array
    {
        $events = [];
        foreach ($entries as $entry) {
            $events[] = [
                'type' => 'viewmodel_start',
                'name' => $entry->viewModelClass,
                'shortName' => $entry->getShortName(),
                'timestamp' => $entry->startTime,
                'parent' => null,
                'category' => 'viewmodel',
                'status' => $entry->status,
            ];
            $events[] = [
                'type' => 'viewmodel_end',
                'name' => $entry->viewModelClass,
                'shortName' => $entry->getShortName(),
                'timestamp' => $entry->endTime,
                'parent' => null,
                'category' => 'viewmodel',
                'status' => $entry->status,
                'duration' => $entry->getDuration(),
            ];
        }
        return $events;
    }

    /**
     * @param array<HttpRequestEntry> $entries
     * @return array<array{type: string, name: string, shortName: string, timestamp: float, parent: ?string, category: string, status: string, duration?: float, statusCode?: int}>
     */
    private function convertHttpEntries(array $entries): array
    {
        $events = [];
        foreach ($entries as $entry) {
            $events[] = [
                'type' => 'http_start',
                'name' => $entry->method . ' ' . $entry->url,
                'shortName' => $entry->method . ' ' . $entry->getShortUrl(),
                'timestamp' => $entry->startTime,
                'parent' => null,
                'category' => 'http',
                'status' => $entry->status,
            ];
            $events[] = [
                'type' => 'http_end',
                'name' => $entry->method . ' ' . $entry->url,
                'shortName' => $entry->method . ' ' . $entry->getShortUrl(),
                'timestamp' => $entry->endTime,
                'parent' => null,
                'category' => 'http',
                'status' => $entry->status,
                'duration' => $entry->getDuration(),
                'statusCode' => $entry->statusCode,
            ];
        }
        return $events;
    }

    /**
     * @param array<array{type: string, timestamp: float}> $timeline
     * @return array<string, array{timestamp: float, label: string}>
     */
    private function calculateMarkers(array $timeline): array
    {
        $markers = [];

        $templateStarts = array_filter($timeline, static fn($e) => $e['type'] === 'template_start');
        $templateEnds = array_filter($timeline, static fn($e) => $e['type'] === 'template_end');
        $viewModelEnds = array_filter($timeline, static fn($e) => $e['type'] === 'viewmodel_end');

        if ($templateStarts !== []) {
            /** @var list<float> $timestamps */
            $timestamps = array_column($templateStarts, 'timestamp');
            $first = min($timestamps);
            $markers['first_template'] = ['timestamp' => (float) $first, 'label' => 'First Template'];
        }

        if ($viewModelEnds !== []) {
            /** @var list<float> $timestamps */
            $timestamps = array_column($viewModelEnds, 'timestamp');
            $last = max($timestamps);
            $markers['all_data_ready'] = ['timestamp' => (float) $last, 'label' => 'All Data Ready'];
        }

        if ($templateEnds !== []) {
            /** @var list<float> $timestamps */
            $timestamps = array_column($templateEnds, 'timestamp');
            $last = max($timestamps);
            $markers['response_complete'] = ['timestamp' => (float) $last, 'label' => 'Response Complete'];
        }

        return $markers;
    }

    /**
     * @param array<array{type: string, timestamp: float}> $timeline
     * @return array{template_count: int, block_count: int, viewmodel_count: int, http_request_count: int, total_time: float, parallel_efficiency: float}
     */
    private function calculateStats(array $timeline): array
    {
        $templateCount = count(array_filter($timeline, static fn($e) => $e['type'] === 'template_start'));
        $blockCount = count(array_filter($timeline, static fn($e) => $e['type'] === 'block_start'));
        $viewModelCount = count(array_filter($timeline, static fn($e) => $e['type'] === 'viewmodel_start'));
        $httpRequestCount = count(array_filter($timeline, static fn($e) => $e['type'] === 'http_start'));

        $timestamps = array_column($timeline, 'timestamp');
        $totalTime = $timestamps !== [] ? max($timestamps) - min($timestamps) : 0.0;

        return [
            'template_count' => $templateCount,
            'block_count' => $blockCount,
            'viewmodel_count' => $viewModelCount,
            'http_request_count' => $httpRequestCount,
            'total_time' => $totalTime,
            'parallel_efficiency' => $this->viewModelProfiler->getParallelEfficiency(),
        ];
    }
}
