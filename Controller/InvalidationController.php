<?php

declare(strict_types=1);

namespace Toppy\SymfonyAsyncTwigBundle\Controller;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Toppy\AsyncViewModel\Cache\SwrCacheInterface;

/**
 * HTTP endpoint for cache invalidation.
 */
#[Route('/_cache', name: 'toppy_cache_')]
final class InvalidationController
{
    public function __construct(
        private readonly SwrCacheInterface $cache,
        private readonly string $secret,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    #[Route('/invalidate', name: 'invalidate', methods: ['GET', 'POST'])]
    public function invalidate(Request $request): Response
    {
        $providedSecret = $request->query->get('secret')
            ?? $request->headers->get('X-Cache-Secret');

        if (!hash_equals($this->secret, $providedSecret ?? '')) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_FORBIDDEN);
        }

        $tags = $this->extractTags($request);

        if (empty($tags)) {
            return new JsonResponse(['error' => 'No tags provided'], Response::HTTP_BAD_REQUEST);
        }

        $this->cache->invalidateTags($tags);

        $this->logger->info('Cache invalidated', ['tags' => $tags]);

        return new JsonResponse([
            'status' => 'ok',
            'invalidated' => $tags,
        ]);
    }

    /**
     * @return array<string>
     */
    private function extractTags(Request $request): array
    {
        // Try query params first (GET or POST with query string)
        if ($request->query->has('tags')) {
            return $request->query->all('tags');
        }

        // Try JSON body (POST)
        if ($request->getContentTypeFormat() === 'json') {
            try {
                $data = $request->toArray();
                return $data['tags'] ?? [];
            } catch (JsonException|\JsonException) {
                return [];
            }
        }

        return [];
    }
}
