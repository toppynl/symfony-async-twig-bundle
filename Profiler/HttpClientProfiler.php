<?php

declare(strict_types=1);

namespace Toppy\SymfonyAsyncTwigBundle\Profiler;

use Symfony\Contracts\Service\ResetInterface;
use Toppy\AsyncViewModel\Profiler\HttpClientProfilerInterface;
use Toppy\AsyncViewModel\Profiler\HttpRequestEntry;
use Toppy\AsyncViewModel\Profiler\TimeEpoch;

/**
 * Default profiler that collects HTTP request timing data in memory.
 *
 * @mago-expect analysis:possibly-invalid-operand
 *
 * max() on mapped timestamps returns int|float.
 * Mago cannot verify operand types for subtraction in getTotalTime().
 */
final class HttpClientProfiler implements HttpClientProfilerInterface, ResetInterface
{
    /** @var array<string, array{start: float, method: string, url: string, headers: array<string, string>}> */
    private array $pending = [];

    /** @var array<HttpRequestEntry> */
    private array $entries = [];

    public function __construct(
        private readonly TimeEpoch $epoch,
    ) {}

    #[\Override]
    public function start(string $requestId, string $method, string $url, array $headers = []): void
    {
        $this->pending[$requestId] = [
            'start' => $this->epoch->getElapsed(),
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
        ];
    }

    #[\Override]
    public function finish(string $requestId, int $statusCode, array $responseHeaders, int $bodySize): void
    {
        if (!isset($this->pending[$requestId])) {
            return;
        }

        $pending = $this->pending[$requestId];

        $this->entries[] = new HttpRequestEntry(
            requestId: $requestId,
            method: $pending['method'],
            url: $pending['url'],
            startTime: $pending['start'],
            endTime: $this->epoch->getElapsed(),
            statusCode: $statusCode,
            requestHeaders: $pending['headers'],
            responseHeaders: $responseHeaders,
            responseSize: $bodySize,
            status: $statusCode >= 400 ? 'error' : 'success',
            errorMessage: null,
        );

        unset($this->pending[$requestId]);
    }

    #[\Override]
    public function fail(string $requestId, \Throwable $exception): void
    {
        if (!isset($this->pending[$requestId])) {
            return;
        }

        $pending = $this->pending[$requestId];

        $this->entries[] = new HttpRequestEntry(
            requestId: $requestId,
            method: $pending['method'],
            url: $pending['url'],
            startTime: $pending['start'],
            endTime: $this->epoch->getElapsed(),
            statusCode: 0,
            requestHeaders: $pending['headers'],
            responseHeaders: [],
            responseSize: 0,
            status: 'error',
            errorMessage: $exception->getMessage(),
        );

        unset($this->pending[$requestId]);
    }

    #[\Override]
    public function getEntries(): array
    {
        return $this->entries;
    }

    #[\Override]
    public function getTotalTime(): float
    {
        if ($this->entries === []) {
            return 0.0;
        }

        $starts = array_map(static fn(HttpRequestEntry $e) => $e->startTime, $this->entries);
        $ends = array_map(static fn(HttpRequestEntry $e) => $e->endTime, $this->entries);

        return max($ends) - min($starts);
    }

    #[\Override]
    public function getCount(): int
    {
        return count($this->entries);
    }

    #[\Override]
    public function getErrorCount(): int
    {
        return count(array_filter($this->entries, static fn(HttpRequestEntry $e) => $e->status === 'error'));
    }

    #[\Override]
    public function reset(): void
    {
        $this->pending = [];
        $this->entries = [];
    }
}
