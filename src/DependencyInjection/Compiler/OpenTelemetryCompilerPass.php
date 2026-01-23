<?php

declare(strict_types=1);

namespace Toppy\SymfonyAsyncTwigBundle\DependencyInjection\Compiler;

use OpenTelemetry\API\Trace\TracerInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Toppy\AsyncViewModel\Profiler\ViewModelProfilerInterface;
use Toppy\SymfonyAsyncTwigBundle\Profiler\OpenTelemetryProfiler;

/**
 * Auto-registers OpenTelemetryProfiler when TracerInterface is available.
 */
final class OpenTelemetryCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        // Check if OTel TracerInterface exists and is registered
        if (!interface_exists(TracerInterface::class)) {
            return;
        }

        if (!$container->has(TracerInterface::class)) {
            return;
        }

        // Register OpenTelemetryProfiler as a decorator
        $definition = new Definition(OpenTelemetryProfiler::class);
        $definition->setDecoratedService(ViewModelProfilerInterface::class);
        $definition->setArgument('$inner', new Reference(OpenTelemetryProfiler::class . '.inner'));
        $definition->setArgument('$tracer', new Reference(TracerInterface::class));

        $container->setDefinition(OpenTelemetryProfiler::class, $definition);
    }
}
