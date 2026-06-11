<?php

declare(strict_types=1);

namespace Toppy\SymfonyAsyncTwigBundle\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Toppy\AsyncViewModel\Cache\CachingViewModelDecorator;
use Toppy\SymfonyAsyncTwigBundle\DependencyInjection\ToppySymfonyAsyncTwigExtension;

final class ToppySymfonyAsyncTwigExtensionTest extends TestCase
{
    public function testCachingDecoratorIsTaggedForKernelReset(): void
    {
        $container = new ContainerBuilder();

        $extension = new ToppySymfonyAsyncTwigExtension();
        $extension->load([['cache' => ['enabled' => true]]], $container);

        $definition = $container->getDefinition(CachingViewModelDecorator::class);

        // The decorator holds request-scoped in-flight futures; without a
        // kernel.reset tag they leak across requests in worker mode.
        static::assertTrue(
            $definition->hasTag('kernel.reset'),
            'CachingViewModelDecorator must be tagged kernel.reset',
        );
        static::assertSame([['method' => 'reset']], $definition->getTag('kernel.reset'));
    }
}
