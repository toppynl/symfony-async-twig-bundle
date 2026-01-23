<?php

declare(strict_types=1);

namespace Toppy\SymfonyAsyncTwigBundle\Tests\EarlyHints;

use PHPUnit\Framework\TestCase;
use Toppy\SymfonyAsyncTwigBundle\EarlyHints\ViteEarlyHintsProvider;

/**
 * @covers \Toppy\SymfonyAsyncTwigBundle\EarlyHints\ViteEarlyHintsProvider
 */
final class ViteEarlyHintsProviderTest extends TestCase
{
    public function testReturnsModulePreloadsFromViteEntrypoints(): void
    {
        // Create a mock for EntrypointsLookup since ViteBundle may not be installed
        $lookup = $this->createMock(EntrypointsLookupInterface::class);
        $lookup->method('getJSFiles')
            ->with('app')
            ->willReturn(['/build/assets/app-abc123.js']);
        $lookup->method('getJavascriptDependencies')
            ->with('app')
            ->willReturn(['/build/assets/shared-def456.js']);
        $lookup->method('getCSSFiles')
            ->with('app')
            ->willReturn(['/build/assets/app-ghi789.css']);

        $provider = new ViteEarlyHintsProvider($lookup, ['app']);
        $hints = $provider->getHints();

        $this->assertCount(3, $hints);

        // JS entry
        $this->assertSame('modulepreload', $hints[0]['rel']);
        $this->assertSame('/build/assets/app-abc123.js', $hints[0]['href']);
        $this->assertTrue($hints[0]['attributes']['crossorigin'] ?? false);

        // Preload (shared chunk)
        $this->assertSame('modulepreload', $hints[1]['rel']);
        $this->assertSame('/build/assets/shared-def456.js', $hints[1]['href']);
        $this->assertTrue($hints[1]['attributes']['crossorigin'] ?? false);

        // CSS
        $this->assertSame('preload', $hints[2]['rel']);
        $this->assertSame('/build/assets/app-ghi789.css', $hints[2]['href']);
        $this->assertSame('style', $hints[2]['attributes']['as']);
        $this->assertTrue($hints[2]['attributes']['crossorigin'] ?? false);
    }

    public function testReturnsEmptyWhenNoEntrypoints(): void
    {
        $lookup = $this->createMock(EntrypointsLookupInterface::class);
        $lookup->method('getJSFiles')->willReturn([]);
        $lookup->method('getJavascriptDependencies')->willReturn([]);
        $lookup->method('getCSSFiles')->willReturn([]);

        $provider = new ViteEarlyHintsProvider($lookup, ['app']);
        $hints = $provider->getHints();

        $this->assertSame([], $hints);
    }

    public function testHandlesMultipleEntrypoints(): void
    {
        $lookup = $this->createMock(EntrypointsLookupInterface::class);

        // Set up expectations for multiple entrypoints
        $lookup->method('getJSFiles')
            ->willReturnCallback(fn(string $name) => match ($name) {
                'app' => ['/build/assets/app-abc123.js'],
                'admin' => ['/build/assets/admin-xyz789.js'],
                default => [],
            });
        $lookup->method('getJavascriptDependencies')
            ->willReturn([]);
        $lookup->method('getCSSFiles')
            ->willReturnCallback(fn(string $name) => match ($name) {
                'app' => ['/build/assets/app-main.css'],
                default => [],
            });

        $provider = new ViteEarlyHintsProvider($lookup, ['app', 'admin']);
        $hints = $provider->getHints();

        $this->assertCount(3, $hints);

        // Verify we got hints from both entrypoints
        $hrefs = array_column($hints, 'href');
        $this->assertContains('/build/assets/app-abc123.js', $hrefs);
        $this->assertContains('/build/assets/admin-xyz789.js', $hrefs);
        $this->assertContains('/build/assets/app-main.css', $hrefs);
    }
}

/**
 * Interface to mock EntrypointsLookup since ViteBundle is not installed.
 *
 * This matches the relevant methods from Pentatrion\ViteBundle\Service\EntrypointsLookup
 */
interface EntrypointsLookupInterface
{
    /**
     * @return list<string>
     */
    public function getJSFiles(string $entryName): array;

    /**
     * @return list<string>
     */
    public function getJavascriptDependencies(string $entryName): array;

    /**
     * @return list<string>
     */
    public function getCSSFiles(string $entryName): array;
}
