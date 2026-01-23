<?php

declare(strict_types=1);

namespace Toppy\SymfonyAsyncTwigBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Bundle configuration for cache and invalidation settings.
 */
final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('toppy_symfony_async_twig');

        $treeBuilder->getRootNode()
            ->children()
                ->arrayNode('cache')
                    ->canBeEnabled()
                    ->children()
                        ->scalarNode('pool')
                            ->defaultValue('cache.app')
                            ->info('Cache pool service ID (must implement TagAwareCacheInterface)')
                        ->end()
                        ->arrayNode('lock')
                            ->canBeEnabled()
                            ->children()
                                ->scalarNode('factory')
                                    ->defaultValue('lock.factory')
                                    ->info('Lock factory service ID')
                                ->end()
                                ->floatNode('ttl')
                                    ->defaultValue(30.0)
                                    ->info('Lock TTL in seconds (should be longer than typical revalidation time)')
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('invalidation')
                    ->canBeEnabled()
                    ->children()
                        ->scalarNode('secret')
                            ->isRequired()
                            ->cannotBeEmpty()
                            ->info('Secret for invalidation endpoint authentication')
                        ->end()
                        ->scalarNode('route_prefix')
                            ->defaultValue('/_cache')
                            ->info('Route prefix for invalidation endpoint')
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
