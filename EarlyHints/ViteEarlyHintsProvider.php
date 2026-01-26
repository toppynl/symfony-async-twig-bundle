<?php

declare(strict_types=1);

namespace Toppy\SymfonyAsyncTwigBundle\EarlyHints;

use Toppy\TwigStreaming\EarlyHints\EarlyHintsProviderInterface;

/**
 * Provides Early Hints from Vite entrypoints.
 *
 * Vite bundles resolve bare specifiers at build time, so modulepreload
 * works in Early Hints without importmap race conditions.
 *
 * Unlike ImportMapEarlyHintsProvider, this provider CAN include modulepreload
 * hints because Vite's build output uses direct URLs rather than bare specifiers.
 * There's no importmap to parse before modules can be resolved.
 *
 * @mago-expect analysis:ambiguous-object-method-access
 * @mago-expect analysis:unknown-iterator-type
 * @mago-expect analysis:mixed-assignment
 * @mago-expect analysis:less-specific-nested-return-statement
 *
 * EntrypointsLookup is typed as object (Pentatrion ViteBundle optional dependency).
 * Methods getJSFiles(), getCSSFiles(), getJavascriptDependencies() return iterables.
 */
final class ViteEarlyHintsProvider implements EarlyHintsProviderInterface
{
    /**
     * @param object $entrypointsLookup Pentatrion\ViteBundle\Service\EntrypointsLookup
     * @param string[] $entrypoints
     */
    public function __construct(
        private readonly object $entrypointsLookup,
        private readonly array $entrypoints = ['app'],
    ) {}

    #[\Override]
    public function getHints(): array
    {
        $hints = [];

        foreach ($this->entrypoints as $entrypoint) {
            // JS entry files - can use modulepreload since Vite resolves URLs at build time
            foreach ($this->entrypointsLookup->getJSFiles($entrypoint) as $file) {
                $hints[] = [
                    'rel' => 'modulepreload',
                    'href' => $file,
                    'attributes' => ['crossorigin' => true],
                ];
            }

            // Preload files (shared chunks) - also safe for modulepreload
            // The method is getJavascriptDependencies in Vite bundle (maps to 'preload' in entrypoints.json)
            foreach ($this->entrypointsLookup->getJavascriptDependencies($entrypoint) as $file) {
                $hints[] = [
                    'rel' => 'modulepreload',
                    'href' => $file,
                    'attributes' => ['crossorigin' => true],
                ];
            }

            // CSS files
            foreach ($this->entrypointsLookup->getCSSFiles($entrypoint) as $file) {
                $hints[] = [
                    'rel' => 'preload',
                    'href' => $file,
                    'attributes' => ['as' => 'style', 'crossorigin' => true],
                ];
            }
        }

        return $hints;
    }
}
