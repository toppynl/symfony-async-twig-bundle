# Async Twig Bundle

> **Read-Only Repository**
> This is a read-only subtree split from the main repository.
> Please submit issues and pull requests to [toppynl/symfony-astro](https://github.com/toppynl/symfony-astro).

Symfony integration bundle for the async Twig rendering stack. This is Layer 3 of the Toppy architecture, providing the bridge between Symfony's service container, request handling, and the framework-agnostic async rendering packages. The bundle auto-configures view models, sets up profiler integration, and provides request-aware context factories for the islands architecture.

## Installation

```bash
composer require toppy/symfony-async-twig-bundle
```

The bundle is auto-registered via Symfony Flex. If not using Flex, add it manually:

```php
// config/bundles.php
return [
    // ...
    Toppy\SymfonyAsyncTwigBundle\ToppySymfonyAsyncTwigBundle::class => ['all' => true],
];
```

## Requirements

- PHP 8.4+
- Symfony 6.4, 7.0, or 8.0
- `toppy/async-view-model` (required)
- `toppy/twig-view-model` (required)
- `toppy/twig-streaming` (optional, for streaming features)
- `toppy/twig-prerender` (optional, for prerender modifiers)

## Quick Start

With zero configuration, the bundle enables core view model functionality:

```yaml
# config/packages/toppy_symfony_async_twig.yaml
toppy_symfony_async_twig: ~
```

Create a view model:

```php
namespace App\ViewModel;

use Toppy\AsyncViewModel\AsyncViewModel;
use Toppy\AsyncViewModel\Context\RequestContext;
use Toppy\AsyncViewModel\Context\ViewContext;

final class ProductStockViewModel implements AsyncViewModel
{
    public function __construct(
        private readonly StockService $stockService,
    ) {}

    public function resolve(ViewContext $viewContext, RequestContext $requestContext): mixed
    {
        $productId = $requestContext->get('product_id');
        return $this->stockService->getStock($productId);
    }
}
```

Use it in templates:

```twig
{# Pre-load for parallel resolution #}
{% do pre_load_view('App\\ViewModel\\ProductStockViewModel', {product_id: product.id}) %}

{# Access resolved data #}
{% set stock = view('App\\ViewModel\\ProductStockViewModel') %}
<div>{{ stock.quantity }} in stock</div>
```

## Architecture

### Key Classes

| Class | Purpose |
|-------|---------|
| `ToppySymfonyAsyncTwigBundle` | Bundle class, registers compiler passes |
| `ToppySymfonyAsyncTwigExtension` | Service registration for all packages |
| `Configuration` | Bundle configuration schema |
| `ContextFactory` | Creates ViewContext/RequestContext from Symfony Request |
| `ContextResolver` | Request-scoped context holder with reset support |
| `ViewModelProfiler` | Collects timing data for view model resolution |
| `TemplateStreamProfiler` | Collects template/block streaming events |
| `ViewModelDataCollector` | Web Profiler panel for view models |
| `StreamingDataCollector` | Unified timeline for streaming debugging |

### Compiler Passes

| Compiler Pass | Purpose |
|---------------|---------|
| `ViewModelDependencyValidationPass` | Detects circular dependencies at compile time |
| `OpenTelemetryCompilerPass` | Auto-registers OpenTelemetry profiler when available |
| `TwigYieldModeCompilerPass` | Enables Twig's `use_yield` mode for streaming |
| `DisableWebLinkListenerPass` | Prevents duplicate Link headers with Early Hints |
| `ReplaceTwigDataCollectorPass` | Wraps Twig collector for streamed response support |
| `ConditionalCompilerPass` | Wrapper for conditional pass execution |

### Service Configuration

The bundle auto-configures services via tags:

```php
// AsyncViewModel implementations are auto-tagged
$container->registerForAutoconfiguration(AsyncViewModel::class)
    ->addTag('toppy.async_view_model');

// EarlyHintsProvider implementations are auto-tagged
$container->registerForAutoconfiguration(EarlyHintsProviderInterface::class)
    ->addTag('toppy.early_hints_provider');
```

View models are collected into a `ServiceLocator` for lazy loading, and the `ViewModelManager` uses this locator to resolve view models on demand.

## Configuration

### Bundle Configuration

```yaml
# config/packages/toppy_symfony_async_twig.yaml
toppy_symfony_async_twig:
    # Core async view model services (ViewModelManager, profiler)
    # Enabled by default
    view_model:
        enabled: true

    # Twig view() and pre_load_view() functions
    # Enabled by default
    twig_view:
        enabled: true

    # Streaming features (deferred slots, early hints)
    # 'auto' detects if toppy/twig-streaming is installed
    streaming:
        enabled: auto  # true | false | 'auto'

    # Prerender modifiers ({% include ... prerender(false) %})
    # 'auto' detects if toppy/twig-prerender is installed
    prerender:
        enabled: auto  # true | false | 'auto'

    # Symfony Web Profiler integration
    # Enabled by default
    profiler:
        enabled: true

    # Stale-while-revalidate cache layer
    cache:
        enabled: false
        pool: cache.app  # Must implement TagAwareCacheInterface
        lock:
            enabled: false  # Prevents thundering herd
            factory: lock.factory
            ttl: 30.0  # Lock TTL in seconds

    # Cache invalidation endpoint
    invalidation:
        enabled: false
        secret: '%env(CACHE_INVALIDATION_SECRET)%'  # Required when enabled
        route_prefix: /_cache
```

### View Model Registration

View models are automatically discovered and registered when they implement `AsyncViewModel`:

```php
namespace App\ViewModel;

use Toppy\AsyncViewModel\AsyncViewModel;

// This class is auto-tagged with 'toppy.async_view_model'
final class MyViewModel implements AsyncViewModel
{
    // ...
}
```

For view models with dependencies on other view models, implement `WithDependencies`:

```php
use Toppy\AsyncViewModel\WithDependencies;

final class OrderTotalsViewModel implements AsyncViewModel, WithDependencies
{
    public static function getDependencies(): array
    {
        return [
            CartItemsViewModel::class,
            ShippingCostViewModel::class,
        ];
    }

    // Dependencies are resolved first, then this view model
}
```

The `ViewModelDependencyValidationPass` detects circular dependencies at container compile time, failing the build rather than causing runtime errors.

## Usage

### In Controllers

Return a `StreamedResponse` with the streaming template renderer:

```php
use Symfony\Component\HttpFoundation\StreamedResponse;
use Toppy\TwigStreaming\Twig\StreamingTemplateRendererInterface;

final class ProductController
{
    public function __construct(
        private readonly StreamingTemplateRendererInterface $renderer,
    ) {}

    public function show(int $id): StreamedResponse
    {
        return new StreamedResponse(
            $this->renderer->stream('product/show.html.twig', [
                'product_id' => $id,
            ])
        );
    }
}
```

For non-streaming responses, use standard Twig rendering - view models still resolve in parallel:

```php
use Twig\Environment;

final class ProductController
{
    public function show(int $id, Environment $twig): Response
    {
        return new Response(
            $twig->render('product/show.html.twig', ['product_id' => $id])
        );
    }
}
```

### In Templates

The `view()` function retrieves resolved view model data:

```twig
{# Pre-load multiple view models for parallel resolution #}
{% do pre_load_view('App\\ViewModel\\ProductDetailsViewModel', {id: product_id}) %}
{% do pre_load_view('App\\ViewModel\\ProductReviewsViewModel', {id: product_id}) %}
{% do pre_load_view('App\\ViewModel\\RelatedProductsViewModel', {id: product_id}) %}

{# Shell renders immediately with skeletons #}
<main>
    {% set details = view('App\\ViewModel\\ProductDetailsViewModel') %}
    {% if details.isReady %}
        <h1>{{ details.name }}</h1>
        <p>{{ details.description }}</p>
    {% else %}
        {% include 'skeletons/product-details.html.twig' %}
    {% endif %}
</main>

<aside>
    {% set reviews = view('App\\ViewModel\\ProductReviewsViewModel') %}
    {# ... #}
</aside>
```

### Context from Symfony Request

The `ContextFactory` creates contexts from the current Symfony request:

```php
// ViewContext contains user/session state
$viewContext = $contextFactory->createViewContext();
// - currency: from session or 'EUR'
// - locale: from request locale
// - isB2B: from session
// - isVatExempt: from session
// - customerGroup: from session

// RequestContext contains route parameters
$requestContext = $contextFactory->createRequestContext(['extra' => 'param']);
// - params: merged from _route_params + additional
// - requestId: unique identifier for this request
```

The `ContextResolver` is request-scoped and implements `ResetInterface` for FrankenPHP worker mode:

```php
// Automatically reset between requests in worker mode
$contextResolver->reset();
```

### Profiler Integration

When `profiler.enabled` is true, the bundle registers data collectors for the Symfony Web Profiler:

#### Web Debug Toolbar

The toolbar shows:
- Number of resolved view models
- Total resolution time
- Parallel efficiency percentage

#### Profiler Panels

**ViewModels Panel** (`toppy.view_model`):
- List of all resolved view models
- Resolution status (success, error, cached, stale)
- Timing information (start, duration)
- Dependencies between view models

**Streaming Panel** (`toppy.streaming`):
- Unified timeline of all events
- Template enter/leave events
- Block enter/leave events
- View model resolution events
- HTTP request events (if HttpClientProfiler is available)
- Key markers (First Template, All Data Ready, Response Complete)

**HTTP Client Panel** (`toppy.http_client`):
- Outgoing HTTP requests made during view model resolution
- Status codes, timing, URLs

The profilers implement `LateDataCollectorInterface` because `StreamedResponse` callbacks execute after `kernel.response`. Data is collected at `kernel.terminate`.

### Streaming Response with Debug Toolbar

The `StreamedResponseWebDebugToolbarListener` injects the web debug toolbar into streamed responses by appending the toolbar JS after the stream completes. This preserves streaming benefits while maintaining debugging capabilities.

### Cache Layer

Enable stale-while-revalidate caching for view models:

```yaml
toppy_symfony_async_twig:
    cache:
        enabled: true
        pool: cache.app
        lock:
            enabled: true
            factory: lock.factory
            ttl: 30.0
```

View models can define cache behavior:

```php
use Toppy\AsyncViewModel\Cache\CacheableViewModel;

final class ExpensiveViewModel implements AsyncViewModel, CacheableViewModel
{
    public function getCacheTtl(): int
    {
        return 300; // Fresh for 5 minutes
    }

    public function getStaleWhileRevalidate(): int
    {
        return 600; // Serve stale for 10 more minutes while revalidating
    }

    public function getCacheTags(RequestContext $context): array
    {
        return ['product:' . $context->get('product_id')];
    }
}
```

### Cache Invalidation

When `invalidation.enabled` is true, an endpoint is available at `/_cache/invalidate`:

```bash
# Invalidate by tags
curl -X POST "https://example.com/_cache/invalidate?secret=your-secret" \
    -H "Content-Type: application/json" \
    -d '{"tags": ["product:123", "category:electronics"]}'

# Or via query params
curl "https://example.com/_cache/invalidate?secret=your-secret&tags[]=product:123"
```

### OpenTelemetry Integration

When `open-telemetry/api` is installed and a `TracerInterface` is available, the `OpenTelemetryCompilerPass` automatically decorates the profiler to emit spans for view model resolution.

### Early Hints Providers

Implement `EarlyHintsProviderInterface` to add resources to HTTP 103 Early Hints:

```php
use Toppy\TwigStreaming\EarlyHints\EarlyHintsProviderInterface;

final class CustomEarlyHintsProvider implements EarlyHintsProviderInterface
{
    public function getHints(): array
    {
        return [
            [
                'rel' => 'preload',
                'href' => '/assets/critical.css',
                'attributes' => ['as' => 'style'],
            ],
        ];
    }
}
```

The bundle includes `ImportMapEarlyHintsProvider` for Symfony AssetMapper integration.

## Integration

This bundle sits at the top of the dependency graph:

```
symfony-async-twig-bundle (this bundle)
        |
   +----+----+
   v         v
twig-prerender --> twig-streaming
                       |
                       |    twig-view-model
                       |         |
                       +----+----+
                            v
                    async-view-model (core)
```

The bundle consolidates service definitions from all packages:
- **async-view-model**: ViewModelManager, profiler interfaces, context abstractions
- **twig-view-model**: ViewExtension, ViewModelRuntime
- **twig-streaming**: SlotRegistry, StreamingTemplateRenderer, EarlyHints
- **twig-prerender**: PrerenderExtension, ContextEncryptor

## Testing

```bash
cd src/Toppy/Bundle/AsyncTwigBundle
composer install
./vendor/bin/phpunit
```

Key test areas:
- Configuration validation (`Tests/DependencyInjection/ConfigurationTest.php`)
- Profiler data collection (`Tests/Unit/Profiler/`)
- Cache implementation (`Tests/Unit/Cache/`)
- Controller responses (`Tests/Unit/Controller/`)

## License

Proprietary - see the main repository for license details.
