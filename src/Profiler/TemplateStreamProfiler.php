<?php

declare(strict_types=1);

namespace Toppy\SymfonyAsyncTwigBundle\Profiler;

use Symfony\Contracts\Service\ResetInterface;
use Toppy\AsyncViewModel\Profiler\TimeEpoch;
use Toppy\TwigStreaming\Profiler\StreamingTimelineEvent;
use Toppy\TwigStreaming\Profiler\TemplateStreamProfilerInterface;

/**
 * Default profiler that collects template/block timing data.
 */
final class TemplateStreamProfiler implements TemplateStreamProfilerInterface, ResetInterface
{
    /** @var array<StreamingTimelineEvent> */
    private array $events = [];

    /** @var list<string> Track current template for block parenting */
    private array $templateStack = [];

    public function __construct(
        private readonly TimeEpoch $epoch,
    ) {}

    public function enterTemplate(string $templateName): void
    {
        $this->templateStack[] = $templateName;
        $this->events[] = new StreamingTimelineEvent(
            type: 'template_start',
            name: $templateName,
            timestamp: $this->epoch->getElapsed(),
            parent: count($this->templateStack) > 1 ? $this->templateStack[count($this->templateStack) - 2] : null,
        );
    }

    public function leaveTemplate(string $templateName): void
    {
        array_pop($this->templateStack);
        $this->events[] = new StreamingTimelineEvent(
            type: 'template_end',
            name: $templateName,
            timestamp: $this->epoch->getElapsed(),
        );
    }

    public function enterBlock(string $templateName, string $blockName): void
    {
        $this->events[] = new StreamingTimelineEvent(
            type: 'block_start',
            name: $blockName,
            timestamp: $this->epoch->getElapsed(),
            parent: $templateName,
        );
    }

    public function leaveBlock(string $templateName, string $blockName): void
    {
        $this->events[] = new StreamingTimelineEvent(
            type: 'block_end',
            name: $blockName,
            timestamp: $this->epoch->getElapsed(),
            parent: $templateName,
        );
    }

    public function getEvents(): array
    {
        return $this->events;
    }

    public function reset(): void
    {
        $this->events = [];
        $this->templateStack = [];
    }
}
