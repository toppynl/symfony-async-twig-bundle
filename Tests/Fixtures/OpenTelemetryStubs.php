<?php

/**
 * Minimal OpenTelemetry API stubs for testing without the optional dependency.
 *
 * Only declares the surface OpenTelemetryProfiler touches. Guarded so the real
 * package wins when installed.
 */

declare(strict_types=1);

namespace OpenTelemetry\API\Trace;

if (!interface_exists(SpanInterface::class)) {
    interface SpanInterface
    {
        public function setStatus(string $code, ?string $description = null): self;

        public function recordException(\Throwable $exception): self;

        public function end(): void;
    }

    interface SpanBuilderInterface
    {
        public function setSpanKind(int $spanKind): self;

        public function setAttribute(string $key, mixed $value): self;

        public function startSpan(): SpanInterface;
    }

    interface TracerInterface
    {
        public function spanBuilder(string $spanName): SpanBuilderInterface;
    }

    final class SpanKind
    {
        public const int KIND_INTERNAL = 1;
    }

    final class StatusCode
    {
        public const string STATUS_OK = 'Ok';
        public const string STATUS_ERROR = 'Error';
    }
}
