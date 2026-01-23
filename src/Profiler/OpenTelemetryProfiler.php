<?php

declare(strict_types=1);

namespace Toppy\SymfonyAsyncTwigBundle\Profiler;

use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use Toppy\AsyncViewModel\Context\RequestContext;
use Toppy\AsyncViewModel\Context\ViewContext;
use Toppy\AsyncViewModel\Profiler\ViewModelProfilerInterface;

/**
 * OpenTelemetry-aware profiler decorator.
 *
 * Wraps the inner profiler and adds OTel spans for each ViewModel resolution.
 * Auto-registered when TracerInterface is available in the container.
 */
final class OpenTelemetryProfiler implements ViewModelProfilerInterface
{
    /** @var array<string, SpanInterface> */
    private array $spans = [];

    public function __construct(
        private readonly ViewModelProfilerInterface $inner,
        private readonly TracerInterface $tracer,
    ) {}

    public function start(
        string $viewModelClass,
        ViewContext $viewContext,
        RequestContext $requestContext,
        array $dependencies = [],
    ): void {
        $this->inner->start($viewModelClass, $viewContext, $requestContext, $dependencies);

        $shortName = $this->getShortName($viewModelClass);

        $span = $this->tracer->spanBuilder("viewmodel.resolve.{$shortName}")
            ->setSpanKind(SpanKind::KIND_INTERNAL)
            ->setAttribute('viewmodel.class', $viewModelClass)
            ->setAttribute('viewmodel.short_name', $shortName)
            ->setAttribute('viewmodel.dependencies_count', count($dependencies))
            ->startSpan();

        $this->spans[$viewModelClass] = $span;
    }

    public function finish(string $viewModelClass, mixed $result): void
    {
        $this->inner->finish($viewModelClass, $result);

        if (isset($this->spans[$viewModelClass])) {
            $this->spans[$viewModelClass]->setStatus(StatusCode::STATUS_OK);
            $this->spans[$viewModelClass]->end();
            unset($this->spans[$viewModelClass]);
        }
    }

    public function fail(string $viewModelClass, \Throwable $exception): void
    {
        $this->inner->fail($viewModelClass, $exception);

        if (isset($this->spans[$viewModelClass])) {
            $this->spans[$viewModelClass]->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
            $this->spans[$viewModelClass]->recordException($exception);
            $this->spans[$viewModelClass]->end();
            unset($this->spans[$viewModelClass]);
        }
    }

    public function recordCacheHit(string $viewModelClass, string $cacheStatus, float $startTime, float $endTime): void
    {
        $this->inner->recordCacheHit($viewModelClass, $cacheStatus, $startTime, $endTime);

        $shortName = $this->getShortName($viewModelClass);

        // Create a span for cache hits with actual duration
        $span = $this->tracer->spanBuilder("viewmodel.cache.{$shortName}")
            ->setSpanKind(SpanKind::KIND_INTERNAL)
            ->setAttribute('viewmodel.class', $viewModelClass)
            ->setAttribute('viewmodel.short_name', $shortName)
            ->setAttribute('viewmodel.cache_status', $cacheStatus)
            ->setAttribute('viewmodel.duration_ms', $endTime - $startTime)
            ->startSpan();

        $span->setStatus(StatusCode::STATUS_OK);
        $span->end();
    }

    public function getEntries(): array
    {
        return $this->inner->getEntries();
    }

    public function getParallelEfficiency(): float
    {
        return $this->inner->getParallelEfficiency();
    }

    public function getTotalTime(): float
    {
        return $this->inner->getTotalTime();
    }

    private function getShortName(string $class): string
    {
        $parts = explode('\\', $class);
        return end($parts);
    }
}
