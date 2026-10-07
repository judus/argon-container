<?php

declare(strict_types=1);

namespace Maduser\Argon\Container;

use Closure;
use Maduser\Argon\Container\Contracts\ArgumentResolverInterface;
use Maduser\Argon\Container\Contracts\InterceptorRegistryInterface;
use Maduser\Argon\Container\Contracts\ReflectionCacheInterface;
use Maduser\Argon\Container\Contracts\ServiceBinderInterface;
use Maduser\Argon\Container\Contracts\ServiceDescriptorInterface;
use Maduser\Argon\Container\Contracts\ServiceResolverInterface;
use Maduser\Argon\Container\Exceptions\ContainerException;
use Maduser\Argon\Container\Exceptions\NotFoundException;
use Maduser\Argon\Container\Support\DebugTrace;
use Override;
use ReflectionException;
use ReflectionFunction;
use ReflectionNamedType;
use ReflectionParameter;
use Throwable;

/**
 * Resolves services, handling recursive instantiation and singleton caching.
 */
final class ServiceResolver implements ServiceResolverInterface
{
    /**
     * Currently resolving IDs (to detect circular dependencies).
     *
     * @var array<string, true>
     */
    private array $resolving = [];

    /**
     * Static stack of the current resolution path, for debugging/contextual error reporting.
     *
     * @var string[]
     */
    private static array $resolutionStack = [];

    public function __construct(
        private readonly ServiceBinderInterface $binder,
        private readonly ReflectionCacheInterface $reflectionCache,
        private readonly InterceptorRegistryInterface $interceptors,
        private readonly ArgumentResolverInterface $argumentResolver,
        private readonly bool $strictMode = false
    ) {
    }

    /**
     * Resolves a service by ID or class name.
     *
     * @template T of object
     * @param class-string<T>|string $id
     * @param array<array-key, mixed> $args
     * @return object
     * @psalm-return ($id is class-string<T> ? T : object)
     *
     * @throws ContainerException
     * @throws NotFoundException
     *
     * We're going to make one single exception here,
     * IMAO this more likely PossiblyPsalmsProblem
     */
    #[Override]
    public function resolve(string $id, array $args = []): object
    {
        $isRootResolve = empty(self::$resolutionStack);

        if ($isRootResolve) {
            DebugTrace::reset();
        }

        $this->checkCircularDependency($id);
        self::$resolutionStack[] = $id;

        try {
            $result = $this->interceptors->matchPre($id, $args);
            if ($result !== null) {
                return $result;
            }

            $descriptor = $this->binder->getDescriptor($id);

            if ($descriptor !== null) {
                $instance = $this->resolveFromDescriptor($id, $descriptor, $args);
            } elseif (!$this->strictMode && class_exists($id)) {
                $instance = $this->resolveUnregistered($id, $args);
            } else {
                $requestedBy = self::$resolutionStack[count(self::$resolutionStack) - 2] ?? 'unknown';
                throw new NotFoundException($id, $requestedBy);
            }

            return $instance;
        } catch (ReflectionException $e) {
            throw ContainerException::fromServiceId(
                $id,
                'Reflection error: ' . $e->getMessage()
            );
        } finally {
            $this->removeFromResolving($id);
            array_pop(self::$resolutionStack);
        }
    }

    /**
     * @throws ContainerException
     * @throws NotFoundException
     * @throws ReflectionException
     */
    private function resolveFromDescriptor(string $id, ServiceDescriptorInterface $descriptor, array $args): object
    {
        if ($descriptor->isShared()) {
            $instance = $descriptor->getInstance();

            if ($instance !== null) {
                if ($args !== []) {
                    throw ContainerException::fromServiceId(
                        $id,
                        'Cannot pass runtime arguments to an already resolved shared service.'
                    );
                }

                return $instance;
            }
        }

        if ($descriptor->hasFactory()) {
            $instance = $this->resolveFromFactory($id, $descriptor, $args);
        } else {
            $concrete = $descriptor->getConcrete();
            $args = array_merge($descriptor->getArguments(), $args);

            $instance = $concrete instanceof Closure
                ? $this->resolveClosure($concrete, $args, $id)
                : $this->resolveClass($concrete, $args, $concrete === $id ? [] : [$id]);
        }

        $instance = $this->interceptors->matchPost($instance);

        if ($descriptor->isShared()) {
            $descriptor->storeInstance($instance);
        }

        return $instance;
    }

    /**
     * @throws ContainerException
     * @throws NotFoundException
     * @throws ReflectionException
     */
    private function resolveFromFactory(string $id, ServiceDescriptorInterface $descriptor, array $args): object
    {
        $factoryClass = $descriptor->getFactoryClass();
        $method = $descriptor->getFactoryMethod();

        if ($factoryClass === null) {
            throw ContainerException::fromServiceId($id, 'Factory class not defined.');
        }

        $factoryInstance = $this->resolve($factoryClass);

        if (!method_exists($factoryInstance, $method)) {
            throw new ContainerException(sprintf(
                'Factory method "%s" not found on class "%s".',
                $method,
                $factoryClass
            ));
        }

        $reflection = $this->reflectionCache
            ->get(get_class($factoryInstance))
            ->getMethod($method);

        $mergedArgs = array_merge($descriptor->getArguments(), $args);
        $orderedArgs = $this->resolveFactoryParameters($reflection->getParameters(), $mergedArgs, $id);

        return $this->ensureObjectResult(
            $id,
            $reflection->isStatic()
                ? call_user_func([$factoryClass, $method], ...$orderedArgs)
                : call_user_func([$factoryInstance, $method], ...$orderedArgs),
            sprintf('Factory method "%s::%s()"', $factoryClass, $method)
        );
    }

    /**
     * Resolves a class not registered in the container.
     *
     * @param class-string $id
     *
     * @throws ContainerException
     * @throws NotFoundException
     */
    private function resolveUnregistered(string $id, array $args): object
    {
        $reflection = $this->reflectionCache->get($id);

        if (!$reflection->isInstantiable()) {
            throw ContainerException::forNonInstantiableClass($id, $reflection->getName());
        }

        $instance = $this->resolveClass($id, $args);

        return $this->interceptors->matchPost($instance);
    }

    /**
     * Resolves a concrete class by analyzing its constructor dependencies.
     *
     * @template T of object
     * @param class-string<T> $className
     * @param array<array-key, mixed> $args
     * @param list<string> $aliasChain
     * @return object|null
     * @psalm-return ($className is class-string<T> ? T : object)
     *
     * @throws ContainerException
     * @throws NotFoundException
     */
    private function resolveClass(string $className, array $args = [], array $aliasChain = []): ?object
    {
        if (in_array($className, $aliasChain, true)) {
            throw ContainerException::forCircularDependency($className, [...$aliasChain, $className]);
        }

        if ($descriptor = $this->binder->getDescriptor($className)) {
            if ($descriptor->hasFactory()) {
                return $this->resolveFromFactory($className, $descriptor, $args);
            }

            $args = array_merge($descriptor->getArguments(), $args);
            $concrete = $descriptor->getConcrete();

            if ($concrete instanceof Closure) {
                return $this->resolveClosure($concrete, $args, $className);
            }

            if ($concrete !== $className) {
                return $this->resolveClass($concrete, $args, [...$aliasChain, $className]);
            }
        }

        $reflection = $this->reflectionCache->get($className);

        if ($reflection->isInterface()) {
            throw ContainerException::forUnresolvableDependency($className, 'Cannot instantiate interface');
        }

        if (!$reflection->isInstantiable()) {
            throw ContainerException::forNonInstantiableClass($className, $reflection->getName());
        }

        $constructor = $reflection->getConstructor();

        $dependencies = $constructor
            ? $this->resolveConstructorParameters($constructor->getParameters(), $args)
            : [];

        try {
            return $reflection->newInstanceArgs(array_values($dependencies));
        } catch (Throwable $e) {
            throw ContainerException::forInstantiationFailure($className, $e);
        }
    }

    /**
     * Resolves constructor parameters using contextual/primitive logic.
     *
     * @param array<int, ReflectionParameter> $params
     * @param array<array-key, mixed> $overrides
     * @return array<int, mixed>
     *
     * @throws ContainerException
     * @throws NotFoundException
     */
    private function resolveConstructorParameters(array $params, array $overrides): array
    {
        return array_map(
            fn(ReflectionParameter $param): mixed => $this->argumentResolver->resolve($param, $overrides),
            $params
        );
    }

    /**
     * @param array<int, ReflectionParameter> $params
     * @param array<array-key, mixed> $overrides
     * @return array<int, mixed>
     *
     * @throws ContainerException
     * @throws NotFoundException
     */
    private function resolveFactoryParameters(array $params, array $overrides, string $contextId): array
    {
        /** @var list<null|bool|int|float|string|array|object> $resolved */
        $resolved = [];

        foreach ($params as $param) {
            $resolved[] = $this->resolveFactoryParameter($param, $overrides, $contextId);
        }

        return $resolved;
    }

    /**
     * @param array<array-key, mixed> $overrides
     * @return null|bool|int|float|string|array|object
     * @throws ContainerException
     * @throws NotFoundException
     */
    private function resolveFactoryParameter(
        ReflectionParameter $param,
        array $overrides,
        string $contextId
    ): null|bool|int|float|string|array|object {
        $name = $param->getName();

        if (array_key_exists($name, $overrides) || $this->factoryParameterUsesContainerResolution($param)) {
            /** @var null|bool|int|float|string|array|object $resolved */
            $resolved = $this->argumentResolver->resolve($param, $overrides, $contextId);

            return $resolved;
        }

        if ($param->isDefaultValueAvailable()) {
            /** @var null|bool|int|float|string|array|object $arg */
            $arg = $param->getDefaultValue();
            DebugTrace::add(
                $contextId,
                $name,
                $param->getType() instanceof ReflectionNamedType ? $param->getType()->getName() : 'mixed',
                is_scalar($arg) ? (string) $arg : gettype($arg)
            );

            return $arg;
        }

        DebugTrace::fail(
            $contextId,
            $name,
            $param->getType() instanceof ReflectionNamedType ? $param->getType()->getName() : 'mixed'
        );
        throw ContainerException::fromServiceId($contextId, "Missing required argument '$name'");
    }

    private function factoryParameterUsesContainerResolution(ReflectionParameter $param): bool
    {
        $type = $param->getType();

        return !$type instanceof ReflectionNamedType || !$type->isBuiltin();
    }

    /**
     * @param Closure $closure
     * @param array<array-key, mixed> $args
     * @throws ContainerException
     * @throws NotFoundException
     */
    private function resolveClosure(Closure $closure, array $args, string $contextId): object
    {
        $reflection = new ReflectionFunction($closure);
        $dependencies = array_map(
            fn(ReflectionParameter $param): mixed => $this->argumentResolver->resolve($param, $args, $contextId),
            $reflection->getParameters()
        );

        return $this->ensureObjectResult($contextId, $closure(...$dependencies), 'Closure binding');
    }

    private function ensureObjectResult(string $id, mixed $value, string $source): object
    {
        if (!is_object($value)) {
            throw ContainerException::fromServiceId(
                $id,
                sprintf('%s must return an object, got %s.', $source, get_debug_type($value))
            );
        }

        return $value;
    }

    /**
     * Detects circular dependencies by tracking resolution chain.
     *
     * @param string $id
     * @throws ContainerException
     */
    private function checkCircularDependency(string $id): void
    {
        if (isset($this->resolving[$id])) {
            $chain = array_keys($this->resolving);
            $chain[] = $id;
            throw ContainerException::forCircularDependency($id, $chain);
        }

        $this->resolving[$id] = true;
    }

    /**
     * Removes a service ID from the current resolving stack.
     *
     * @param string $id
     */
    private function removeFromResolving(string $id): void
    {
        unset($this->resolving[$id]);
    }
}
