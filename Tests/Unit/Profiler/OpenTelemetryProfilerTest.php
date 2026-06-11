<?php

declare(strict_types=1);

namespace Toppy\SymfonyAsyncTwigBundle\Tests\Unit\Profiler;

use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\TracerInterface;
use PHPUnit\Framework\TestCase;
use Toppy\AsyncViewModel\Context\RequestContext;
use Toppy\AsyncViewModel\Context\ViewContext;
use Toppy\AsyncViewModel\Profiler\NullViewModelProfiler;
use Toppy\SymfonyAsyncTwigBundle\Profiler\OpenTelemetryProfiler;

require_once __DIR__ . '/../../Fixtures/OpenTelemetryStubs.php';

final class OpenTelemetryProfilerTest extends TestCase
{
    /** @var list<RecordingSpan> */
    private array $createdSpans = [];

    public function testResetEndsAndClearsOpenSpans(): void
    {
        // The profiler holds spans keyed by view model class. In worker mode a
        // span started in request A whose fiber never finishes would leak, and
        // a late finish() from A would end request B's span for the same class.
        // reset() (wired via kernel.reset) must end and drop all open spans.
        $profiler = new OpenTelemetryProfiler(new NullViewModelProfiler(), $this->createTracer());

        $viewContext = ViewContext::create('EUR', 'en', false, false, null);
        $requestContext = RequestContext::create([], 'test');

        $profiler->start('App\\ViewModel\\A', $viewContext, $requestContext);
        $profiler->start('App\\ViewModel\\B', $viewContext, $requestContext);

        static::assertInstanceOf(
            \Symfony\Contracts\Service\ResetInterface::class,
            $profiler,
            'Profiler holds request-scoped spans and must be resettable between requests',
        );

        $profiler->reset();

        static::assertCount(2, $this->createdSpans);
        static::assertTrue($this->createdSpans[0]->ended, 'Open span A must be ended on reset');
        static::assertTrue($this->createdSpans[1]->ended, 'Open span B must be ended on reset');

        // A late fiber from the previous request must not touch new spans.
        $this->createdSpans = [];
        $profiler->finish('App\\ViewModel\\A', new \stdClass());
        static::assertSame([], $this->createdSpans, 'finish() after reset must be a no-op');
    }

    private function createTracer(): TracerInterface
    {
        $test = $this;

        return new class($test) implements TracerInterface {
            public function __construct(
                private readonly OpenTelemetryProfilerTest $test,
            ) {}

            #[\Override]
            public function spanBuilder(string $spanName): SpanBuilderInterface
            {
                $test = $this->test;

                return new class($test) implements SpanBuilderInterface {
                    public function __construct(
                        private readonly OpenTelemetryProfilerTest $test,
                    ) {}

                    #[\Override]
                    public function setSpanKind(int $spanKind): SpanBuilderInterface
                    {
                        return $this;
                    }

                    #[\Override]
                    public function setAttribute(string $key, mixed $value): SpanBuilderInterface
                    {
                        return $this;
                    }

                    #[\Override]
                    public function startSpan(): SpanInterface
                    {
                        return $this->test->recordSpan();
                    }
                };
            }
        };
    }

    public function recordSpan(): SpanInterface
    {
        $span = new RecordingSpan();
        $this->createdSpans[] = $span;

        return $span;
    }
}

/** Span double recording end() calls. */
final class RecordingSpan implements SpanInterface
{
    public bool $ended = false;

    #[\Override]
    public function setStatus(string $code, ?string $description = null): SpanInterface
    {
        return $this;
    }

    #[\Override]
    public function recordException(\Throwable $exception): SpanInterface
    {
        return $this;
    }

    #[\Override]
    public function end(): void
    {
        $this->ended = true;
    }
}
