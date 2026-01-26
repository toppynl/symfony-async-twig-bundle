<?php

declare(strict_types=1);

namespace Toppy\SymfonyAsyncTwigBundle\DataCollector;

use Symfony\Bridge\Twig\DataCollector\TwigDataCollector;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\DataCollector\DataCollector;
use Symfony\Component\HttpKernel\DataCollector\LateDataCollectorInterface;
use Twig\Profiler\Profile;

/**
 * Late-collecting wrapper for TwigDataCollector that supports StreamedResponse.
 *
 * When the response is streamed, templates render AFTER kernel.response,
 * so we defer collection to kernel.terminate via LateDataCollectorInterface.
 *
 * IMPORTANT: Data must be stored in $this->data because collectors are serialized
 * to storage. When viewing past requests, the collector is unserialized without
 * calling the constructor, so $inner won't exist. Getters must read from $this->data.
 */
final class LateTwigDataCollector extends DataCollector implements LateDataCollectorInterface
{
    private ?Request $request = null;
    private ?Response $response = null;
    private ?\Throwable $exception = null;
    private bool $isStreamed = false;

    public function __construct(
        private readonly TwigDataCollector $inner,
        private readonly Profile $profile,
    ) {}

    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
        $this->request = $request;
        $this->response = $response;
        $this->exception = $exception;
        $this->isStreamed = $response instanceof StreamedResponse;

        if (!$this->isStreamed) {
            // Non-streamed: collect immediately (data extraction happens in lateCollect)
            $this->inner->collect($request, $response, $exception);
        }
    }

    public function lateCollect(): void
    {
        if ($this->isStreamed && $this->request !== null && $this->response !== null) {
            // Streamed: collect now that streaming is complete
            $this->inner->collect($this->request, $this->response, $this->exception);
        }

        // If inner has lateCollect, call it
        if ($this->inner instanceof LateDataCollectorInterface) {
            $this->inner->lateCollect();
        }

        // Extract data from inner collector into $this->data for serialization
        $this->extractDataFromInner();

        // Clear references for worker mode
        $this->request = null;
        $this->response = null;
        $this->exception = null;
    }

    /**
     * Extract data from the inner collector into $this->data for serialization.
     */
    private function extractDataFromInner(): void
    {
        $this->data = [
            'time' => $this->inner->getTime(),
            'template_count' => $this->inner->getTemplateCount(),
            'template_paths' => $this->inner->getTemplatePaths(),
            'templates' => $this->inner->getTemplates(),
            'block_count' => $this->inner->getBlockCount(),
            'macro_count' => $this->inner->getMacroCount(),
            'html_call_graph' => $this->inner->getHtmlCallGraph(),
            // Serialize the profile for getProfile() to work after unserialization
            'profile' => $this->profile,
        ];
    }

    public function getName(): string
    {
        return 'twig';
    }

    public function reset(): void
    {
        $this->inner->reset();
        $this->data = [];
        $this->request = null;
        $this->response = null;
        $this->exception = null;
        $this->isStreamed = false;
    }

    // Getter methods read from $this->data (works after unserialization)

    public function getProfile(): Profile
    {
        return $this->data['profile'] ?? $this->profile;
    }

    public function getTime(): float
    {
        return $this->data['time'] ?? 0.0;
    }

    public function getTemplateCount(): int
    {
        return $this->data['template_count'] ?? 0;
    }

    public function getTemplatePaths(): array
    {
        return $this->data['template_paths'] ?? [];
    }

    public function getTemplates(): array
    {
        return $this->data['templates'] ?? [];
    }

    public function getBlockCount(): int
    {
        return $this->data['block_count'] ?? 0;
    }

    public function getMacroCount(): int
    {
        return $this->data['macro_count'] ?? 0;
    }

    public function getHtmlCallGraph(): string
    {
        return $this->data['html_call_graph'] ?? '';
    }
}
