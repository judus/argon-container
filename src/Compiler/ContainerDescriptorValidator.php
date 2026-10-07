<?php

declare(strict_types=1);

namespace Maduser\Argon\Container\Compiler;

use Closure;
use Maduser\Argon\Container\ArgonContainer;
use Maduser\Argon\Container\Contracts\ServiceDescriptorInterface;
use Maduser\Argon\Container\Exceptions\ContainerException;
use ReflectionClass;
use ReflectionException;

final class ContainerDescriptorValidator
{
    /**
     * @throws ContainerException
     * @throws ReflectionException
     */
    public function validate(ArgonContainer $container): void
    {
        foreach ($container->getBindings() as $id => $descriptor) {
            if ($descriptor->shouldCompile() === false) {
                continue;
            }

            $resolved = (new ServiceDefinitionResolver())->resolve($container, $id, $descriptor);
            if ($resolved instanceof CircularAlias) {
                // The generated getter reports alias cycles when resolution is actually attempted.
                continue;
            }
            $this->validateDescriptor($id, $resolved);
        }
    }

    /**
     * @throws ContainerException
     * @throws ReflectionException
     */
    private function validateDescriptor(string $id, ServiceDescriptorInterface $descriptor): void
    {
        $concrete = $descriptor->getConcrete();

        if ($concrete instanceof Closure) {
            throw new ContainerException(
                "Cannot compile a container with closures: [$id]. " .
                "Use skipCompilation() to exclude it, " .
                "or register the closure during boot/runtime after compilation."
            );
        }

        $this->validateInvocations($id, $descriptor, $concrete);

        if ($descriptor->hasFactory()) {
            $this->validateFactory($id, $descriptor);
            return;
        }

        $reflection = new ReflectionClass($concrete);
        if (!$reflection->isInstantiable()) {
            $target = " [$concrete]";
            throw new ContainerException(
                "Service [$id] points to non-instantiable class$target."
            );
        }
    }

    /**
     * @throws ContainerException
     * @throws ReflectionException
     */
    private function validateFactory(string $id, ServiceDescriptorInterface $descriptor): void
    {
        $factoryClass = $descriptor->getFactoryClass();
        $factoryMethod = $descriptor->getFactoryMethod();

        if ($factoryClass === null) {
            throw ContainerException::fromServiceId($id, 'Factory class not defined.');
        }

        $reflection = new ReflectionClass($factoryClass);

        if (!$reflection->hasMethod($factoryMethod)) {
            throw new ContainerException(sprintf(
                'Factory method "%s" not found on class "%s".',
                $factoryMethod,
                $factoryClass
            ));
        }

        $method = $reflection->getMethod($factoryMethod);
        if (!$method->isPublic()) {
            throw ContainerException::fromServiceId(
                $id,
                sprintf('Factory method "%s" on class "%s" is not public.', $factoryMethod, $factoryClass)
            );
        }
    }

    /**
     * @param class-string $concrete
     * @throws ContainerException
     * @throws ReflectionException
     */
    private function validateInvocations(
        string $id,
        ServiceDescriptorInterface $descriptor,
        string $concrete
    ): void {
        $invocations = $descriptor->getInvocationMap();

        if ($invocations === []) {
            return;
        }

        $reflection = new ReflectionClass($concrete);

        foreach (array_keys($invocations) as $methodName) {
            if (!$reflection->hasMethod($methodName)) {
                throw ContainerException::fromServiceId(
                    $id,
                    sprintf('Invocation method "%s" not found on class "%s".', $methodName, $concrete)
                );
            }

            $method = $reflection->getMethod($methodName);
            if (!$method->isPublic()) {
                throw ContainerException::fromServiceId(
                    $id,
                    sprintf('Invocation method "%s" on class "%s" is not public.', $methodName, $concrete)
                );
            }
        }
    }
}
