<?php

declare(strict_types=1);

namespace Toppy\SymfonyAsyncTwigBundle\Context;

use Symfony\Contracts\Service\ResetInterface;
use Toppy\AsyncViewModel\Context\ContextFactoryInterface;
use Toppy\AsyncViewModel\Context\ContextResolverInterface;
use Toppy\AsyncViewModel\Context\RequestContext;
use Toppy\AsyncViewModel\Context\ViewContext;

final class ContextResolver implements ContextResolverInterface, ResetInterface
{
    private ?ViewContext $viewContext = null;
    private ?RequestContext $requestContext = null;

    public function __construct(
        private readonly ContextFactoryInterface $factory,
    ) {}

    public function setViewContext(ViewContext $context): void
    {
        $this->viewContext = $context;
    }

    public function setRequestContext(RequestContext $context): void
    {
        $this->requestContext = $context;
    }

    public function getViewContext(): ViewContext
    {
        return $this->viewContext ??= $this->factory->createViewContext();
    }

    public function getRequestContext(): RequestContext
    {
        return $this->requestContext ??= $this->factory->createRequestContext();
    }

    public function reset(): void
    {
        $this->viewContext = null;
        $this->requestContext = null;
    }
}
