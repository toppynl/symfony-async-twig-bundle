<?php

declare(strict_types=1);

namespace Toppy\SymfonyAsyncTwigBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Disables Symfony's AddLinkHeaderListener to prevent duplicate Link headers.
 *
 * When using StreamingTemplateRenderer with Early Hints, we send Link headers
 * via HTTP 103 before streaming begins. Symfony's AddLinkHeaderListener would
 * add the same headers again to the 200 response from the _links request attribute
 * populated by preload() calls during template rendering.
 *
 * This pass removes the event subscriber tag from the listener, effectively
 * disabling it while keeping the preload(), preconnect(), etc. functions available.
 */
final class DisableWebLinkListenerPass implements CompilerPassInterface
{
    private const string LISTENER_ID = 'web_link.add_link_header_listener';

    /**
     * @throws \Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException
     */
    #[\Override]
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(self::LISTENER_ID)) {
            return;
        }

        $definition = $container->getDefinition(self::LISTENER_ID);

        // Remove all tags to prevent it from being registered as an event subscriber
        $definition->clearTags();
    }
}
