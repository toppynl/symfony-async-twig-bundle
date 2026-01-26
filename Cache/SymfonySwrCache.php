<?php

declare(strict_types=1);

namespace Toppy\SymfonyAsyncTwigBundle\Cache;

use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;
use Toppy\AsyncViewModel\Cache\CacheEntry;
use Toppy\AsyncViewModel\Cache\SwrCacheInterface;

/**
 * Symfony Cache implementation of SwrCacheInterface.
 */
final class SymfonySwrCache implements SwrCacheInterface
{
    public function __construct(
        private readonly TagAwareCacheInterface $cache,
    ) {}

    public function get(string $key): ?CacheEntry
    {
        $item = $this->cache->getItem($key);

        if (!$item->isHit()) {
            return null;
        }

        $value = $item->get();

        return $value instanceof CacheEntry ? $value : null;
    }

    public function set(string $key, CacheEntry $entry, array $tags): void
    {
        $this->cache->get(
            $key,
            function (ItemInterface $item) use ($entry, $tags): CacheEntry {
                $item->expiresAfter($entry->getTotalTtl());
                $item->tag($tags);
                return $entry;
            },
            INF, // Force immediate computation (beta = INF)
        );
    }

    public function invalidateTags(array $tags): void
    {
        $this->cache->invalidateTags($tags);
    }
}
