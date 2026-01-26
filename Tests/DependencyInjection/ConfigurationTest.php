<?php

declare(strict_types=1);

namespace Toppy\SymfonyAsyncTwigBundle\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;
use Toppy\SymfonyAsyncTwigBundle\DependencyInjection\Configuration;

final class ConfigurationTest extends TestCase
{
    public function testFeatureTogglesHaveDefaults(): void
    {
        $processor = new Processor();
        $config = $processor->processConfiguration(new Configuration(), [[]]);

        // All features enabled by default
        self::assertTrue($config['view_model']['enabled']);
        self::assertTrue($config['twig_view']['enabled']);
        self::assertTrue($config['streaming']['enabled']);
        self::assertTrue($config['prerender']['enabled']);
        self::assertTrue($config['profiler']['enabled']);
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

        self::assertFalse($config['view_model']['enabled']);
        self::assertFalse($config['twig_view']['enabled']);
        self::assertFalse($config['streaming']['enabled']);
        self::assertFalse($config['prerender']['enabled']);
        self::assertFalse($config['profiler']['enabled']);
    }
}
