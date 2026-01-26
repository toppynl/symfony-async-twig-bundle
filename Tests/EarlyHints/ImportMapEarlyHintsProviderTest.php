<?php

declare(strict_types=1);

namespace Toppy\SymfonyAsyncTwigBundle\Tests\EarlyHints;

use PHPUnit\Framework\TestCase;
use Symfony\Component\AssetMapper\ImportMap\ImportMapGenerator;
use Toppy\SymfonyAsyncTwigBundle\EarlyHints\ImportMapEarlyHintsProvider;

/** Tests for ImportMapEarlyHintsProvider */
final class ImportMapEarlyHintsProviderTest extends TestCase
{
    public function testSkipsJsModulesInEarlyHints(): void
    {
        // JS modules are intentionally excluded from Early Hints
        // because importmaps with bare specifiers require the importmap
        // to be parsed from HTML before modules can resolve dependencies.
        // Sending modulepreload in Early Hints causes a race condition.
        $generator = $this->createStub(ImportMapGenerator::class);
        $generator
            ->method('getImportMapData')
            ->with(['app'])
            ->willReturn([
                'app' => ['path' => '/assets/app-abc123.js', 'type' => 'js', 'preload' => true],
                'htmx.org' => ['path' => '/assets/htmx-def456.js', 'type' => 'js', 'preload' => true],
                'some-lib' => ['path' => '/assets/lib.js', 'type' => 'js'], // No preload
            ]);

        $provider = new ImportMapEarlyHintsProvider($generator, ['app']);
        $hints = $provider->getHints();

        // No hints for JS modules - they're handled by HTML after importmap
        static::assertSame([], $hints);
    }

    public function testHandlesCssPreloads(): void
    {
        $generator = $this->createStub(ImportMapGenerator::class);
        $generator
            ->method('getImportMapData')
            ->willReturn([
                'styles' => ['path' => '/assets/app.css', 'type' => 'css', 'preload' => true],
            ]);

        $provider = new ImportMapEarlyHintsProvider($generator, ['app']);
        $hints = $provider->getHints();

        static::assertCount(1, $hints);
        static::assertSame('preload', $hints[0]['rel']);
        static::assertSame('/assets/app.css', $hints[0]['href']);
        static::assertSame('style', $hints[0]['attributes']['as']);
    }

    public function testReturnsEmptyWhenNoPreloads(): void
    {
        $generator = $this->createStub(ImportMapGenerator::class);
        $generator
            ->method('getImportMapData')
            ->willReturn([
                'app' => ['path' => '/assets/app.js', 'type' => 'js'], // No preload flag
            ]);

        $provider = new ImportMapEarlyHintsProvider($generator, ['app']);
        $hints = $provider->getHints();

        static::assertSame([], $hints);
    }
}
