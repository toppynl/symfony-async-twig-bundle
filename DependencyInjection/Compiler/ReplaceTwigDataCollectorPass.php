<?php

declare(strict_types=1);

namespace Toppy\SymfonyAsyncTwigBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Toppy\SymfonyAsyncTwigBundle\DataCollector\LateTwigDataCollector;

/**
 * Replaces Symfony's TwigDataCollector with a late-collecting wrapper.
 *
 * This is necessary for streaming responses where templates render
 * AFTER kernel.response. The wrapper defers collection to kernel.terminate.
 */
final class ReplaceTwigDataCollectorPass implements CompilerPassInterface
{
    private const TWIG_COLLECTOR_ID = 'data_collector.twig';
    private const TWIG_PROFILE_ID = 'twig.profile';

    public function process(ContainerBuilder $container): void
    {
        // Only apply in debug mode
        if (!$container->hasParameter('kernel.debug') || !$container->getParameter('kernel.debug')) {
            return;
        }

        // Check if Twig data collector exists
        if (!$container->hasDefinition(self::TWIG_COLLECTOR_ID)) {
            return;
        }

        // Check if Twig profile exists
        if (!$container->hasDefinition(self::TWIG_PROFILE_ID)) {
            return;
        }

        // Create wrapper definition using the decorator pattern
        $wrapperDef = new Definition(LateTwigDataCollector::class);
        $wrapperDef->setDecoratedService(self::TWIG_COLLECTOR_ID);
        $wrapperDef->setArgument('$inner', new Reference(LateTwigDataCollector::class . '.inner'));
        $wrapperDef->setArgument('$profile', new Reference(self::TWIG_PROFILE_ID));

        $container->setDefinition(LateTwigDataCollector::class, $wrapperDef);
    }
}
