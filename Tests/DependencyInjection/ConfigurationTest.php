<?php

declare(strict_types=1);

namespace Toppy\SymfonyAsyncTwigBundle\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;
use Toppy\SymfonyAsyncTwigBundle\DependencyInjection\Configuration;

/**
 * @mago-expect analysis:mixed-array-access
 */
final class ConfigurationTest extends TestCase
{
    public function testFeatureTogglesHaveDefaults(): void
    {
        $processor = new Processor();
        $config = $processor->processConfiguration(new Configuration(), [[]]);

        // All features enabled by default (streaming/prerender use 'auto' for package detection)
        static::assertTrue($config['view_model']['enabled']);
        static::assertTrue($config['twig_view']['enabled']);
        static::assertSame('auto', $config['streaming']['enabled']);
        static::assertSame('auto', $config['prerender']['enabled']);
        static::assertTrue($config['profiler']['enabled']);
    }

    public function testFeatureTogglesCanBeDisabled(): void
    {
        $processor = new Processor();
        $config = $processor->processConfiguration(new Configuration(), [[
            'view_model' => ['enabled' => false],
            'twig_view' => ['enabled' => false],
            'streaming' => ['enabled' => false],
            'prerender' => ['enabled' => false],
            'profiler' => ['enabled' => false],
        ]]);

        static::assertFalse($config['view_model']['enabled']);
        static::assertFalse($config['twig_view']['enabled']);
        static::assertFalse($config['streaming']['enabled']);
        static::assertFalse($config['prerender']['enabled']);
        static::assertFalse($config['profiler']['enabled']);
    }
}
