<?php

declare(strict_types=1);

namespace Toppy\SymfonyAsyncTwigBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Enables Twig's use_yield mode for streaming template support.
 *
 * This compiler pass modifies the Twig Environment service definition
 * to enable generator-based rendering (use_yield: true), which is required
 * for streaming templates with StreamingTemplateRenderer.
 *
 * Note: TwigBundle doesn't expose use_yield in config, so we merge it
 * with existing options on the service definition.
 *
 * @mago-expect analysis:mixed-assignment
 *
 * Definition::getArgument() returns mixed. We check is_array() before use.
 */
final class TwigYieldModeCompilerPass implements CompilerPassInterface
{
    /**
     * @throws \Symfony\Component\DependencyInjection\Exception\OutOfBoundsException
     * @throws \Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException
     */
    #[\Override]
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('twig')) {
            return;
        }

        $definition = $container->getDefinition('twig');
        $existingOptions = $definition->getArgument(1);

        // Merge use_yield with existing options to preserve user configuration
        if (\is_array($existingOptions)) {
            $existingOptions['use_yield'] = true;
            $definition->setArgument(1, $existingOptions);
        } else {
            // Handle abstract_arg case - TwigBundle hasn't set options yet
            // Set minimal required config, TwigBundle will merge later
            $definition->setArgument(1, ['use_yield' => true]);
        }
    }
}
