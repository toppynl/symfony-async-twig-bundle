<?php

declare(strict_types=1);

namespace Toppy\SymfonyAsyncTwigBundle\Cache;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;
use Toppy\AsyncViewModel\Cache\CacheEntry;
use Toppy\AsyncViewModel\Cache\SwrCacheInterface;

/**
 * Symfony Cache implementation of SwrCacheInterface.
 *
 * Requires a cache that implements both TagAwareCacheInterface (for tag-based
 * invalidation) and CacheItemPoolInterface (for direct item access).
 * Symfony's TagAwareAdapter satisfies both.
 *
 * @mago-expect analysis:mixed-assignment
 *
 * CacheItem::get() returns mixed. We check instanceof before returning.
 */
final class SymfonySwrCache implements SwrCacheInterface
{
    public function __construct(
        private readonly TagAwareCacheInterface&CacheItemPoolInterface $cache,
    ) {}

    /**
     * @throws \Psr\Cache\InvalidArgumentException
     */
    #[\Override]
    public function get(string $key): ?CacheEntry
    {
        $item = $this->cache->getItem($key);

        if (!$item->isHit()) {
            return null;
        }

        $value = $item->get();

        return $value instanceof CacheEntry ? $value : null;
    }

    #[\Override]
    public function set(string $key, CacheEntry $entry, array $tags): void
    {
        $this->cache->get(
            $key,
            static function (ItemInterface $item) use ($entry, $tags): CacheEntry {
                $item->expiresAfter($entry->getTotalTtl());
                $item->tag($tags);
                return $entry;
            },
            INF, // Force immediate computation (beta = INF)
        );
    }

    /**
     * @throws \Psr\Cache\InvalidArgumentException
     */
    #[\Override]
    public function invalidateTags(array $tags): void
    {
        $this->cache->invalidateTags($tags);
    }
}
