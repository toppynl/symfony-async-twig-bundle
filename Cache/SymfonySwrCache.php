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
 * Keys and tags are built by view models from request data (slugs, ids), which
 * may contain the characters PSR-6 reserves ({}()/\@:). They are percent-encoded
 * before reaching the pool so a crafted URL cannot make the pool throw; the
 * encoding is collision-free and applied consistently to keys and tags.
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
        $item = $this->cache->getItem(self::sanitize($key));

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
            self::sanitize($key),
            static function (ItemInterface $item) use ($entry, $tags): CacheEntry {
                $item->expiresAfter($entry->getTotalTtl());
                $item->tag(array_map(self::sanitize(...), $tags));
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
        $this->cache->invalidateTags(array_map(self::sanitize(...), $tags));
    }

    /**
     * Percent-encode everything outside [A-Za-z0-9_.~-]. Leaves typical keys
     * (uuids, locales, snake_case prefixes) untouched, so existing cache
     * entries keep their keys.
     */
    private static function sanitize(string $value): string
    {
        return rawurlencode($value);
    }
}
