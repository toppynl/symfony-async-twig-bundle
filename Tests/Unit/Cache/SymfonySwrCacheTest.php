<?php

declare(strict_types=1);

namespace Toppy\SymfonyAsyncTwigBundle\Tests\Unit\Cache;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Toppy\AsyncViewModel\Cache\CacheEntry;
use Toppy\SymfonyAsyncTwigBundle\Cache\SymfonySwrCache;

/**
 * @mago-expect analysis:mixed-property-access
 */
final class SymfonySwrCacheTest extends TestCase
{
    private TagAwareAdapter $symfonyCache;
    private SymfonySwrCache $cache;

    #[\Override]
    protected function setUp(): void
    {
        $this->symfonyCache = new TagAwareAdapter(new ArrayAdapter());
        $this->cache = new SymfonySwrCache($this->symfonyCache);
    }

    public function testGetReturnsNullOnMiss(): void
    {
        $result = $this->cache->get('nonexistent');

        static::assertNull($result);
    }

    public function testSetAndGet(): void
    {
        $value = new \stdClass();
        $value->name = 'test';

        $entry = new CacheEntry($value, time(), 300, 3600, 86400);

        $this->cache->set('test_key', $entry, ['tag1', 'tag2']);

        $retrieved = $this->cache->get('test_key');

        static::assertInstanceOf(CacheEntry::class, $retrieved);
        static::assertSame('test', $retrieved->value->name);
        static::assertSame(300, $retrieved->maxAge);
    }

    public function testInvalidateTags(): void
    {
        $entry = new CacheEntry(new \stdClass(), time(), 300, 3600, 86400);

        $this->cache->set('key1', $entry, ['product_123']);
        $this->cache->set('key2', $entry, ['product_456']);
        $this->cache->set('key3', $entry, ['product_123', 'stock']);

        // Both keys should exist
        static::assertNotNull($this->cache->get('key1'));
        static::assertNotNull($this->cache->get('key2'));
        static::assertNotNull($this->cache->get('key3'));

        // Invalidate product_123 tag
        $this->cache->invalidateTags(['product_123']);

        // key1 and key3 should be invalidated
        static::assertNull($this->cache->get('key1'));
        static::assertNotNull($this->cache->get('key2'));
        static::assertNull($this->cache->get('key3'));
    }

    public function testGetReturnsNullForNonCacheEntry(): void
    {
        // Store something that's not a CacheEntry directly via Symfony cache
        $this->symfonyCache->get('raw_value', static fn() => 'not a cache entry');

        $result = $this->cache->get('raw_value');

        static::assertNull($result);
    }
}
