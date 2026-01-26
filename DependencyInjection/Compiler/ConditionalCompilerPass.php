<?php

declare(strict_types=1);

namespace Toppy\SymfonyAsyncTwigBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Wraps a compiler pass to only execute when a container parameter is truthy.
 */
final class ConditionalCompilerPass implements CompilerPassInterface
{
    public function __construct(
        private readonly CompilerPassInterface $inner,
        private readonly string $parameterName,
    ) {}

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter($this->parameterName)) {
            return;
        }

        if (!$container->getParameter($this->parameterName)) {
            return;
        }

        $this->inner->process($container);
    }
}
