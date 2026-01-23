<?php

declare(strict_types=1);

namespace Toppy\SymfonyAsyncTwigBundle\EventListener;

use Symfony\Bundle\FrameworkBundle\FullStack;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Bundle\WebProfilerBundle\Csp\ContentSecurityPolicyHandler;
use Twig\Environment;

/**
 * Injects the web debug toolbar into StreamedResponse objects.
 *
 * Symfony's WebDebugToolbarListener doesn't support StreamedResponse because
 * content is generated on-the-fly. This listener wraps the streaming callback
 * with output buffering to capture the output and inject the toolbar before </body>.
 *
 * This mirrors the approach from symfony/symfony#58789.
 *
 * @see https://github.com/symfony/symfony/pull/58789
 */
final class StreamedResponseWebDebugToolbarListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly Environment $twig,
        private readonly ?string $excludedAjaxPaths = '^/((index|app(_[\w]+)?)\.php/)?_wdt',
        private readonly ?ContentSecurityPolicyHandler $cspHandler = null,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            // Same priority as Symfony's WebDebugToolbarListener (-128)
            KernelEvents::RESPONSE => ['onKernelResponse', -128],
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        $response = $event->getResponse();
        $request = $event->getRequest();

        // Only handle StreamedResponse (regular Response is handled by Symfony's listener)
        if (!$response instanceof StreamedResponse) {
            return;
        }

        // Check if profiler token exists (set by profiler listener)
        if (!$response->headers->has('X-Debug-Token')) {
            return;
        }

        // Skip redirects
        if ($response->isRedirection()) {
            return;
        }

        // Skip non-HTML responses
        $contentType = $response->headers->get('Content-Type', '');
        if (!str_contains($contentType, 'html')) {
            return;
        }

        // Skip non-HTML request formats
        if ('html' !== $request->getRequestFormat()) {
            return;
        }

        // Skip attachments
        if ($response->headers->has('Content-Disposition')
            && str_contains($response->headers->get('Content-Disposition', ''), 'attachment')) {
            return;
        }

        // Skip excluded AJAX paths (e.g., /_wdt itself)
        if ($this->excludedAjaxPaths !== null
            && $request->isXmlHttpRequest()
            && preg_match('#' . $this->excludedAjaxPaths . '#', $request->getPathInfo())) {
            return;
        }

        // Get CSP nonces if handler is available
        $nonces = $this->cspHandler?->updateResponseHeaders($request, $response) ?? [];

        $this->injectToolbar($response, $request, $nonces);
    }

    /**
     * @param array{csp_script_nonce?: ?string, csp_style_nonce?: ?string} $nonces
     */
    private function injectToolbar(StreamedResponse $response, Request $request, array $nonces): void
    {
        $token = $response->headers->get('X-Debug-Token');
        $originalCallback = $response->getCallback();

        if ($originalCallback === null) {
            return;
        }

        $twig = $this->twig;
        $excludedAjaxPaths = $this->excludedAjaxPaths;

        // Wrap the callback to append toolbar JS after streaming completes.
        // We append AFTER the stream (not buffering) to preserve streaming benefits.
        // The toolbar JS loads via iframe, so appending after </body> is safe.
        $injectedCallback = static function () use ($originalCallback, $token, $request, $nonces, $excludedAjaxPaths, $twig): void {
            // Execute the original streaming callback
            $originalCallback();

            // Append toolbar JS after the stream completes
            try {
                $toolbarHtml = $twig->render('@WebProfiler/Profiler/toolbar_js.html.twig', [
                    'full_stack' => class_exists(FullStack::class),
                    'excluded_ajax_paths' => $excludedAjaxPaths,
                    'token' => $token,
                    'request' => $request,
                    'csp_script_nonce' => $nonces['csp_script_nonce'] ?? null,
                    'csp_style_nonce' => $nonces['csp_style_nonce'] ?? null,
                ]);

                echo "\n" . str_replace("\n", '', $toolbarHtml);

                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            } catch (\Throwable) {
                // Silently ignore toolbar rendering failures
            }
        };

        $response->setCallback($injectedCallback);
    }
}
