<?php

declare(strict_types=1);

namespace Toppy\SymfonyAsyncTwigBundle\Tests\DependencyInjection;

use OpenTelemetry\API\Trace\TracerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Toppy\AsyncViewModel\Profiler\NullViewModelProfiler;
use Toppy\AsyncViewModel\Profiler\ViewModelProfilerInterface;
use Toppy\SymfonyAsyncTwigBundle\DependencyInjection\Compiler\OpenTelemetryCompilerPass;
use Toppy\SymfonyAsyncTwigBundle\Profiler\OpenTelemetryProfiler;

require_once __DIR__ . '/../Fixtures/OpenTelemetryStubs.php';

final class OpenTelemetryCompilerPassTest extends TestCase
{
    public function testProfilerDecoratorIsTaggedForKernelReset(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(ViewModelProfilerInterface::class, new Definition(NullViewModelProfiler::class));
        $container->setDefinition(TracerInterface::class, new Definition(\stdClass::class));

        new OpenTelemetryCompilerPass()->process($container);

        $definition = $container->getDefinition(OpenTelemetryProfiler::class);

        // The profiler holds request-scoped spans; without kernel.reset they
        // leak across requests in worker mode.
        static::assertTrue($definition->hasTag('kernel.reset'), 'OpenTelemetryProfiler must be tagged kernel.reset');
        static::assertSame([['method' => 'reset']], $definition->getTag('kernel.reset'));
    }
}
