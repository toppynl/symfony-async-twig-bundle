<?php

declare(strict_types=1);

namespace Toppy\SymfonyAsyncTwigBundle;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Toppy\SymfonyAsyncTwigBundle\DependencyInjection\Compiler\DisableWebLinkListenerPass;
use Toppy\SymfonyAsyncTwigBundle\DependencyInjection\Compiler\OpenTelemetryCompilerPass;
use Toppy\SymfonyAsyncTwigBundle\DependencyInjection\Compiler\ReplaceTwigDataCollectorPass;
use Toppy\SymfonyAsyncTwigBundle\DependencyInjection\Compiler\TwigYieldModeCompilerPass;
use Toppy\SymfonyAsyncTwigBundle\DependencyInjection\Compiler\ViewModelDependencyValidationPass;

/**
 * Symfony integration bundle for the async Twig rendering stack.
 *
 * This bundle bridges the framework-agnostic packages with Symfony:
 * - Registers Symfony-specific service implementations (Context, Profilers)
 * - Configures data collectors for the profiler
 * - Sets up compiler passes for Twig and OpenTelemetry integration
 */
final class ToppySymfonyAsyncTwigBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new TwigYieldModeCompilerPass());
        $container->addCompilerPass(new ViewModelDependencyValidationPass());
        $container->addCompilerPass(new OpenTelemetryCompilerPass());
        $container->addCompilerPass(new DisableWebLinkListenerPass());
        $container->addCompilerPass(new ReplaceTwigDataCollectorPass());
    }

    public function getPath(): string
    {
        // Return src/ directory where Resources/views lives
        return __DIR__;
    }
}
