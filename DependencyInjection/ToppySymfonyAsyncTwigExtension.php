<?php

declare(strict_types=1);

namespace Toppy\SymfonyAsyncTwigBundle\DependencyInjection;

use Psr\Log\LoggerInterface;
use Symfony\Component\Asset\Packages;
use Symfony\Component\DependencyInjection\Argument\ServiceLocatorArgument;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpFoundation\RequestStack;
use Toppy\AsyncViewModel\AsyncViewModel;
use Toppy\AsyncViewModel\Cache\CachingViewModelDecorator;
use Toppy\AsyncViewModel\Cache\RevalidationLockInterface;
use Toppy\AsyncViewModel\Cache\SwrCacheInterface;
use Toppy\AsyncViewModel\Context\ContextFactoryInterface;
use Toppy\AsyncViewModel\Context\ContextResolverInterface;
use Toppy\AsyncViewModel\Profiler\HttpClientProfilerInterface;
use Toppy\AsyncViewModel\Profiler\NullViewModelProfiler;
use Toppy\AsyncViewModel\Profiler\TimeEpoch;
use Toppy\AsyncViewModel\Profiler\ViewModelProfilerInterface;
use Toppy\AsyncViewModel\ViewModelManager;
use Toppy\AsyncViewModel\ViewModelManagerInterface;
use Toppy\SymfonyAsyncTwigBundle\Cache\SymfonyRevalidationLock;
use Toppy\SymfonyAsyncTwigBundle\Cache\SymfonySwrCache;
use Toppy\SymfonyAsyncTwigBundle\Context\ContextFactory;
use Toppy\SymfonyAsyncTwigBundle\Context\ContextResolver;
use Toppy\SymfonyAsyncTwigBundle\Controller\InvalidationController;
use Toppy\SymfonyAsyncTwigBundle\DataCollector\HttpClientDataCollector;
use Toppy\SymfonyAsyncTwigBundle\DataCollector\StreamingDataCollector;
use Toppy\SymfonyAsyncTwigBundle\DataCollector\ViewModelDataCollector;
use Toppy\SymfonyAsyncTwigBundle\EventListener\StreamedResponseWebDebugToolbarListener;
use Toppy\SymfonyAsyncTwigBundle\Profiler\HttpClientProfiler;
use Toppy\SymfonyAsyncTwigBundle\Profiler\TemplateStreamProfiler;
use Toppy\SymfonyAsyncTwigBundle\Profiler\ViewModelProfiler;
use Toppy\TwigPrerender\PrerenderExtension;
use Toppy\TwigPrerender\Service\ContextEncryptor;
use Toppy\TwigStreaming\EarlyHints\EarlyHintsProviderInterface;
use Toppy\TwigStreaming\Profiler\NullTemplateStreamProfiler;
use Toppy\TwigStreaming\Profiler\TemplateStreamProfilerInterface;
use Toppy\TwigStreaming\Slot\SlotRegistry;
use Toppy\TwigStreaming\Slot\SlotRegistryInterface;
use Toppy\TwigStreaming\Slot\SlotRenderer;
use Toppy\TwigStreaming\Twig\EarlyHintsExtension;
use Toppy\TwigStreaming\Twig\PreloadingTemplateRenderer;
use Toppy\TwigStreaming\Twig\StreamingProfilerExtension;
use Toppy\TwigStreaming\Twig\StreamingProfilerRuntime;
use Toppy\TwigStreaming\Twig\StreamingTemplateRenderer;
use Toppy\TwigStreaming\Twig\StreamingTemplateRendererInterface;
use Toppy\TwigViewModel\Twig\Runtime\ViewModelRuntime;
use Toppy\TwigViewModel\Twig\ViewExtension;

/**
 * Registers all Toppy async Twig services.
 *
 * Consolidates service definitions from:
 * - async-view-model: ViewModelManager, profiler interfaces
 * - twig-view-model: ViewExtension, ViewModelRuntime
 * - twig-streaming: SlotRegistry, StreamingTemplateRenderer, EarlyHints
 * - twig-prerender: PrerenderExtension, ContextEncryptor
 *
 * Plus Symfony-specific services: Context factories, Data collectors, Profilers.
 *
 * @mago-expect analysis:mixed-operand
 *
 * Container parameter kernel.debug returns mixed, cast to bool for if check.
 */
final class ToppySymfonyAsyncTwigExtension extends Extension
{
    /**
     * @throws \LogicException When a feature is enabled but its package is not installed
     */
    #[\Override]
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        /** @var array{
         *     view_model: array{enabled: bool},
         *     twig_view: array{enabled: bool},
         *     streaming: array{enabled: string|bool},
         *     prerender: array{enabled: string|bool},
         *     profiler: array{enabled: bool},
         *     cache: array{enabled: bool, pool: string, lock: array{enabled: bool, factory: string, ttl: float}},
         *     invalidation: array{enabled: bool, secret: string, route_prefix: string}
         * } $config
         */
        $config = $this->processConfiguration($configuration, $configs);

        // Detect package availability
        $streamingAvailable = $this->isStreamingAvailable();
        $prerenderAvailable = $this->isPrerenderAvailable();

        // Resolve 'auto' config to actual booleans
        $streamingEnabled = $this->resolveFeatureEnabled($config['streaming']['enabled'], $streamingAvailable);
        $prerenderEnabled = $this->resolveFeatureEnabled($config['prerender']['enabled'], $prerenderAvailable);

        // Validate: cannot enable if package not installed
        if ($streamingEnabled && !$streamingAvailable) {
            throw new \LogicException(
                'Cannot enable streaming features: toppy/twig-streaming is not installed. '
                . 'Run: composer require toppy/twig-streaming',
            );
        }
        if ($prerenderEnabled && !$prerenderAvailable) {
            throw new \LogicException(
                'Cannot enable prerender features: toppy/twig-prerender is not installed. '
                . 'Run: composer require toppy/twig-prerender',
            );
        }

        // Store for compiler passes
        $container->setParameter('toppy.streaming.enabled', $streamingEnabled);
        $container->setParameter('toppy.prerender.enabled', $prerenderEnabled);

        // TimeEpoch - shared timing reference for profilers (always needed)
        $container->register(TimeEpoch::class)->setPublic(false)->addTag('kernel.reset', ['method' => 'reset']);

        // === FEATURE: view_model ===
        if ($config['view_model']['enabled']) {
            $this->registerViewModelServices($container);
        }

        // === FEATURE: twig_view ===
        if ($config['twig_view']['enabled']) {
            $this->registerTwigViewServices($container);
        }

        // === FEATURE: streaming ===
        if ($streamingEnabled) {
            $this->registerStreamingServices($container);
        }

        // === FEATURE: prerender ===
        if ($prerenderEnabled) {
            $this->registerPrerenderServices($container, $streamingEnabled);
        }

        // === FEATURE: profiler ===
        if ($config['profiler']['enabled']) {
            $this->registerProfilerServices($container, $streamingEnabled);
        } else {
            // Register null profilers when profiler is disabled
            $this->registerNullProfilers($container, $streamingEnabled);
        }

        // Cache layer (optional)
        if ($config['cache']['enabled']) {
            $this->registerCacheServices($container, $config['cache']);
        }

        // Invalidation endpoint (optional)
        if ($config['invalidation']['enabled']) {
            $this->registerInvalidationServices($container, $config['invalidation']);
        }
    }

    /**
     * Core async view model services (from async-view-model bundle).
     *
     * @throws \Symfony\Component\DependencyInjection\Exception\InvalidArgumentException
     */
    private function registerViewModelServices(ContainerBuilder $container): void
    {
        // Auto-configure AsyncViewModel implementations with a tag
        $container->registerForAutoconfiguration(AsyncViewModel::class)->addTag('toppy.async_view_model');

        // Register ViewModelManager with a service locator for tagged view models
        $container
            ->register(ViewModelManager::class)
            ->setArgument(
                '$viewModels',
                new ServiceLocatorArgument(new TaggedIteratorArgument('toppy.async_view_model', null, null, true)),
            )
            ->setArgument('$profiler', new Reference(ViewModelProfilerInterface::class))
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->addTag('kernel.reset', ['method' => 'reset']);

        $container->setAlias(ViewModelManagerInterface::class, ViewModelManager::class);

        // Context implementations (Symfony-specific)
        $container->register(ContextFactory::class)->setAutowired(true)->setAutoconfigured(true);
        $container->setAlias(ContextFactoryInterface::class, ContextFactory::class);

        $container
            ->register(ContextResolver::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->addTag('kernel.reset', ['method' => 'reset']);
        $container->setAlias(ContextResolverInterface::class, ContextResolver::class);
    }

    /**
     * Twig view() and pre_load_view() functions (from twig-view-model bundle).
     *
     * @throws \Symfony\Component\DependencyInjection\Exception\BadMethodCallException
     */
    private function registerTwigViewServices(ContainerBuilder $container): void
    {
        // ViewExtension with twig.extension tag
        $container
            ->setDefinition(ViewExtension::class, new Definition(ViewExtension::class))
            ->setAutowired(true)
            ->setArgument('$loader', new Reference('twig.loader.native_filesystem'))
            ->addTag('twig.extension');

        // ViewModelRuntime with twig.runtime tag
        $container
            ->setDefinition(ViewModelRuntime::class, new Definition(ViewModelRuntime::class))
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->addTag('twig.runtime');
    }

    /**
     * Streaming template renderer + deferred slots (from twig-streaming bundle).
     *
     * @throws \Symfony\Component\DependencyInjection\Exception\BadMethodCallException
     * @throws \Symfony\Component\DependencyInjection\Exception\InvalidArgumentException
     */
    private function registerStreamingServices(ContainerBuilder $container): void
    {
        // Slot services
        $container->setDefinition(SlotRegistry::class, new Definition(SlotRegistry::class))->setAutoconfigured(true);
        $container->setAlias(SlotRegistryInterface::class, SlotRegistry::class);

        $container->setDefinition(SlotRenderer::class, new Definition(SlotRenderer::class));

        // EarlyHintsExtension with twig.extension tag
        $container
            ->setDefinition(EarlyHintsExtension::class, new Definition(EarlyHintsExtension::class))
            ->addTag('twig.extension');

        // PreloadingTemplateRenderer
        $container
            ->setDefinition(PreloadingTemplateRenderer::class, new Definition(PreloadingTemplateRenderer::class))
            ->setAutowired(true)
            ->setAutoconfigured(true);

        // StreamingTemplateRenderer with slot dependencies
        $container
            ->setDefinition(StreamingTemplateRenderer::class, new Definition(StreamingTemplateRenderer::class))
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setArgument('$viewModelManager', new Reference(ViewModelManagerInterface::class))
            ->setArgument(
                '$slotRegistry',
                new Reference(SlotRegistryInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE),
            )
            ->setArgument(
                '$slotRenderer',
                new Reference(SlotRenderer::class, ContainerInterface::NULL_ON_INVALID_REFERENCE),
            )
            ->setArgument(
                '$assetPackages',
                new Reference(Packages::class, ContainerInterface::NULL_ON_INVALID_REFERENCE),
            )
            ->setArgument('$earlyHintsProviders', new TaggedIteratorArgument('toppy.early_hints_provider'))
            ->setArgument('$requestStack', new Reference(RequestStack::class));
        $container->setAlias(StreamingTemplateRendererInterface::class, StreamingTemplateRenderer::class);

        // Auto-configure EarlyHintsProvider implementations
        $container
            ->registerForAutoconfiguration(EarlyHintsProviderInterface::class)
            ->addTag('toppy.early_hints_provider');
    }

    /**
     * Prerender extension for {% include ... prerender(false) %} (from twig-prerender bundle).
     *
     * @throws \Symfony\Component\DependencyInjection\Exception\BadMethodCallException
     */
    private function registerPrerenderServices(ContainerBuilder $container, bool $streamingEnabled): void
    {
        // ContextEncryptor with kernel.secret - always needed for prerender(false)
        $container
            ->setDefinition(ContextEncryptor::class, new Definition(ContextEncryptor::class))
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setArgument('$secretKey', '%kernel.secret%');

        // PrerenderExtension with twig.extension tag and conditional slot dependencies
        $definition = new Definition(PrerenderExtension::class);
        $definition->setAutowired(true)->setAutoconfigured(true)->addTag('twig.extension');

        if ($streamingEnabled) {
            $definition->setArgument(
                '$slotRegistry',
                new Reference(SlotRegistryInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE),
            )->setArgument(
                '$slotRenderer',
                new Reference(SlotRenderer::class, ContainerInterface::NULL_ON_INVALID_REFERENCE),
            );
        } else {
            $definition->setArgument('$slotRegistry', null)->setArgument('$slotRenderer', null);
        }

        $container->setDefinition(PrerenderExtension::class, $definition);
    }

    /**
     * Symfony Web Profiler integration (data collectors + real profilers).
     *
     * @throws \Symfony\Component\DependencyInjection\Exception\InvalidArgumentException
     * @throws \Symfony\Component\DependencyInjection\Exception\BadMethodCallException
     * @throws \Symfony\Component\DependencyInjection\Exception\ParameterNotFoundException
     */
    private function registerProfilerServices(ContainerBuilder $container, bool $streamingEnabled): void
    {
        // ViewModelProfiler - always available (from async-view-model)
        $container
            ->register(ViewModelProfiler::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->addTag('kernel.reset', ['method' => 'reset']);
        $container->setAlias(ViewModelProfilerInterface::class, ViewModelProfiler::class);

        // HttpClientProfiler - always available (from async-view-model)
        $container
            ->register(HttpClientProfiler::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->addTag('kernel.reset', ['method' => 'reset']);
        $container->setAlias(HttpClientProfilerInterface::class, HttpClientProfiler::class);

        // ViewModelDataCollector - always available
        $container
            ->register(ViewModelDataCollector::class)
            ->setAutowired(true)
            ->addTag('data_collector', [
                'template' => '@ToppySymfonyAsyncTwig/data_collector/view_model.html.twig',
                'id' => 'toppy.view_model',
            ]);

        // HttpClientDataCollector - always available
        $container
            ->register(HttpClientDataCollector::class)
            ->setAutowired(true)
            ->addTag('data_collector', [
                'template' => '@ToppySymfonyAsyncTwig/data_collector/http_client.html.twig',
                'id' => 'toppy.http_client',
            ]);

        // === STREAMING-DEPENDENT PROFILERS ===
        if ($streamingEnabled) {
            $container
                ->register(TemplateStreamProfiler::class)
                ->setAutowired(true)
                ->setAutoconfigured(true)
                ->addTag('kernel.reset', ['method' => 'reset']);
            $container->setAlias(TemplateStreamProfilerInterface::class, TemplateStreamProfiler::class);

            $container
                ->register(StreamingDataCollector::class)
                ->setAutowired(true)
                ->addTag('data_collector', [
                    'template' => '@ToppySymfonyAsyncTwig/data_collector/streaming.html.twig',
                    'id' => 'toppy.streaming',
                ]);

            $container
                ->register(StreamedResponseWebDebugToolbarListener::class)
                ->setAutowired(true)
                ->addTag('kernel.event_subscriber');

            // StreamingProfilerExtension (debug mode only - for template node instrumentation)
            $isDebug = $container->hasParameter('kernel.debug') ? $container->getParameter('kernel.debug') : true;

            if ($isDebug) {
                $container
                    ->setDefinition(
                        StreamingProfilerExtension::class,
                        new Definition(StreamingProfilerExtension::class),
                    )
                    ->addTag('twig.extension');

                $container
                    ->setDefinition(StreamingProfilerRuntime::class, new Definition(StreamingProfilerRuntime::class))
                    ->setAutowired(true)
                    ->addTag('twig.runtime');
            }
        }
    }

    /**
     * Null profilers when profiler feature is disabled.
     *
     * @throws \Symfony\Component\DependencyInjection\Exception\InvalidArgumentException
     * @throws \Symfony\Component\DependencyInjection\Exception\BadMethodCallException
     */
    private function registerNullProfilers(ContainerBuilder $container, bool $streamingEnabled): void
    {
        // Null ViewModel profiler - always register
        $container->register(NullViewModelProfiler::class);
        $container->setAlias(ViewModelProfilerInterface::class, NullViewModelProfiler::class);

        // Null Template Stream profiler - only if streaming is available
        if ($streamingEnabled) {
            $container->setDefinition(
                NullTemplateStreamProfiler::class,
                new Definition(NullTemplateStreamProfiler::class),
            );
            $container->setAlias(TemplateStreamProfilerInterface::class, NullTemplateStreamProfiler::class);
        }
    }

    /**
     * Cache layer services.
     *
     * @param array{enabled?: bool, pool: string, lock: array{enabled: bool, factory: string, ttl: float}} $cacheConfig
     *
     * @throws \Symfony\Component\DependencyInjection\Exception\InvalidArgumentException
     */
    private function registerCacheServices(ContainerBuilder $container, array $cacheConfig): void
    {
        $container
            ->register(SymfonySwrCache::class)
            ->setArguments([
                new Reference($cacheConfig['pool']),
            ]);

        $container->setAlias(SwrCacheInterface::class, SymfonySwrCache::class);

        // Revalidation lock (optional, prevents thundering herd)
        $lockReference = null;
        if ($cacheConfig['lock']['enabled']) {
            $container
                ->register(SymfonyRevalidationLock::class)
                ->setArguments([
                    new Reference($cacheConfig['lock']['factory']),
                    $cacheConfig['lock']['ttl'],
                ]);
            $container->setAlias(RevalidationLockInterface::class, SymfonyRevalidationLock::class);
            $lockReference = new Reference(RevalidationLockInterface::class);
        }

        // Decorate ViewModelManagerInterface with caching
        $container
            ->register(CachingViewModelDecorator::class)
            ->setDecoratedService(ViewModelManagerInterface::class)
            ->setArguments([
                new Reference('.inner'),
                new ServiceLocatorArgument(new TaggedIteratorArgument('toppy.async_view_model', null, null, true)),
                new Reference(SwrCacheInterface::class),
                new Reference(ContextResolverInterface::class),
                new Reference(ViewModelProfilerInterface::class),
                new Reference(TimeEpoch::class),
                new Reference(LoggerInterface::class),
                $lockReference,
            ]);
    }

    /**
     * Invalidation endpoint services.
     *
     * @param array{enabled?: bool, secret: string, route_prefix: string} $invalidationConfig
     */
    private function registerInvalidationServices(ContainerBuilder $container, array $invalidationConfig): void
    {
        $container
            ->register(InvalidationController::class)
            ->setArguments([
                new Reference(SwrCacheInterface::class),
                $invalidationConfig['secret'],
                new Reference(LoggerInterface::class),
            ])
            ->addTag('controller.service_arguments');
    }

    #[\Override]
    public function getAlias(): string
    {
        return 'toppy_symfony_async_twig';
    }

    /**
     * Check if the twig-streaming package is available.
     */
    private function isStreamingAvailable(): bool
    {
        return interface_exists(SlotRegistryInterface::class);
    }

    /**
     * Check if the twig-prerender package is available.
     */
    private function isPrerenderAvailable(): bool
    {
        return class_exists(PrerenderExtension::class);
    }

    /**
     * Resolve 'auto' config value to actual boolean based on package availability.
     */
    private function resolveFeatureEnabled(mixed $configValue, bool $packageAvailable): bool
    {
        if ($configValue === 'auto') {
            return $packageAvailable;
        }

        return (bool) $configValue;
    }
}
