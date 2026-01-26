<?php

declare(strict_types=1);

namespace Toppy\SymfonyAsyncTwigBundle\Cache;

use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Toppy\AsyncViewModel\Cache\RevalidationLockInterface;

/**
 * Symfony Lock implementation for revalidation locking.
 *
 * Prevents thundering herd by ensuring only one request
 * revalidates a given cache key at a time.
 */
final class SymfonyRevalidationLock implements RevalidationLockInterface
{
    /** @var array<string, LockInterface> */
    private array $locks = [];

    public function __construct(
        private readonly LockFactory $lockFactory,
        private readonly float $ttl = 30.0,
    ) {}

    /**
     * @throws \Symfony\Component\Lock\Exception\LockAcquiringException
     * @throws \Symfony\Component\Lock\Exception\LockConflictedException
     */
    #[\Override]
    public function acquire(string $key): bool
    {
        $lockKey = 'cache_revalidation_' . $key;
        $lock = $this->lockFactory->createLock($lockKey, $this->ttl);

        if (!$lock->acquire(blocking: false)) {
            return false;
        }

        $this->locks[$key] = $lock;
        return true;
    }

    /**
     * @throws \Symfony\Component\Lock\Exception\LockReleasingException
     */
    #[\Override]
    public function release(string $key): void
    {
        if (!isset($this->locks[$key])) {
            return;
        }

        $this->locks[$key]->release();
        unset($this->locks[$key]);
    }
}
