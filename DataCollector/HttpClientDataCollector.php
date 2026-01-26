<?php

declare(strict_types=1);

namespace Toppy\SymfonyAsyncTwigBundle\DataCollector;

use Symfony\Bundle\FrameworkBundle\DataCollector\AbstractDataCollector;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\LateDataCollectorInterface;
use Toppy\AsyncViewModel\Profiler\HttpClientProfilerInterface;
use Toppy\AsyncViewModel\Profiler\HttpRequestEntry;

/**
 * Collects AmPHP HTTP client request data for Symfony Profiler.
 *
 * Implements LateDataCollectorInterface because HTTP requests made
 * during StreamedResponse callbacks execute AFTER kernel.response.
 *
 * @mago-expect analysis:mixed-return-statement
 *
 * Symfony AbstractDataCollector $this->data is typed as mixed.
 * Getter methods return values from $this->data with null coalescing defaults.
 */
final class HttpClientDataCollector extends AbstractDataCollector implements LateDataCollectorInterface
{
    public function __construct(
        private readonly HttpClientProfilerInterface $profiler,
    ) {}

    #[\Override]
    public static function getTemplate(): ?string
    {
        return '@ToppySymfonyAsyncTwig/data_collector/http_client.html.twig';
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
            'entries' => array_map(static fn(HttpRequestEntry $e) => [
                'method' => $e->method,
                'url' => $e->url,
                'shortUrl' => $e->getShortUrl(),
                'host' => $e->getHost(),
                'path' => $e->getPath(),
                'start' => $e->startTime,
                'end' => $e->endTime,
                'duration' => $e->getDuration(),
                'statusCode' => $e->statusCode,
                'requestHeaders' => $e->requestHeaders,
                'responseHeaders' => $e->responseHeaders,
                'responseSize' => $e->responseSize,
                'status' => $e->status,
                'error' => $e->errorMessage,
            ], $entries),
            'totalTime' => $this->profiler->getTotalTime(),
            'count' => $this->profiler->getCount(),
            'errorCount' => $this->profiler->getErrorCount(),
        ];
    }

    #[\Override]
    public function getName(): string
    {
        return 'toppy.http_client';
    }

    /**
     * @return array<array{method: string, url: string, shortUrl: string, host: string, path: string, start: float, end: float, duration: float, statusCode: int, requestHeaders: array, responseHeaders: array, responseSize: int, status: string, error: ?string}>
     */
    public function getEntries(): array
    {
        return $this->data['entries'] ?? [];
    }

    public function getTotalTime(): float
    {
        return $this->data['totalTime'] ?? 0.0;
    }

    public function getCount(): int
    {
        return $this->data['count'] ?? 0;
    }

    public function getErrorCount(): int
    {
        return $this->data['errorCount'] ?? 0;
    }
}
