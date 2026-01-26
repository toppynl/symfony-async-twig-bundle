<?php

declare(strict_types=1);

namespace Toppy\SymfonyAsyncTwigBundle\Tests\Unit\Profiler;

use PHPUnit\Framework\TestCase;
use Toppy\AsyncViewModel\Profiler\TimeEpoch;
use Toppy\SymfonyAsyncTwigBundle\Profiler\TemplateStreamProfiler;

final class TemplateStreamProfilerTest extends TestCase
{
    public function testEnterAndLeaveTemplateCreatesEvents(): void
    {
        $epoch = new TimeEpoch();
        $profiler = new TemplateStreamProfiler($epoch);

        $profiler->enterTemplate('base.html.twig');
        $profiler->leaveTemplate('base.html.twig');

        $events = $profiler->getEvents();

        $this->assertCount(2, $events);
        $this->assertSame('template_start', $events[0]->type);
        $this->assertSame('base.html.twig', $events[0]->name);
        $this->assertSame('template_end', $events[1]->type);
        $this->assertSame('base.html.twig', $events[1]->name);
    }

    public function testEnterAndLeaveBlockCreatesEvents(): void
    {
        $epoch = new TimeEpoch();
        $profiler = new TemplateStreamProfiler($epoch);

        $profiler->enterTemplate('base.html.twig');
        $profiler->enterBlock('base.html.twig', 'content');
        $profiler->leaveBlock('base.html.twig', 'content');
        $profiler->leaveTemplate('base.html.twig');

        $events = $profiler->getEvents();

        $this->assertCount(4, $events);
        $this->assertSame('block_start', $events[1]->type);
        $this->assertSame('content', $events[1]->name);
        $this->assertSame('base.html.twig', $events[1]->parent);
    }

    public function testResetClearsEvents(): void
    {
        $epoch = new TimeEpoch();
        $profiler = new TemplateStreamProfiler($epoch);

        $profiler->enterTemplate('test.html.twig');
        $profiler->leaveTemplate('test.html.twig');

        $this->assertNotEmpty($profiler->getEvents());

        $profiler->reset();

        $this->assertEmpty($profiler->getEvents());
    }

    public function testTimestampsAreFromSharedEpoch(): void
    {
        $epoch = new TimeEpoch();
        usleep(5_000); // 5ms delay before profiler
        $profiler = new TemplateStreamProfiler($epoch);

        $profiler->enterTemplate('test.html.twig');

        $events = $profiler->getEvents();

        // Timestamp should be > 5ms since epoch was created before sleep
        $this->assertGreaterThan(4.0, $events[0]->timestamp);
    }
}
