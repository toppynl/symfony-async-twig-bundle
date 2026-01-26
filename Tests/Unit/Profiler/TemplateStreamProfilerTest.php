<?php

declare(strict_types=1);

namespace Toppy\SymfonyAsyncTwigBundle\Tests\Unit\Profiler;

use PHPUnit\Framework\TestCase;
use Toppy\AsyncViewModel\Profiler\TimeEpoch;
use Toppy\SymfonyAsyncTwigBundle\Profiler\TemplateStreamProfiler;

/** Tests for TemplateStreamProfiler */
final class TemplateStreamProfilerTest extends TestCase
{
    public function testEnterAndLeaveTemplateCreatesEvents(): void
    {
        $epoch = new TimeEpoch();
        $profiler = new TemplateStreamProfiler($epoch);

        $profiler->enterTemplate('base.html.twig');
        $profiler->leaveTemplate('base.html.twig');

        $events = $profiler->getEvents();

        static::assertCount(2, $events);
        static::assertSame('template_start', $events[0]->type);
        static::assertSame('base.html.twig', $events[0]->name);
        static::assertSame('template_end', $events[1]->type);
        static::assertSame('base.html.twig', $events[1]->name);
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

        static::assertCount(4, $events);
        static::assertSame('block_start', $events[1]->type);
        static::assertSame('content', $events[1]->name);
        static::assertSame('base.html.twig', $events[1]->parent);
    }

    public function testResetClearsEvents(): void
    {
        $epoch = new TimeEpoch();
        $profiler = new TemplateStreamProfiler($epoch);

        $profiler->enterTemplate('test.html.twig');
        $profiler->leaveTemplate('test.html.twig');

        static::assertNotEmpty($profiler->getEvents());

        $profiler->reset();

        static::assertEmpty($profiler->getEvents());
    }

    public function testTimestampsAreFromSharedEpoch(): void
    {
        $epoch = new TimeEpoch();
        usleep(5_000); // 5ms delay before profiler
        $profiler = new TemplateStreamProfiler($epoch);

        $profiler->enterTemplate('test.html.twig');

        $events = $profiler->getEvents();

        // Timestamp should be > 5ms since epoch was created before sleep
        static::assertGreaterThan(4.0, $events[0]->timestamp);
    }
}
