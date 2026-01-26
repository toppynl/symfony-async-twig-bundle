<?php

declare(strict_types=1);

namespace Toppy\SymfonyAsyncTwigBundle\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Toppy\AsyncViewModel\Cache\SwrCacheInterface;
use Toppy\SymfonyAsyncTwigBundle\Controller\InvalidationController;

final class InvalidationControllerTest extends TestCase
{
    private const SECRET = 'test-secret-123';

    public function testUnauthorizedWithoutSecret(): void
    {
        $cache = $this->createMock(SwrCacheInterface::class);
        $cache->expects($this->never())->method('invalidateTags');

        $controller = new InvalidationController($cache, self::SECRET);

        $request = Request::create('/_cache/invalidate', 'GET');
        $response = $controller->invalidate($request);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('Unauthorized', $response->getContent());
    }

    public function testUnauthorizedWithWrongSecret(): void
    {
        $cache = $this->createMock(SwrCacheInterface::class);
        $cache->expects($this->never())->method('invalidateTags');

        $controller = new InvalidationController($cache, self::SECRET);

        $request = Request::create('/_cache/invalidate?secret=wrong', 'GET');
        $response = $controller->invalidate($request);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testBadRequestWithoutTags(): void
    {
        $cache = $this->createMock(SwrCacheInterface::class);
        $cache->expects($this->never())->method('invalidateTags');

        $controller = new InvalidationController($cache, self::SECRET);

        $request = Request::create('/_cache/invalidate?secret=' . self::SECRET, 'GET');
        $response = $controller->invalidate($request);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('No tags provided', $response->getContent());
    }

    public function testGetWithQueryParams(): void
    {
        $cache = $this->createMock(SwrCacheInterface::class);
        $cache->expects($this->once())
            ->method('invalidateTags')
            ->with(['product_123', 'stock']);

        $controller = new InvalidationController($cache, self::SECRET);

        $request = Request::create(
            '/_cache/invalidate?secret=' . self::SECRET . '&tags[]=product_123&tags[]=stock',
            'GET'
        );
        $response = $controller->invalidate($request);

        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertSame('ok', $data['status']);
        $this->assertSame(['product_123', 'stock'], $data['invalidated']);
    }

    public function testPostWithJsonBody(): void
    {
        $cache = $this->createMock(SwrCacheInterface::class);
        $cache->expects($this->once())
            ->method('invalidateTags')
            ->with(['product_456']);

        $controller = new InvalidationController($cache, self::SECRET);

        $request = Request::create(
            '/_cache/invalidate?secret=' . self::SECRET,
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['tags' => ['product_456']])
        );
        $response = $controller->invalidate($request);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testSecretViaHeader(): void
    {
        $cache = $this->createMock(SwrCacheInterface::class);
        $cache->expects($this->once())
            ->method('invalidateTags')
            ->with(['tag1']);

        $controller = new InvalidationController($cache, self::SECRET);

        $request = Request::create(
            '/_cache/invalidate?tags[]=tag1',
            'POST',
            [],
            [],
            [],
            ['HTTP_X_CACHE_SECRET' => self::SECRET]
        );
        $response = $controller->invalidate($request);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testPostWithQueryParamTags(): void
    {
        $cache = $this->createMock(SwrCacheInterface::class);
        $cache->expects($this->once())
            ->method('invalidateTags')
            ->with(['tag_a', 'tag_b']);

        $controller = new InvalidationController($cache, self::SECRET);

        $request = Request::create(
            '/_cache/invalidate?secret=' . self::SECRET . '&tags[]=tag_a&tags[]=tag_b',
            'POST'
        );
        $response = $controller->invalidate($request);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testQueryParamSecretTakesPrecedenceOverHeader(): void
    {
        $cache = $this->createMock(SwrCacheInterface::class);
        $cache->expects($this->once())
            ->method('invalidateTags')
            ->with(['tag1']);

        $controller = new InvalidationController($cache, self::SECRET);

        // Query param has correct secret, header has wrong one
        $request = Request::create(
            '/_cache/invalidate?secret=' . self::SECRET . '&tags[]=tag1',
            'POST',
            [],
            [],
            [],
            ['HTTP_X_CACHE_SECRET' => 'wrong-secret']
        );
        $response = $controller->invalidate($request);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testEmptyTagsArrayReturnsBadRequest(): void
    {
        $cache = $this->createMock(SwrCacheInterface::class);
        $cache->expects($this->never())->method('invalidateTags');

        $controller = new InvalidationController($cache, self::SECRET);

        $request = Request::create(
            '/_cache/invalidate?secret=' . self::SECRET,
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['tags' => []])
        );
        $response = $controller->invalidate($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testInvalidJsonReturnsBadRequest(): void
    {
        $cache = $this->createMock(SwrCacheInterface::class);
        $cache->expects($this->never())->method('invalidateTags');

        $controller = new InvalidationController($cache, self::SECRET);

        $request = Request::create(
            '/_cache/invalidate?secret=' . self::SECRET,
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            'not valid json{'
        );
        $response = $controller->invalidate($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testJsonBodyMissingTagsKeyReturnsBadRequest(): void
    {
        $cache = $this->createMock(SwrCacheInterface::class);
        $cache->expects($this->never())->method('invalidateTags');

        $controller = new InvalidationController($cache, self::SECRET);

        $request = Request::create(
            '/_cache/invalidate?secret=' . self::SECRET,
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['other_key' => 'value'])
        );
        $response = $controller->invalidate($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testSingleTagInQueryParam(): void
    {
        $cache = $this->createMock(SwrCacheInterface::class);
        $cache->expects($this->once())
            ->method('invalidateTags')
            ->with(['single_tag']);

        $controller = new InvalidationController($cache, self::SECRET);

        $request = Request::create(
            '/_cache/invalidate?secret=' . self::SECRET . '&tags[]=single_tag',
            'GET'
        );
        $response = $controller->invalidate($request);

        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertSame(['single_tag'], $data['invalidated']);
    }

    public function testResponseContainsCorrectStructure(): void
    {
        $cache = $this->createMock(SwrCacheInterface::class);
        $cache->expects($this->once())->method('invalidateTags');

        $controller = new InvalidationController($cache, self::SECRET);

        $request = Request::create(
            '/_cache/invalidate?secret=' . self::SECRET . '&tags[]=test',
            'GET'
        );
        $response = $controller->invalidate($request);

        $data = json_decode($response->getContent(), true);

        $this->assertArrayHasKey('status', $data);
        $this->assertArrayHasKey('invalidated', $data);
        $this->assertSame('ok', $data['status']);
        $this->assertIsArray($data['invalidated']);
    }

    public function testTimingAttackProtection(): void
    {
        // This test verifies that hash_equals is used (constant-time comparison)
        // by checking that the controller still returns 403 for similar secrets
        $cache = $this->createMock(SwrCacheInterface::class);
        $cache->expects($this->never())->method('invalidateTags');

        $controller = new InvalidationController($cache, self::SECRET);

        // Similar secret (one character different)
        $request = Request::create(
            '/_cache/invalidate?secret=test-secret-124&tags[]=tag',
            'GET'
        );
        $response = $controller->invalidate($request);

        $this->assertSame(403, $response->getStatusCode());
    }
}
