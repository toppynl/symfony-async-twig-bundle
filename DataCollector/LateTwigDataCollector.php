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
 *
 * @phpstan-type DataArray array{
 *     time?: float,
 *     template_count?: int,
 *     template_paths?: array<string, string>,
 *     templates?: array<string, int>,
 *     block_count?: int,
 *     macro_count?: int,
 *     html_call_graph?: string,
 *     profile?: Profile
 * }
 *
 * @mago-expect analysis:mixed-assignment
 *
 * Symfony DataCollector $this->data is typed as mixed. Getters use instanceof/is_*
 * checks before returning, but assignment from $this->data['key'] triggers warning.
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

    #[\Override]
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

    #[\Override]
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

    #[\Override]
    public function getName(): string
    {
        return 'twig';
    }

    #[\Override]
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
        $profile = $this->data['profile'] ?? null;
        return $profile instanceof Profile ? $profile : $this->profile;
    }

    public function getTime(): float
    {
        $time = $this->data['time'] ?? null;
        if (is_float($time)) {
            return $time;
        }
        if (is_int($time)) {
            return (float) $time;
        }
        return 0.0;
    }

    public function getTemplateCount(): int
    {
        $count = $this->data['template_count'] ?? null;
        return is_int($count) ? $count : 0;
    }

    /**
     * @return array<string, string>
     */
    public function getTemplatePaths(): array
    {
        $paths = $this->data['template_paths'] ?? null;
        /** @var array<string, string> */
        return is_array($paths) ? $paths : [];
    }

    /**
     * @return array<string, int>
     */
    public function getTemplates(): array
    {
        $templates = $this->data['templates'] ?? null;
        /** @var array<string, int> */
        return is_array($templates) ? $templates : [];
    }

    public function getBlockCount(): int
    {
        $count = $this->data['block_count'] ?? null;
        return is_int($count) ? $count : 0;
    }

    public function getMacroCount(): int
    {
        $count = $this->data['macro_count'] ?? null;
        return is_int($count) ? $count : 0;
    }

    public function getHtmlCallGraph(): string
    {
        $graph = $this->data['html_call_graph'] ?? null;
        return is_string($graph) ? $graph : '';
    }
}
