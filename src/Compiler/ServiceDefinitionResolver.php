<?php

declare(strict_types=1);

namespace Maduser\Argon\Container\Compiler;

use Closure;
use Maduser\Argon\Container\ArgonContainer;
use Maduser\Argon\Container\Contracts\ServiceDescriptorInterface;
use Maduser\Argon\Container\ServiceDescriptor;

final class ServiceDefinitionResolver
{
    public function resolve(
        ArgonContainer $container,
        string $id,
        ServiceDescriptorInterface $descriptor
    ): ServiceDescriptorInterface|CircularAlias {
        $current = $descriptor;
        $arguments = $descriptor->getArguments();
        $chain = [$id];
        $currentId = $id;

        while (!$current->hasFactory()) {
            $concrete = $current->getConcrete();
            if ($concrete instanceof Closure || $concrete === $currentId) {
                break;
            }
            if (in_array($concrete, $chain, true)) {
                return new CircularAlias($concrete, [...$chain, $concrete]);
            }
            $target = $container->getDescriptor($concrete);
            if ($target === null) {
                break;
            }
            $chain[] = $concrete;
            $currentId = $concrete;
            $current = $target;
            $arguments = array_merge($current->getArguments(), $arguments);
        }

        if ($current === $descriptor) {
            return $descriptor;
        }

        // Keep the construction binding's identity for factory context; callers retain the requested cache ID.
        $resolved = new ServiceDescriptor($currentId, $current->getConcrete(), $descriptor->isShared(), $arguments);
        $factory = $current->getFactoryClass();
        if ($factory !== null) {
            $resolved->setFactory($factory, $current->getFactoryMethod());
        }
        foreach ($descriptor->getInvocationMap() as $method => $args) {
            $resolved->defineInvocation($method, $args);
        }
        return $resolved;
    }
}
