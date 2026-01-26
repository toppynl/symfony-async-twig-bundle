<?php

declare(strict_types=1);

namespace Toppy\SymfonyAsyncTwigBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Toppy\AsyncViewModel\WithDependencies;

/**
 * Validates ViewModel dependencies at container compile time.
 *
 * Detects circular dependencies before runtime, failing the build
 * rather than causing runtime errors.
 */
final class ViewModelDependencyValidationPass implements CompilerPassInterface
{
    /**
     * @throws \LogicException On circular dependency
     * @throws \Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException
     */
    #[\Override]
    public function process(ContainerBuilder $container): void
    {
        $viewModels = $container->findTaggedServiceIds('toppy.async_view_model');

        if ($viewModels === []) {
            return;
        }

        $graph = $this->buildDependencyGraph($container, $viewModels);
        $this->detectCycles($graph);
    }

    /**
     * @param array<string, array<mixed>> $viewModels
     * @return array<class-string, list<class-string>>
     *
     * @throws \Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException
     */
    private function buildDependencyGraph(ContainerBuilder $container, array $viewModels): array
    {
        $graph = [];

        foreach (array_keys($viewModels) as $serviceId) {
            $definition = $container->getDefinition($serviceId);
            $class = $definition->getClass();

            if ($class === null || !class_exists($class)) {
                continue;
            }

            if (!is_subclass_of($class, WithDependencies::class)) {
                $graph[$class] = [];
                continue;
            }

            // Get dependencies - must instantiate or use reflection
            // Since we're at compile time, use reflection to call static or check interface
            try {
                $reflection = new \ReflectionClass($class);
                $method = $reflection->getMethod('getDependencies');

                if ($method->isStatic()) {
                    /** @var list<class-string> $dependencies */
                    $dependencies = $class::getDependencies();
                    $graph[$class] = $dependencies;
                } else {
                    // Instance method - we can't call it at compile time without instantiation
                    // Skip validation for these; runtime will catch cycles
                    $graph[$class] = [];
                }
            } catch (\ReflectionException) {
                $graph[$class] = [];
            }
        }

        return $graph;
    }

    /**
     * @param array<class-string, list<class-string>> $graph
     * @throws \LogicException on circular dependency
     */
    private function detectCycles(array $graph): void
    {
        $visited = [];
        $recursionStack = [];

        foreach (array_keys($graph) as $node) {
            if ($this->hasCycle($node, $graph, $visited, $recursionStack)) {
                throw new \LogicException(sprintf('Circular ViewModel dependency detected: %s', implode(
                    ' -> ',
                    $recursionStack,
                )));
            }
        }
    }

    /**
     * @param array<class-string, list<class-string>> $graph
     * @param array<string, bool> $visited
     * @param list<string> $recursionStack
     */
    private function hasCycle(string $node, array $graph, array &$visited, array &$recursionStack): bool
    {
        if (in_array($node, $recursionStack, strict: true)) {
            $recursionStack[] = $node;
            return true;
        }

        if (isset($visited[$node])) {
            return false;
        }

        $visited[$node] = true;
        $recursionStack[] = $node;

        foreach ($graph[$node] ?? [] as $dep) {
            if ($this->hasCycle($dep, $graph, $visited, $recursionStack)) {
                return true;
            }
        }

        array_pop($recursionStack);
        return false;
    }
}
