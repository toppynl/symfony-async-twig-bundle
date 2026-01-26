<?php

declare(strict_types=1);

namespace Toppy\SymfonyAsyncTwigBundle\Context;

use Symfony\Component\HttpFoundation\RequestStack;
use Toppy\AsyncViewModel\Context\ContextFactoryInterface;
use Toppy\AsyncViewModel\Context\RequestContext;
use Toppy\AsyncViewModel\Context\ViewContext;

final class ContextFactory implements ContextFactoryInterface
{
    public function __construct(
        private readonly RequestStack $requestStack,
    ) {}

    public function createViewContext(bool $isPrivate = false): ViewContext
    {
        $request = $this->requestStack->getCurrentRequest();

        if ($request === null) {
            return ViewContext::create(
                currency: 'EUR',
                locale: 'en',
                isB2B: false,
                isVatExempt: false,
                customerGroup: null,
                isPrivate: $isPrivate,
            );
        }

        $session = $request->hasSession() ? $request->getSession() : null;

        /** @var string $currency */
        $currency = $session?->get('currency', 'EUR') ?? 'EUR';
        /** @var bool $isB2B */
        $isB2B = $session?->get('is_b2b', false) ?? false;
        /** @var bool $isVatExempt */
        $isVatExempt = $session?->get('is_vat_exempt', false) ?? false;
        /** @var string|null $customerGroup */
        $customerGroup = $session?->get('customer_group');

        return ViewContext::create(
            currency: $currency,
            locale: $request->getLocale(),
            isB2B: $isB2B,
            isVatExempt: $isVatExempt,
            customerGroup: $customerGroup,
            isPrivate: $isPrivate,
        );
    }

    public function createRequestContext(array $additionalParams = []): RequestContext
    {
        $request = $this->requestStack->getCurrentRequest();

        /** @var array<string, mixed> $routeParams */
        $routeParams = $request?->attributes->get('_route_params', []) ?? [];

        return RequestContext::create(
            params: array_merge($routeParams, $additionalParams),
            requestId: bin2hex(random_bytes(16)),
        );
    }
}
