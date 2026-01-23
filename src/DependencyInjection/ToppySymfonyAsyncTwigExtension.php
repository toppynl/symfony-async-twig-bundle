<?php

declare(strict_types=1);

namespace Toppy\SymfonyAsyncTwigBundle\DependencyInjection;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Argument\ServiceLocatorArgument;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Toppy\AsyncViewModel\Cache\CachingViewModelDecorator;
use Toppy\AsyncViewModel\Cache\SwrCacheInterface;
use Toppy\AsyncViewModel\Context\ContextFactoryInterface;
use Toppy\AsyncViewModel\Context\ContextResolverInterface;
use Toppy\AsyncViewModel\Profiler\HttpClientProfilerInterface;
use Toppy\AsyncViewModel\Profiler\TimeEpoch;
use Toppy\AsyncViewModel\Profiler\ViewModelProfilerInterface;
use Toppy\AsyncViewModel\ViewModelManagerInterface;
use Toppy\AsyncViewModel\Cache\RevalidationLockInterface;
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
use Toppy\TwigStreaming\Profiler\TemplateStreamProfilerInterface;

/**
 * Registers Symfony-specific services for the async Twig stack.
 */
final class ToppySymfonyAsyncTwigExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        // TimeEpoch - shared timing reference for profilers
        $container->register(TimeEpoch::class)
            ->setPublic(false)
            ->addTag('kernel.reset', ['method' => 'reset']);

        // Context implementations
        $container->register(ContextFactory::class)
            ->setAutowired(true)
            ->setAutoconfigured(true);
        $container->setAlias(ContextFactoryInterface::class, ContextFactory::class);

        $container->register(ContextResolver::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->addTag('kernel.reset', ['method' => 'reset']);
        $container->setAlias(ContextResolverInterface::class, ContextResolver::class);

        // Real profiler implementations (overrides null from child bundles)
        $container->register(ViewModelProfiler::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->addTag('kernel.reset', ['method' => 'reset']);
        $container->setAlias(ViewModelProfilerInterface::class, ViewModelProfiler::class);

        $container->register(TemplateStreamProfiler::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->addTag('kernel.reset', ['method' => 'reset']);
        $container->setAlias(TemplateStreamProfilerInterface::class, TemplateStreamProfiler::class);

        $container->register(HttpClientProfiler::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->addTag('kernel.reset', ['method' => 'reset']);
        $container->setAlias(HttpClientProfilerInterface::class, HttpClientProfiler::class);

        // Data collectors for Symfony profiler
        $container->register(ViewModelDataCollector::class)
            ->setAutowired(true)
            ->addTag('data_collector', [
                'template' => '@ToppySymfonyAsyncTwig/data_collector/view_model.html.twig',
                'id' => 'toppy.view_model',
            ]);

        $container->register(StreamingDataCollector::class)
            ->setAutowired(true)
            ->addTag('data_collector', [
                'template' => '@ToppySymfonyAsyncTwig/data_collector/streaming.html.twig',
                'id' => 'toppy.streaming',
            ]);

        $container->register(HttpClientDataCollector::class)
            ->setAutowired(true)
            ->addTag('data_collector', [
                'template' => '@ToppySymfonyAsyncTwig/data_collector/http_client.html.twig',
                'id' => 'toppy.http_client',
            ]);

        // Event listener for streaming debug toolbar
        $container->register(StreamedResponseWebDebugToolbarListener::class)
            ->setAutowired(true)
            ->addTag('kernel.event_subscriber');

        // Early hints providers (conditionally registered based on available bundles)
        // ImportMapEarlyHintsProvider - registered if symfony/asset-mapper is available
        // ViteEarlyHintsProvider - registered if pentatrion/vite-bundle is available

        // Cache layer (optional)
        if ($config['cache']['enabled']) {
            $container->register(SymfonySwrCache::class)
                ->setArguments([
                    new Reference($config['cache']['pool']),
                ]);

            $container->setAlias(SwrCacheInterface::class, SymfonySwrCache::class);

            // Revalidation lock (optional, prevents thundering herd)
            $lockReference = null;
            if ($config['cache']['lock']['enabled']) {
                $container->register(SymfonyRevalidationLock::class)
                    ->setArguments([
                        new Reference($config['cache']['lock']['factory']),
                        $config['cache']['lock']['ttl'],
                    ]);
                $container->setAlias(RevalidationLockInterface::class, SymfonyRevalidationLock::class);
                $lockReference = new Reference(RevalidationLockInterface::class);
            }

            // Decorate ViewModelManagerInterface with caching
            // Uses a ServiceLocator containing all tagged AsyncViewModels (keyed by class name)
            $container->register(CachingViewModelDecorator::class)
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

        // Invalidation endpoint (optional)
        if ($config['invalidation']['enabled']) {
            $container->register(InvalidationController::class)
                ->setArguments([
                    new Reference(SwrCacheInterface::class),
                    $config['invalidation']['secret'],
                    new Reference(LoggerInterface::class),
                ])
                ->addTag('controller.service_arguments');
        }
    }

    public function getAlias(): string
    {
        return 'toppy_symfony_async_twig';
    }
}
