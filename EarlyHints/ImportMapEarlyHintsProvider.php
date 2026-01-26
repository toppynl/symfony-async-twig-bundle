<?php

declare(strict_types=1);

namespace Toppy\SymfonyAsyncTwigBundle\EarlyHints;

use Symfony\Component\AssetMapper\ImportMap\ImportMapGenerator;
use Toppy\TwigStreaming\EarlyHints\EarlyHintsProviderInterface;

/**
 * Provides Early Hints from Symfony ImportMap configuration.
 *
 * Extracts CSS preloads from the import map for HTTP 103 Early Hints.
 *
 * IMPORTANT: JS modulepreload is intentionally excluded from Early Hints.
 * When using importmaps with bare specifiers (e.g., `import 'htmx.org'`),
 * the browser cannot resolve these specifiers until the importmap is
 * parsed from the HTML. Sending modulepreload in Early Hints causes a
 * race condition where modules load before the importmap is available,
 * resulting in "Failed to resolve module specifier" errors.
 *
 * The HTML's own `<link rel="modulepreload">` tags (rendered after the
 * importmap by Symfony's importmap() function) handle module preloading
 * correctly.
 */
final class ImportMapEarlyHintsProvider implements EarlyHintsProviderInterface
{
    /**
     * @param string[] $entrypoints
     */
    public function __construct(
        private readonly ImportMapGenerator $importMapGenerator,
        private readonly array $entrypoints = ['app'],
    ) {}

    #[\Override]
    public function getHints(): array
    {
        $hints = [];
        $importMapData = $this->importMapGenerator->getImportMapData($this->entrypoints);

        foreach ($importMapData as $data) {
            if (!($data['preload'] ?? false)) {
                continue;
            }

            $path = $data['path'];
            $type = $data['type'];

            // Only include CSS preloads in Early Hints
            // JS modulepreload is handled by HTML after importmap is parsed
            if ($type === 'css') {
                $hints[] = [
                    'rel' => 'preload',
                    'href' => $path,
                    'attributes' => ['as' => 'style'],
                ];
            }
        }

        return $hints;
    }
}
