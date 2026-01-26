<?php

declare(strict_types=1);

namespace Toppy\SymfonyAsyncTwigBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Bundle configuration for feature toggles, cache, and invalidation settings.
 *
 * @mago-expect analysis:non-existent-method
 * @mago-expect analysis:possible-method-access-on-null
 * @mago-expect analysis:mixed-method-access
 *
 * Symfony TreeBuilder uses fluent interface returning mixed.
 * The arrayNode() method exists at runtime but returns NodeParentInterface.
 */
final class Configuration implements ConfigurationInterface
{
    /**
     * @throws \RuntimeException
     */
    #[\Override]
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('toppy_symfony_async_twig');

        $treeBuilder
            ->getRootNode()
            ->children()
            // Feature toggles (all enabled by default)
            ->arrayNode('view_model')
            ->canBeDisabled()
            ->info('Core async view model services (ViewModelManager, profiler)')
            ->end()
            ->arrayNode('twig_view')
            ->canBeDisabled()
            ->info('Twig view() and pre_load_view() functions')
            ->end()
            ->arrayNode('streaming')
            ->addDefaultsIfNotSet()
            ->beforeNormalization()
            ->ifTrue(static fn($v) => $v === null || $v === [])
            ->then(static fn() => ['enabled' => 'auto'])
            ->end()
            ->children()
            ->scalarNode('enabled')
            ->defaultValue('auto')
            ->info('Enable streaming features. "auto" detects package availability, true/false to force.')
            ->end()
            ->end()
            ->end()
            ->arrayNode('prerender')
            ->addDefaultsIfNotSet()
            ->beforeNormalization()
            ->ifTrue(static fn($v) => $v === null || $v === [])
            ->then(static fn() => ['enabled' => 'auto'])
            ->end()
            ->children()
            ->scalarNode('enabled')
            ->defaultValue('auto')
            ->info('Enable prerender features. "auto" detects package availability, true/false to force.')
            ->end()
            ->end()
            ->end()
            ->arrayNode('profiler')
            ->canBeDisabled()
            ->info('Symfony Web Profiler integration (data collectors)')
            ->end()
            // Cache configuration (existing)
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
            // Invalidation endpoint (existing)
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
