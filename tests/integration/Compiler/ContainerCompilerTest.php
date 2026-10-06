<?php

declare(strict_types=1);

namespace Tests\Integration\Compiler;

use Maduser\Argon\Container\ArgonContainer;
use Maduser\Argon\Container\Compiler\ContainerCompiler;
use Maduser\Argon\Container\Contracts\ServiceDescriptorInterface;
use Maduser\Argon\Container\Exceptions\ContainerException;
use Maduser\Argon\Container\Exceptions\NotFoundException;
use Maduser\Argon\Container\Support\ReflectionUtils;
use Maduser\Argon\Container\Support\ServiceInvoker;
use ArrayIterator;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionException;
use ReflectionMethod;
use RuntimeException;
use stdClass;
use TypeError;
use Tests\Integration\Compiler\Mocks\SelfDependent;
use Tests\Integration\Compiler\Mocks\DefaultValueService;
use Tests\Integration\Compiler\Mocks\CyclicFactory;
use Tests\Integration\Compiler\Mocks\CyclicPreInterceptor;
use Tests\Integration\Compiler\Mocks\CyclicPostInterceptor;
use Tests\Integration\Compiler\Mocks\ResolutionLimitInterceptor;
use Tests\Integration\Compiler\Mocks\FallbackConsumer;
use Tests\Integration\Compiler\Mocks\DependentLoggerInterceptor;
use Tests\Integration\Compiler\Mocks\ImplicitNullable;
use Tests\Integration\Compiler\Mocks\InvocationTarget;
use Tests\Integration\Compiler\Mocks\Logger;
use Tests\Integration\Compiler\Mocks\LoggerInterceptor;
use Tests\Integration\Compiler\Mocks\Mailer;
use Tests\Integration\Compiler\Mocks\MailerFactory;
use Tests\Integration\Compiler\Mocks\PrimitiveService;
use Tests\Integration\Compiler\Mocks\RouteStyleController;
use Tests\Integration\Compiler\Mocks\ServiceWithDependency;
use Tests\Integration\Compiler\Mocks\SomeInterface;
use Tests\Integration\Compiler\Mocks\StatefulDefaultValueFactory;
use Tests\Integration\Compiler\Mocks\TestServiceWithMultipleParams;
use Tests\Integration\Compiler\Mocks\WithOptionalInterface;
use Tests\Integration\Compiler\Mocks\WithOptionalService;
use Tests\Integration\Mocks\CustomLogger;
use Tests\Integration\Mocks\A;
use Tests\Integration\Mocks\B;
use Tests\Integration\Mocks\C;
use Tests\Integration\Mocks\DeepGraph;
use Tests\Integration\Mocks\Foo;
use Tests\Integration\Mocks\InterceptedClass;
use Tests\Integration\Mocks\Logger as AutowireLogger;
use Tests\Integration\Mocks\LoggerInterface;
use Tests\Integration\Mocks\MidLevel;
use Tests\Integration\Mocks\NeedsLogger;
use Tests\Integration\Mocks\PostHook;
use Tests\Integration\Mocks\PreArgOverride;
use Tests\Integration\Mocks\SimpleService;
use Tests\Integration\Mocks\StaticFooFactory;
use Tests\Mocks\DummyProvider;

final class ContainerCompilerTest extends TestCase
{
    private static ?string $compilerCacheDir = null;

    /** @return iterable<string, array{bool, string, mixed, bool}> */
    public static function numericInvocationCases(): iterable
    {
        $cases = [
            'integer' => ['id', 42, true],
            'numeric-string' => ['id', '42', true],
            'negative-string' => ['id', '-42', true],
            'whitespace' => ['id', ' 42 ', true],
            'exponent' => ['id', '4.2e1', true],
            'integral-float' => ['id', 42.0, true],
            'boolean' => ['id', true, true],
            'max-int' => ['id', (string) PHP_INT_MAX, true],
            'min-int' => ['id', (string) PHP_INT_MIN, true],
            'invalid-integer' => ['id', 'bad', false],
            'numeric-prefix' => ['id', '42bad', false],
            'empty-string' => ['id', '', false],
            'overflow-string' => ['id', '1e100', false],
            'overflow-float' => ['id', 1e100, false],
            'infinite-integer' => ['id', INF, false],
            'array-integer' => ['id', [], false],
            'object-integer' => ['id', new stdClass(), false],
            'null-integer' => ['id', null, false],
            'float' => ['ratio', 3.5, true],
            'numeric-float-string' => ['ratio', '3.5', true],
            'float-exponent' => ['ratio', '3.5e2', true],
            'infinite-float' => ['ratio', INF, true],
            'invalid-float' => ['ratio', 'bad', false],
            'array-float' => ['ratio', [], false],
            'object-float' => ['ratio', new stdClass(), false],
            'null-float' => ['ratio', null, false],
        ];
        foreach ([false, true] as $strict) {
            foreach ($cases as $name => [$parameter, $value, $accepted]) {
                yield ($strict ? 'strict-' : 'dynamic-') . $name => [$strict, $parameter, $value, $accepted];
            }
        }
    }

    #[DataProvider('numericInvocationCases')]
    public function testCompiledNumericInvocationMatchesRuntime(
        bool $strict,
        string $parameter,
        mixed $value,
        bool $accepted
    ): void {
        $runtime = new ArgonContainer(strictMode: $strict);
        $runtime->set(Logger::class);
        $runtime->set(RouteStyleController::class)->defineInvocation(
            'typed',
            ReflectionUtils::getMethodParameters(RouteStyleController::class, 'typed')
        );
        $compiled = $this->compileAndLoadContainer($runtime, 'NumericInvocation_' . bin2hex(random_bytes(6)));
        $arguments = array_replace(['id' => '42'], [$parameter => $value]);
        $results = [];

        foreach ([$runtime, $compiled] as $container) {
            $controller = $container->get(RouteStyleController::class);
            $invoker = new ServiceInvoker($container, RouteStyleController::class, 'typed');
            $rejected = false;
            try {
                $results[] = $invoker($arguments);
            } catch (TypeError) {
                $rejected = true;
            }
            self::assertSame(!$accepted, $rejected, 'Invalid input must be rejected before the handler executes.');
            self::assertSame($accepted ? 1 : 0, $controller->calls);
        }
        if ($accepted) {
            self::assertSame($results[0], $results[1]);
        }
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function cycleCases(): iterable
    {
        foreach (['runtime', 'dynamic', 'strict', 'mixed'] as $mode) {
            $kinds = $mode === 'mixed' ? ['constructor'] : ['constructor', 'self', 'factory', 'pre', 'post'];
            foreach ($kinds as $kind) {
                foreach ([true, false] as $shared) {
                    yield $mode . '_' . $kind . '_' . (int) $shared => [$mode, $kind, $shared];
                }
            }
        }
    }

    #[DataProvider('cycleCases')]
    public function testResolutionCyclesAreDetectedAndUnwound(string $mode, string $kind, bool $shared): void
    {
        $container = new ArgonContainer(strictMode: $mode === 'strict');
        // Compiled interceptor IDs are fully qualified; bind that exact ID in strict mode.
        $container->set(ResolutionLimitInterceptor::class);
        $container->set('\\' . ResolutionLimitInterceptor::class);
        $container->registerInterceptor(ResolutionLimitInterceptor::class);
        $container->set(Logger::class);
        $container->set(A::class);
        $container->set(B::class);
        $container->set(C::class);
        $container->set(SelfDependent::class);
        $container->set(stdClass::class);
        if ($mode === 'mixed') {
            $container->set(B::class)->skipCompilation();
        }

        if ($kind === 'factory') {
            $container->set(CyclicFactory::class);
            $container->set(stdClass::class)->factory(CyclicFactory::class, 'create');
        } elseif ($kind === 'pre') {
            $container->set(CyclicPreInterceptor::class);
            $container->set('\\' . CyclicPreInterceptor::class);
            $container->registerInterceptor(CyclicPreInterceptor::class);
        } elseif ($kind === 'post') {
            $container->set(CyclicPostInterceptor::class);
            $container->set('\\' . CyclicPostInterceptor::class);
            $container->registerInterceptor(CyclicPostInterceptor::class);
        }
        if (!$shared) {
            foreach ($container->getBindings() as $descriptor) {
                $descriptor->setShared(false);
            }
        }
        if ($mode !== 'runtime') {
            $container = $this->compileAndLoadContainer($container, 'Cycle_' . $mode . '_' . $kind . (int) $shared);
        }

        $id = match ($kind) {
            'constructor' => A::class,
            'self' => SelfDependent::class,
            default => stdClass::class,
        };
        $prefix = $mode === 'runtime' ? '' : '\\';
        $chain = match ($kind) {
            'constructor' => [A::class, B::class, C::class, A::class],
            'self' => [SelfDependent::class, SelfDependent::class],
            'factory' => [stdClass::class, stdClass::class],
            'pre' => [stdClass::class, $prefix . CyclicPreInterceptor::class, stdClass::class],
            'post' => [stdClass::class, $prefix . CyclicPostInterceptor::class, stdClass::class],
        };
        for ($attempt = 0; $attempt < 2; $attempt++) {
            ResolutionLimitInterceptor::$calls = 0;
            try {
                $container->get($id);
                self::fail('Expected a circular dependency exception.');
            } catch (ContainerException $exception) {
                self::assertStringContainsString(
                    "Circular dependency detected for service '$id'. Chain: " . implode(' -> ', $chain),
                    $exception->getMessage()
                );
            }
            self::assertInstanceOf(Logger::class, $container->get(Logger::class));
        }
        if ($kind === 'factory') {
            $replacement = new stdClass();
            self::assertSame($replacement, $container->get(stdClass::class, ['dependency' => $replacement]));
        }
    }

    #[DataProvider('invocationModes')]
    public function testCompiledResolutionGuardIsClearedAfterErrors(bool $strict): void
    {
        $runtime = new ArgonContainer(strictMode: $strict);
        $runtime->set(PrimitiveService::class, args: ['path' => 'valid']);
        $compiled = $this->compileAndLoadContainer($runtime, 'GuardErrors_' . (int) $strict);

        foreach ([$runtime, $compiled] as $container) {
            for ($attempt = 0; $attempt < 2; $attempt++) {
                try {
                    $container->get(PrimitiveService::class, ['path' => new stdClass()]);
                    self::fail('Expected an invalid constructor argument to fail.');
                } catch (ContainerException | TypeError $exception) {
                    self::assertStringNotContainsString('Circular dependency', $exception->getMessage());
                }
                try {
                    $container->get('missing-service');
                    self::fail('Expected a missing service to fail.');
                } catch (NotFoundException $exception) {
                    self::assertStringContainsString('missing-service', $exception->getMessage());
                }
            }
            $service = $container->get(PrimitiveService::class);
            self::assertSame('valid', $service->path);
            self::assertSame($service, $container->get(PrimitiveService::class));
        }
    }

    #[DataProvider('invocationModes')]
    public function testCompiledCycleCanBeShortCircuitedAndGuardIsCleared(bool $strict): void
    {
        $runtime = new ArgonContainer(strictMode: $strict);
        $runtime->set(ResolutionLimitInterceptor::class);
        $runtime->set('\\' . ResolutionLimitInterceptor::class);
        $runtime->registerInterceptor(ResolutionLimitInterceptor::class);
        $runtime->set(CyclicFactory::class);
        $runtime->set(stdClass::class)->factory(CyclicFactory::class, 'create');
        $compiled = $this->compileAndLoadContainer($runtime, 'GuardShortCircuit_' . (int) $strict);

        try {
            $replacement = new stdClass();
            ResolutionLimitInterceptor::$replacement = $replacement;
            ResolutionLimitInterceptor::$calls = 0;
            foreach ([$runtime, $compiled] as $container) {
                self::assertSame($replacement, $container->get(stdClass::class));
                self::assertSame($replacement, $container->get(stdClass::class));
            }
        } finally {
            ResolutionLimitInterceptor::$replacement = null;
            ResolutionLimitInterceptor::$calls = 0;
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function fallbackLifecycles(): iterable
    {
        yield 'shared' => [true];
        yield 'transient' => [false];
    }

    #[DataProvider('fallbackLifecycles')]
    public function testDynamicFallbackUsesCompiledDependencies(bool $shared): void
    {
        $runtime = new ArgonContainer();
        $runtime->set(LoggerInterface::class, Logger::class);
        $runtime->set(Logger::class);
        $runtime->set(PrimitiveService::class, args: ['path' => '/configured']);
        $runtime->set(StatefulDefaultValueFactory::class, args: ['label' => 'factory']);
        $runtime->set(DefaultValueService::class, args: ['label' => 'product'])
            ->factory(StatefulDefaultValueFactory::class, 'create');
        if (!$shared) {
            $runtime->set(Logger::class)->transient();
        }
        $compiled = $this->compileAndLoadContainer($runtime, 'FallbackDependencies_' . (int) $shared);

        foreach ([$runtime, $compiled] as $container) {
            $first = $container->get(FallbackConsumer::class);
            $second = $container->get(FallbackConsumer::class);

            self::assertNotSame($first, $second);
            self::assertNotSame($first->nested, $second->nested);
            self::assertSame($container->get(LoggerInterface::class), $first->nested->logger);
            self::assertSame($first->nested->logger, $second->nested->logger);
            self::assertSame('/configured', $first->configured->path);
            self::assertSame($container->get(PrimitiveService::class), $first->configured);
            self::assertSame('factory:product', $first->product->label);
            self::assertSame($container->get(DefaultValueService::class), $first->product);
            self::assertSame($shared, $first->logger === $second->logger);
            self::assertSame($shared, $first->logger === $container->get(Logger::class));
        }
    }

    public function testFallbackConcreteDependencyKeepsCompiledSingleton(): void
    {
        $runtime = new ArgonContainer();
        $runtime->set(Logger::class);
        $compiled = $this->compileAndLoadContainer($runtime, 'FallbackConcreteSingleton');

        foreach ([$runtime, $compiled] as $container) {
            $logger = $container->get(Logger::class);
            self::assertSame($logger, $container->get(Mailer::class)->logger);
        }
    }

    public function testBootClosureCanConsumeCompiledDependency(): void
    {
        $runtime = new ArgonContainer();
        $runtime->set(LoggerInterface::class, Logger::class);
        $compiled = $this->compileAndLoadContainer($runtime, 'BootClosureCompiledDependency');

        foreach ([$runtime, $compiled] as $container) {
            $container->set(
                'boot.consumer',
                static fn(LoggerInterface $logger): NeedsLogger => new NeedsLogger($logger)
            );
            $consumer = $container->get('boot.consumer');
            self::assertInstanceOf(NeedsLogger::class, $consumer);
            self::assertSame($container->get(LoggerInterface::class), $consumer->logger);
        }
    }

    public function testFallbackHonorsExplicitAndLiveContextualDependencies(): void
    {
        $runtime = new ArgonContainer();
        $runtime->set(LoggerInterface::class, Logger::class);
        $runtime->set(CustomLogger::class);
        $compiled = $this->compileAndLoadContainer($runtime, 'FallbackOverrides');

        foreach ([$runtime, $compiled] as $container) {
            $explicit = new CustomLogger();
            self::assertSame($explicit, $container->get(NeedsLogger::class, ['logger' => $explicit])->logger);
            self::assertSame(
                $container->get(CustomLogger::class),
                $container->get(NeedsLogger::class, ['logger' => CustomLogger::class])->logger
            );
            $container->for(NeedsLogger::class)->set(LoggerInterface::class, CustomLogger::class);
            self::assertSame($container->get(CustomLogger::class), $container->get(NeedsLogger::class)->logger);
        }
    }

    /** @return iterable<string, array{bool, string}> */
    public static function callableCases(): iterable
    {
        foreach ([false, true] as $strict) {
            foreach (
                [
                'closure', 'zero-closure', 'function', 'object', 'class',
                'object-method', 'class-method', 'static-string', 'static-array', 'method-string',
                ] as $form
            ) {
                yield ($strict ? 'strict-' : 'dynamic-') . $form => [$strict, $form];
            }
        }
    }

    #[DataProvider('callableCases')]
    public function testCompiledCallableFormsMatchRuntime(bool $strict, string $form): void
    {
        $runtime = new ArgonContainer(strictMode: $strict);
        $runtime->set(InvocationTarget::class, args: ['prefix' => 'configured']);
        $compiled = $this->compileAndLoadContainer(
            $runtime,
            'Callable_' . ($strict ? 'strict_' : 'dynamic_') . str_replace('-', '_', $form)
        );
        $target = match ($form) {
            'closure' => static fn(string $value): string => 'closure:' . $value,
            'zero-closure' => static fn(): string => 'no arguments',
            'function' => 'strtoupper',
            'object' => new InvocationTarget('object'),
            'class' => InvocationTarget::class,
            'object-method' => [new InvocationTarget('object'), '__invoke'],
            'class-method' => [InvocationTarget::class, '__invoke'],
            'static-string' => InvocationTarget::class . '::staticMethod',
            'static-array' => [InvocationTarget::class, 'staticMethod'],
            'method-string' => InvocationTarget::class . '::__invoke',
        };
        $args = $form === 'function' ? ['string' => 'hello'] : ['value' => 'hello'];

        self::assertSame($runtime->invoke($target, $args), $compiled->invoke($target, $args));
    }

    /** @return iterable<string, array{bool}> */
    public static function invocationModes(): iterable
    {
        yield 'dynamic' => [false];
        yield 'strict' => [true];
    }

    #[DataProvider('invocationModes')]
    public function testCompiledInvocationArgumentsMatchRuntime(bool $strict): void
    {
        $runtime = new ArgonContainer(strictMode: $strict);
        $runtime->set(LoggerInterface::class, Logger::class);
        $runtime->set(CustomLogger::class);
        $compiled = $this->compileAndLoadContainer($runtime, 'InvocationArguments_' . (int) $strict);
        $target = new InvocationTarget();
        $iterator = new ArrayIterator(['one', 'two']);

        foreach (
            [
            ['union', ['value' => 7]],
            ['union', ['value' => 'seven']],
            ['intersection', ['value' => $iterator]],
            ['nullable', []],
            ['nullable', ['logger' => null]],
            ['defaults', []],
            ['defaults', ['number' => '12', 'text' => null]],
            ] as [$method, $args]
        ) {
            self::assertSame(
                $runtime->invoke([$target, $method], $args),
                $compiled->invoke([$target, $method], $args)
            );
        }

        foreach ([$runtime, $compiled] as $container) {
            self::assertSame(
                $container->get(LoggerInterface::class),
                $container->invoke([$target, 'dependency'])
            );
            self::assertSame(
                $container->get(LoggerInterface::class),
                $container->invoke(static fn(LoggerInterface $logger): LoggerInterface => $logger)
            );
            $explicit = new CustomLogger();
            self::assertSame($explicit, $container->invoke([$target, 'nullable'], ['logger' => $explicit]));
            self::assertSame(
                $container->get(CustomLogger::class),
                $container->invoke([$target, 'nullable'], ['logger' => CustomLogger::class])
            );
        }
    }

    #[DataProvider('invocationModes')]
    public function testCompiledInvocationUsesCurrentContextualBindings(bool $strict): void
    {
        $runtime = new ArgonContainer(strictMode: $strict);
        $runtime->set(CustomLogger::class);
        $compiled = $this->compileAndLoadContainer($runtime, 'InvocationContext_' . (int) $strict);

        foreach ([$runtime, $compiled] as $container) {
            $target = new InvocationTarget();
            self::assertNull($container->invoke([$target, 'nullable']));
            $container->for(InvocationTarget::class . '::nullable')->set(LoggerInterface::class, CustomLogger::class);
            self::assertSame($container->get(CustomLogger::class), $container->invoke([$target, 'nullable']));
        }
    }

    #[DataProvider('invocationModes')]
    public function testCompiledInvocationPropagatesApplicationExceptions(bool $strict): void
    {
        $compiled = $this->compileAndLoadContainer(
            new ArgonContainer(strictMode: $strict),
            'InvocationException_' . (int) $strict
        );
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('application failure');
        $compiled->invoke([new InvocationTarget(), 'fail']);
    }

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        $cacheDir = sys_get_temp_dir() . '/argon-compiler-tests-' . bin2hex(random_bytes(8));

        if (!mkdir($cacheDir) && !is_dir($cacheDir)) {
            throw new RuntimeException('Failed to create compiler test cache directory.');
        }

        self::$compilerCacheDir = $cacheDir;
    }

    #[\Override]
    public static function tearDownAfterClass(): void
    {
        $cacheDir = self::$compilerCacheDir;

        if ($cacheDir === null || !is_dir($cacheDir)) {
            return;
        }

        $files = glob($cacheDir . '/*.php');
        if ($files !== false) {
            foreach ($files as $file) {
                unlink($file);
            }
        }

        rmdir($cacheDir);
        self::$compilerCacheDir = null;
    }

    /**
     * @throws ContainerException
     * @throws ReflectionException
     */
    private function compileAndLoadContainer(
        ArgonContainer $container,
        string $className,
        ?bool $strictMode = null
    ): ArgonContainer {
        $namespace = 'Tests\\Integration\\Compiler';
        $file = self::compilerCacheFile($className);

        $compiler = new ContainerCompiler($container);
        $compiler->compile($file, $className, $namespace, $strictMode);

        /** @psalm-suppress UnresolvableInclude */
        require_once $file;

        $fqcn = "{$namespace}\\{$className}";
        if (!class_exists($fqcn)) {
            throw new RuntimeException("Failed to load compiled container class: $fqcn");
        }

        /** @var ArgonContainer $fqcn */
        return new $fqcn();
    }

    private static function compilerCacheFile(string $className): string
    {
        if (self::$compilerCacheDir === null) {
            throw new RuntimeException('Compiler test cache directory has not been initialized.');
        }

        return self::$compilerCacheDir . "/{$className}.php";
    }

    /**
     * @throws ContainerException
     * @throws NotFoundException
     * @throws ReflectionException
     */
    public function testCompiledContainerDoesNotResolveClosures(): void
    {
        $container = new ArgonContainer();

        $this->expectException(ContainerException::class);

        $container->set(Logger::class, fn() => new Logger());
        $container->set(Mailer::class, fn() => new Mailer($container->get(Logger::class)));
        $container->set(DefaultValueService::class, fn() => new DefaultValueService())->skipCompilation();

        $this->compileAndLoadContainer($container, 'testCompiledContainerResolvesClosures');
    }

    /**
     * @throws NotFoundException
     * @throws ReflectionException
     * @throws ContainerException
     */
    public function testCompiledContainerCanIgnore(): void
    {
        $container = new ArgonContainer();
        $service = new DefaultValueService();
        $container->set(DefaultValueService::class, fn() => $service)->skipCompilation();

        $compiled = $this->compileAndLoadContainer($container, 'testCompiledContainerResolvesClosures');

        // For now, we just check that compiler does not throw an error,
        // just a dummy assertion
        $this->assertNotSame($service, $compiled->get(DefaultValueService::class));
    }

    /**
     * @throws ContainerException
     * @throws NotFoundException
     * @throws ReflectionException
     */
    public function testRuntimeClosureCanBeRegisteredOnCompiledContainer(): void
    {
        $container = new ArgonContainer();

        $compiled = $this->compileAndLoadContainer(
            $container,
            'testRuntimeClosureCanBeRegisteredOnCompiledContainer'
        );

        $compiled->set('runtime.mailer', fn(Logger $logger): Mailer => new Mailer($logger))->skipCompilation();

        $mailer = $compiled->get('runtime.mailer');

        $this->assertInstanceOf(Mailer::class, $mailer);
        $this->assertInstanceOf(Logger::class, $mailer->logger);
    }

    /**
     * @throws ContainerException
     * @throws ReflectionException
     * @throws NotFoundException
     */
    public function testCompiledContainerResolvesSingletons(): void
    {
        $container = new ArgonContainer();

        $container->set(Logger::class);
        $container->set(Mailer::class);

        $compiled = $this->compileAndLoadContainer($container, 'testCompiledContainerResolvesSingletons');

        $mailer = $compiled->get(Mailer::class);
        $mailer2 = $compiled->get(Mailer::class);
        $logger = $compiled->get(Logger::class);
        $logger2 = $compiled->get(Logger::class);

        $this->assertInstanceOf(Mailer::class, $mailer);
        $this->assertInstanceOf(Logger::class, $mailer->logger);

        // Singleton assertions
        $this->assertSame($mailer, $mailer2, 'Mailer should be a singleton');
        $this->assertSame($logger, $logger2, 'Logger should be a singleton');
        $this->assertSame($logger, $mailer->logger, 'Mailer.logger should be the same Logger singleton');
    }

    /**
     * @throws ContainerException
     * @throws NotFoundException
     * @throws ReflectionException
     */
    public function testCompiledContainerResolvesTransients(): void
    {
        $container = new ArgonContainer();

        $container->set(Logger::class)->transient();
        $container->set(Mailer::class)->transient();

        $compiled = $this->compileAndLoadContainer($container, 'testCompiledContainerResolvesTransients');

        $mailer = $compiled->get(Mailer::class);
        $mailer2 = $compiled->get(Mailer::class);

        $this->assertInstanceOf(Mailer::class, $mailer);
        $this->assertInstanceOf(Logger::class, $mailer->logger);
        $this->assertNotSame($mailer, $mailer2);
        $this->assertNotSame($mailer->logger, $mailer2->logger);
    }

    /**
     * @throws ReflectionException
     * @throws ContainerException
     * @throws NotFoundException
     */
    public function testCompiledContainerResolvesSelf(): void
    {
        $container = new ArgonContainer();

        $compiled = $this->compileAndLoadContainer($container, 'testCompiledContainerResolvesSelf');

        $this->assertInstanceOf(ArgonContainer::class, $compiled->get(ArgonContainer::class));
        $this->assertSame($compiled, $compiled->get(ArgonContainer::class));
    }

    /**
     * @throws NotFoundException
     * @throws ReflectionException
     * @throws ContainerException
     */
    public function testDoesNotResolveNullableServiceIfNotBound(): void
    {
        $container = new ArgonContainer();
        $container->set(WithOptionalService::class);

        $compiled = $this->compileAndLoadContainer($container, 'testDoesNotResolveNullableServiceIfNotBound');

        $instance = $compiled->get(WithOptionalService::class);

        $this->assertNull($instance->logger);
    }

    /**
     * @throws NotFoundException
     * @throws ReflectionException
     * @throws ContainerException
     */
    public function testDoesNotResolveNullableInterfaceIfNotBound(): void
    {
        $container = new ArgonContainer();
        $container->set(WithOptionalInterface::class);

        $compiled = $this->compileAndLoadContainer($container, 'testDoesNotResolveNullableInterfaceIfNotBound');

        $instance = $compiled->get(WithOptionalInterface::class);

        $this->assertNull($instance->logger);
    }

    /**
     * @throws NotFoundException
     * @throws ReflectionException
     * @throws ContainerException
     */
    public function testCompiledInjectsOptionalServiceWhenBound(): void
    {
        $container = new ArgonContainer();
        $container->set(Logger::class);
        $container->set(WithOptionalService::class, args: [
            'logger' => Logger::class,
        ]);

        $compiled = $this->compileAndLoadContainer($container, 'testCompiledInjectsOptionalServiceWhenBound');

        $instance = $compiled->get(WithOptionalService::class);

        $this->assertInstanceOf(Logger::class, $instance->logger);
    }

    /**
     * @throws NotFoundException
     * @throws ReflectionException
     * @throws ContainerException
     */
    public function testCompiledInjectsOptionalServiceAtRuntime(): void
    {
        $container = new ArgonContainer();
        $container->set(WithOptionalService::class);

        $compiled = $this->compileAndLoadContainer($container, 'testCompiledInjectsOptionalServiceAtRuntime');

        $instance = $compiled->get(WithOptionalService::class, args: [
            'logger' => $compiled->get(Logger::class),
        ]);

        $this->assertInstanceOf(Logger::class, $instance->logger);
    }

    /**
     * @throws NotFoundException
     * @throws ReflectionException
     * @throws ContainerException
     */
    public function testCompiledClassStringRuntimeArgumentResolvesService(): void
    {
        $container = new ArgonContainer();
        $container->set(WithOptionalService::class);

        $compiled = $this->compileAndLoadContainer(
            $container,
            'testCompiledClassStringRuntimeArgumentResolvesService'
        );

        $instance = $compiled->get(WithOptionalService::class, args: [
            'logger' => Logger::class,
        ]);

        $this->assertInstanceOf(Logger::class, $instance->logger);
    }

    /**
     * @throws NotFoundException
     * @throws ReflectionException
     * @throws ContainerException
     */
    public function testCompiledInjectsOptionalInterfaceWhenBound(): void
    {
        $container = new ArgonContainer();
        $container->set(Logger::class);
        $container->set(WithOptionalInterface::class, args: [
            'logger' => Logger::class,
        ]);

        $compiled = $this->compileAndLoadContainer($container, 'testCompiledInjectsOptionalInterfaceWhenBound');

        $instance = $compiled->get(WithOptionalInterface::class);

        $this->assertInstanceOf(Logger::class, $instance->logger);
    }

    /**
     * @throws NotFoundException
     * @throws ReflectionException
     * @throws ContainerException
     */
    /**
     * @throws NotFoundException
     * @throws ReflectionException
     * @throws ContainerException
     */
    public function testNullableDependencyWithoutDefaultGetsNull(): void
    {
        $container = new ArgonContainer();
        $container->set(ImplicitNullable::class);

        $compiled = $this->compileAndLoadContainer($container, 'testNullableDependencyWithoutDefaultGetsNull');

        $instance = $compiled->get(ImplicitNullable::class);

        $this->assertNull($instance->logger, 'Expected nullable LoggerInterface to resolve to null');
    }

    /**
     * @throws ContainerException
     * @throws NotFoundException
     * @throws ReflectionException
     */
    public function testCompiledContainerResolvesWithPrimitiveOverrides(): void
    {
        $container = new ArgonContainer();

        // Bind the service
        $container->set(TestServiceWithMultipleParams::class, args: [
            'param1' => 'compiled-override',
            'param2' => 99,
        ]);

        // Compile and load container
        $compiled = $this->compileAndLoadContainer($container, 'testCompiledContainerResolvesWithPrimitiveOverrides');

        // Resolve and assert
        $service = $compiled->get(TestServiceWithMultipleParams::class);

        $this->assertEquals('compiled-override', $service->getParam1());
        $this->assertEquals(99, $service->getParam2());
    }

    /**
     * @throws ContainerException
     * @throws NotFoundException
     * @throws ReflectionException
     */
    public function testCompiledContainerPreservesTags(): void
    {
        $container = new ArgonContainer();

        $container->set(Logger::class);
        $container->set(Mailer::class);

        // Tag the services
        $container->tag(Logger::class, ['loggers']);
        $container->tag(Mailer::class, ['mailers', 'loggers']);

        // Compile
        $compiled = $this->compileAndLoadContainer($container, 'testCompiledContainerPreservesTags');

        // Check tagged services
        $loggers = $compiled->getTagged('loggers');
        $mailers = $compiled->getTagged('mailers');

        $this->assertCount(2, $loggers);
        $this->assertCount(1, $mailers);

        $this->assertInstanceOf(Logger::class, $loggers[0]);
        $this->assertInstanceOf(Mailer::class, $loggers[1]);
        $this->assertInstanceOf(Mailer::class, $mailers[0]);
    }

    /**
     * @throws ContainerException
     * @throws NotFoundException
     * @throws ReflectionException
     */
    public function testTaggedServicesWithMetadata(): void
    {
        $container = new ArgonContainer();

        $container->set(Logger::class);
        $container->set(Mailer::class);

        // Tag using new metadata format
        $container->tag(Logger::class, ['loggers' => ['priority' => 100]]);
        $container->tag(Mailer::class, [
            'loggers' => ['priority' => 50],
            'mailers' => ['priority' => 10, 'group' => 'email']
        ]);

        // Verify pre-compile metadata
        $tags = $container->getTags(true);

        $this->assertArrayHasKey('loggers', $tags);
        $this->assertArrayHasKey('mailers', $tags);
        $this->assertArrayHasKey(Logger::class, $tags['loggers']);
        $this->assertSame(['priority' => 100], $tags['loggers'][Logger::class]);
        $this->assertSame(['priority' => 50], $tags['loggers'][Mailer::class]);
        $this->assertSame(['priority' => 10, 'group' => 'email'], $tags['mailers'][Mailer::class]);

        $loggersMeta = $container->getTaggedMeta('loggers');
        $mailersMeta = $container->getTaggedMeta('mailers');

        $this->assertSame(['priority' => 100], $loggersMeta[Logger::class]);
        $this->assertSame(['priority' => 50], $loggersMeta[Mailer::class]);
        $this->assertSame(['priority' => 10, 'group' => 'email'], $mailersMeta[Mailer::class]);

        // Compile
        $compiled = $this->compileAndLoadContainer($container, 'testTaggedServicesWithMetadata');

        // Post-compile: make sure instances still work
        $loggers = $compiled->getTagged('loggers');
        $mailers = $compiled->getTagged('mailers');

        $this->assertCount(2, $loggers);
        $this->assertCount(1, $mailers);

        $this->assertInstanceOf(Logger::class, $loggers[0]);
        $this->assertInstanceOf(Mailer::class, $loggers[1]);
        $this->assertInstanceOf(Mailer::class, $mailers[0]);

        // Metadata after compilation
        $loggersMeta = $compiled->getTaggedMeta('loggers');
        $mailersMeta = $compiled->getTaggedMeta('mailers');

        $this->assertSame(['priority' => 100], $loggersMeta[Logger::class]);
        $this->assertSame(['priority' => 50], $loggersMeta[Mailer::class]);
        $this->assertSame(['priority' => 10, 'group' => 'email'], $mailersMeta[Mailer::class]);
    }

    /**
     * @throws ContainerException
     * @throws NotFoundException
     * @throws ReflectionException
     */
    public function testCompiledContainerResolvesUnregisteredConcreteDependencies(): void
    {
        $container = new ArgonContainer();
        $container->set(DeepGraph::class);

        $compiled = $this->compileAndLoadContainer(
            $container,
            'testCompiledContainerResolvesUnregisteredConcreteDependencies'
        );

        $resolved = $compiled->get(DeepGraph::class);

        $this->assertInstanceOf(DeepGraph::class, $resolved);
        $this->assertInstanceOf(MidLevel::class, $resolved->mid);
        $this->assertInstanceOf(AutowireLogger::class, $resolved->mid->logger);
    }

    /**
     * @throws ContainerException
     * @throws NotFoundException
     * @throws ReflectionException
     */
    public function testCompiledInvokeResolvesUnregisteredConcreteParameters(): void
    {
        $container = new ArgonContainer();

        $compiled = $this->compileAndLoadContainer(
            $container,
            'testCompiledInvokeResolvesUnregisteredConcreteParameters'
        );

        $result = $compiled->invoke([ServiceWithDependency::class, 'doSomething']);

        $this->assertSame('from-invoker', $result);
    }

    /**
     * @throws ContainerException
     * @throws NotFoundException
     * @throws ReflectionException
     */
    public function testCompiledServiceInvokerMatchesRuntimeForRouteStyleInvocation(): void
    {
        $runtime = new ArgonContainer();
        $runtime->set(Logger::class);
        $runtime->set(RouteStyleController::class)
            ->defineInvocation(
                'show',
                ReflectionUtils::getMethodParameters(RouteStyleController::class, 'show')
            );

        $compiled = $this->compileAndLoadContainer(
            $runtime,
            'testCompiledServiceInvokerMatchesRuntimeForRouteStyleInvocation'
        );

        $arguments = ['id' => '42'];
        $runtimeResult = (new ServiceInvoker($runtime, RouteStyleController::class, 'show'))($arguments);
        $compiledResult = (new ServiceInvoker($compiled, RouteStyleController::class, 'show'))($arguments);

        $this->assertSame($runtimeResult, $compiledResult);
        $this->assertSame([
            'id' => '42',
            'log' => 'route-hit',
        ], $compiledResult);
    }

    /**
     * @throws ContainerException
     * @throws NotFoundException
     * @throws ReflectionException
     */
    public function testCompiledServiceInvokerMatchesRuntimeForRouteStylePrimitiveCasting(): void
    {
        $runtime = new ArgonContainer();
        $runtime->set(Logger::class);
        $runtime->set(RouteStyleController::class)
            ->defineInvocation(
                'typed',
                ReflectionUtils::getMethodParameters(RouteStyleController::class, 'typed')
            );

        $compiled = $this->compileAndLoadContainer(
            $runtime,
            'testCompiledServiceInvokerMatchesRuntimeForRouteStylePrimitiveCasting'
        );

        $arguments = ['id' => '42'];
        $runtimeResult = (new ServiceInvoker($runtime, RouteStyleController::class, 'typed'))($arguments);
        $compiledResult = (new ServiceInvoker($compiled, RouteStyleController::class, 'typed'))($arguments);

        $this->assertSame($runtimeResult, $compiledResult);
        $this->assertSame([
            'id' => 42,
            'ratio' => 1.5,
            'log' => 'typed-route-hit',
        ], $compiledResult);
    }


    /**
     * @throws ContainerException
     * @throws ReflectionException
     * @throws NotFoundException
     */
    public function testCompiledContainerAppliesPostInterceptor(): void
    {
        $container = new ArgonContainer();
        $container->set(Logger::class);
        $container->registerInterceptor(LoggerInterceptor::class);

        $compiled = $this->compileAndLoadContainer($container, 'testCompiledContainerAppliesPostInterceptor');

        $logger = $compiled->get(Logger::class);

        $this->assertInstanceOf(Logger::class, $logger);
        $this->assertTrue($logger->intercepted, 'Logger instance should be intercepted.');
    }

    /**
     * @throws ContainerException
     * @throws NotFoundException
     * @throws ReflectionException
     */
    public function testCompiledContainerRunsPostInterceptorOnlyWhenSharedServiceIsCreated(): void
    {
        InterceptedClass::reset();

        $container = new ArgonContainer();
        $container->set(InterceptedClass::class);
        $container->registerInterceptor(PostHook::class);

        $compiled = $this->compileAndLoadContainer(
            $container,
            'testCompiledContainerRunsPostInterceptorOnlyWhenSharedServiceIsCreated'
        );

        $first = $compiled->get(InterceptedClass::class);
        $second = $compiled->get(InterceptedClass::class);

        $this->assertSame($first, $second);
        $this->assertSame(1, InterceptedClass::$validatedCalls);
    }

    /**
     * @throws ContainerException
     * @throws NotFoundException
     * @throws ReflectionException
     */
    public function testCompiledFallbackDoesNotRunPostInterceptorTwice(): void
    {
        InterceptedClass::reset();

        $container = new ArgonContainer();
        $container->registerInterceptor(PostHook::class);

        $compiled = $this->compileAndLoadContainer(
            $container,
            'testCompiledFallbackDoesNotRunPostInterceptorTwice'
        );

        $compiled->get(InterceptedClass::class);

        $this->assertSame(1, InterceptedClass::$validatedCalls);
    }

    /**
     * @throws ContainerException
     * @throws NotFoundException
     * @throws ReflectionException
     */
    public function testCompiledInterceptorsResolveDependenciesViaContainer(): void
    {
        $container = new ArgonContainer();
        $container->set(Logger::class);
        $container->set(CustomLogger::class);

        $container->registerInterceptor(DependentLoggerInterceptor::class);

        $compiled = $this->compileAndLoadContainer(
            $container,
            'testCompiledInterceptorsResolveDependenciesViaContainer'
        );

        $logger = $compiled->get(Logger::class);

        $this->assertSame('[custom] interceptor', $logger->note);
        $this->assertTrue($logger->intercepted);
    }

    /**
     * @throws ContainerException
     * @throws NotFoundException
     * @throws ReflectionException
     */
    public function testStrictCompiledContainerDisallowsUnregisteredServices(): void
    {
        $container = new ArgonContainer(strictMode: true);

        $compiled = $this->compileAndLoadContainer($container, 'StrictDisallowsAutowiring', strictMode: true);

        $this->expectException(NotFoundException::class);
        $compiled->get(Logger::class);
    }

    /**
     * @throws ContainerException
     * @throws NotFoundException
     * @throws ReflectionException
     */
    public function testCompiledContainerMirrorsRuntimeStrictModeWhenStrictModeIsOmitted(): void
    {
        $container = new ArgonContainer(strictMode: true);

        $compiled = $this->compileAndLoadContainer($container, 'StrictMirrorsRuntime');

        $this->expectException(NotFoundException::class);
        $compiled->get(Logger::class);
    }

    /**
     * @throws ContainerException
     * @throws NotFoundException
     * @throws ReflectionException
     */
    public function testCompiledContainerCanExplicitlyDisableStrictMode(): void
    {
        $container = new ArgonContainer(strictMode: true);

        $compiled = $this->compileAndLoadContainer($container, 'ExplicitLenientFromStrict', strictMode: false);

        $this->assertInstanceOf(Logger::class, $compiled->get(Logger::class));
    }

    /**
     * @throws ContainerException
     * @throws NotFoundException
     * @throws ReflectionException
     */
    public function testStrictCompiledContainerResolvesRegisteredServices(): void
    {
        $container = new ArgonContainer(strictMode: true);
        $container->set(Logger::class);

        $compiled = $this->compileAndLoadContainer($container, 'StrictResolvesRegistered', strictMode: true);

        $this->assertInstanceOf(Logger::class, $compiled->get(Logger::class));
    }

    /**
     * @throws ContainerException
     * @throws NotFoundException
     * @throws ReflectionException
     */
    public function testStrictCompiledInvokeThrowsForMissingDependency(): void
    {
        $container = new ArgonContainer(strictMode: true);

        $compiled = $this->compileAndLoadContainer($container, 'StrictInvokeFails', strictMode: true);

        $this->expectException(NotFoundException::class);
        $compiled->invoke([ServiceWithDependency::class, 'doSomething']);
    }

    /**
     * @throws ContainerException
     * @throws NotFoundException
     * @throws ReflectionException
     */
    public function testCompiledContainerInjectsPrimitiveArguments(): void
    {
        $container = new ArgonContainer();
        $container->set(PrimitiveService::class, args: [
            'path' => '/tmp/profiles',
        ]);

        $compiled = $this->compileAndLoadContainer($container, 'PrimitiveServiceCompiled');

        $service = $compiled->get(PrimitiveService::class);

        $this->assertSame('/tmp/profiles', $service->path);
    }

    /**
     * @throws ContainerException
     * @throws NotFoundException
     * @throws ReflectionException
     */
    public function testCompiledSharedServiceRejectsRuntimeArgumentsAfterFirstResolution(): void
    {
        $container = new ArgonContainer();
        $container->set(PrimitiveService::class);

        $compiled = $this->compileAndLoadContainer(
            $container,
            'testCompiledSharedServiceRejectsRuntimeArgumentsAfterFirstResolution'
        );

        $service = $compiled->get(PrimitiveService::class, [
            'path' => '/tmp/first',
        ]);

        $this->assertSame($service, $compiled->get(PrimitiveService::class));
        $this->assertSame('/tmp/first', $service->path);

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Cannot pass runtime arguments to an already resolved shared service.');

        $compiled->get(PrimitiveService::class, [
            'path' => '/tmp/second',
        ]);
    }

    /**
     * @throws ContainerException
     * @throws NotFoundException
     * @throws ReflectionException
     */
    public function testPreInterceptorModifiesParameters(): void
    {
        $container = new ArgonContainer();

        $container->registerInterceptor(PreArgOverride::class);

        $compiled = $this->compileAndLoadContainer($container, 'testPreInterceptorModifiesParameters');

        $instance = $compiled->get(SimpleService::class);

        $this->assertSame('from-interceptor', $instance->value);
    }

    /**
     * @throws ContainerException
     * @throws NotFoundException
     * @throws ReflectionException
     */
    public function testCompiledContainerIncludesServiceProviders(): void
    {
        $container = new ArgonContainer();
        $container->register(DummyProvider::class);

        $compiled = $this->compileAndLoadContainer($container, 'testCompiledContainerIncludesServiceProviders');

        // Ensure service provider is still tagged
        $providers = $compiled->getTagged('service.provider');
        $this->assertNotEmpty($providers, 'Expected at least one tagged service provider');

        $this->assertInstanceOf(DummyProvider::class, $providers[0]);

        // Ensure the service registered by the provider is present
        $this->assertTrue($compiled->has('dummy.service'));
        $this->assertInstanceOf(stdClass::class, $compiled->get('dummy.service'));
    }

    /**
     * @throws ReflectionException
     * @throws ContainerException
     */
    public function testCompileHandlesDefaultParameterValues(): void
    {
        $container = new ArgonContainer();
        $container->set(DefaultValueService::class);

        $className = 'testCompileHandlesDefaultParameterValues';
        $outputPath = self::compilerCacheFile($className);
        $namespace = 'Tests\\Integration\\Compiler';

        $compiler = new ContainerCompiler($container);
        $compiler->compile($outputPath, $className, $namespace);

        $this->assertFileExists($outputPath);

        $contents = file_get_contents($outputPath);
        $this->assertNotFalse($contents);
        $this->assertStringContainsString("'default-val'", $contents);
    }

    /**
     * @throws ContainerException
     * @throws NotFoundException
     * @throws ReflectionException
     */
    public function testContextualBindingIsHardcoded(): void
    {
        $container = new ArgonContainer();

        // Contextual binding: NeedsLogger gets CustomLogger instead of Logger
        $container->set(NeedsLogger::class);

        $container->for(NeedsLogger::class)->set(LoggerInterface::class, CustomLogger::class);

        $compiled = $this->compileAndLoadContainer($container, 'CompiledContainerWithContextual');

        $needsLogger = $compiled->get(NeedsLogger::class);

        $this->assertInstanceOf(NeedsLogger::class, $needsLogger);
        $this->assertInstanceOf(CustomLogger::class, $needsLogger->logger);
    }

    /**
     * @throws ContainerException
     * @throws NotFoundException
     * @throws ReflectionException
     */
    public function testCompiledContainerInjectsParameterStoreValues(): void
    {
        $container = new ArgonContainer();

        // Set a parameter directly into the store
        $container->getParameters()->set('config.value', 'compiled-store');

        // Bind the service that depends on the parameter
        $container->set(SimpleService::class);

        // Compile and load the container
        $compiled = $this->compileAndLoadContainer($container, 'testCompiledContainerInjectsParameterStoreValues');

        // Grab the instance from the compiled container
        $instance = $compiled->get(SimpleService::class, [
            'value' => $compiled->getParameters()->get('config.value')
        ]);

        $this->assertInstanceOf(SimpleService::class, $instance);
        $this->assertSame('compiled-store', $instance->value, 'Parameter store value should be injected correctly.');
    }

    /**
     * @throws ReflectionException
     * @throws ContainerException
     */
    public function testInvokeServiceMethodInvokesCompiledMethod(): void
    {
        $container = new ArgonContainer();
        $container->set(Logger::class)
            ->defineInvocation('log', ['msg' => 'hello']);

        $compiled = $this->compileAndLoadContainer($container, 'testInvokeServiceMethodInvokesCompiledMethod');

        $refMethod = new ReflectionMethod($compiled, 'invokeServiceMethod');

        $result = $refMethod->invoke($compiled, Logger::class, 'log', ['msg' => 'hello']);

        $this->assertSame('hello', $result);
        $this->assertTrue(method_exists($compiled, 'invoke_' . str_replace('\\', '_', Logger::class) . '__log'));
    }

    /**
     * @throws ReflectionException
     * @throws ContainerException
     * @throws NotFoundException
     */
    public function testCompileHandlesFactoryBinding(): void
    {
        $container = new ArgonContainer();

        $container->set(Logger::class);
        $container->set(Mailer::class)->factory(MailerFactory::class, 'create');

        $compiled = $this->compileAndLoadContainer($container, 'testCompileHandlesFactoryBinding');

        $mailer = $compiled->get(Mailer::class);

        $this->assertInstanceOf(Mailer::class, $mailer);
        $this->assertInstanceOf(Logger::class, $mailer->logger);
    }

    /**
     * @throws ReflectionException
     * @throws ContainerException
     * @throws NotFoundException
     */
    public function testCompileHandlesStaticFactoryBinding(): void
    {
        $container = new ArgonContainer();

        $container->set(Foo::class)->factory(StaticFooFactory::class, 'createStatic');

        $compiled = $this->compileAndLoadContainer($container, 'testCompileHandlesStaticFactoryBinding');

        $foo = $compiled->get(Foo::class);

        $this->assertInstanceOf(Foo::class, $foo);
        $this->assertSame('from-static-method', $foo->label);
    }

    /**
     * @throws ReflectionException
     * @throws ContainerException
     * @throws NotFoundException
     */
    public function testCompiledFactoryMethodParametersUseContainerResolution(): void
    {
        $container = new ArgonContainer();

        $container->set(Logger::class);
        $container->set(Mailer::class)->factory(MailerFactory::class, 'createWithLogger');

        $compiled = $this->compileAndLoadContainer(
            $container,
            'testCompiledFactoryMethodParametersUseContainerResolution'
        );

        $mailer = $compiled->get(Mailer::class);

        $this->assertInstanceOf(Mailer::class, $mailer);
        $this->assertInstanceOf(Logger::class, $mailer->logger);
    }

    /**
     * @throws ContainerException
     * @throws ReflectionException
     * @throws NotFoundException
     */
    public function testCompiledFactoryUsesDefaultValue(): void
    {
        $container = new ArgonContainer();
        $container->set(DefaultValueService::class)
            ->factory(MailerFactory::class, 'createWithDefault');

        $compiled = $this->compileAndLoadContainer($container, 'testCompiledFactoryUsesDefaultValue');

        $service = $compiled->get(DefaultValueService::class);

        $this->assertInstanceOf(DefaultValueService::class, $service);
        $this->assertSame('default-label', $service->label);
    }

    /**
     * @throws ContainerException
     * @throws ReflectionException
     * @throws NotFoundException
     */
    public function testCompiledFactoryHonorsTransientLifecycle(): void
    {
        $container = new ArgonContainer();
        $container->set(DefaultValueService::class)
            ->factory(MailerFactory::class, 'createWithDefault')
            ->transient();

        $compiled = $this->compileAndLoadContainer($container, 'testCompiledFactoryHonorsTransientLifecycle');

        $first = $compiled->get(DefaultValueService::class);
        $second = $compiled->get(DefaultValueService::class);

        $this->assertNotSame($first, $second);
    }

    /**
     * @throws ContainerException
     * @throws ReflectionException
     * @throws NotFoundException
     */
    public function testCompiledFactoryAllowsRuntimeArguments(): void
    {
        $container = new ArgonContainer();

        $container->set(DefaultValueService::class)
            ->factory(MailerFactory::class, 'createWithRequired');

        $compiled = $this->compileAndLoadContainer($container, 'testCompiledFactoryAllowsRuntimeArguments');

        // Provide required arg at runtime
        $instance = $compiled->get(DefaultValueService::class, ['label' => 'runtime-supplied']);

        $this->assertInstanceOf(DefaultValueService::class, $instance);
        $this->assertSame('runtime-supplied', $instance->label);
    }

    /**
     * @throws ContainerException
     * @throws ReflectionException
     * @throws NotFoundException
     */
    public function testCompiledFactoryRuntimeArgumentsDoNotConfigureFactoryObject(): void
    {
        $container = new ArgonContainer();
        $container->set(StatefulDefaultValueFactory::class, args: ['label' => 'factory-config']);
        $container->set(DefaultValueService::class)
            ->factory(StatefulDefaultValueFactory::class, 'create');

        $compiled = $this->compileAndLoadContainer(
            $container,
            'testCompiledFactoryRuntimeArgumentsDoNotConfigureFactoryObject'
        );

        $service = $compiled->get(DefaultValueService::class, ['label' => 'product-runtime']);
        $factory = $compiled->get(StatefulDefaultValueFactory::class);

        $this->assertSame('factory-config:product-runtime', $service->label);
        $this->assertInstanceOf(StatefulDefaultValueFactory::class, $factory);
    }

    /**
     * @throws ContainerException
     * @throws ReflectionException
     * @throws NotFoundException
     */
    public function testCompiledSharedFactoryRejectsRuntimeArgumentsAfterFirstResolution(): void
    {
        $container = new ArgonContainer();
        $container->set(DefaultValueService::class)
            ->factory(MailerFactory::class, 'createWithRequired');

        $compiled = $this->compileAndLoadContainer(
            $container,
            'testCompiledSharedFactoryRejectsRuntimeArgumentsAfterFirstResolution'
        );

        $service = $compiled->get(DefaultValueService::class, ['label' => 'first']);

        $this->assertSame($service, $compiled->get(DefaultValueService::class));
        $this->assertSame('first', $service->label);

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Cannot pass runtime arguments to an already resolved shared service.');

        $compiled->get(DefaultValueService::class, ['label' => 'second']);
    }

    /**
     * @throws ReflectionException
     * @throws ContainerException
     * @throws NotFoundException
     */
    public function testCompiledFactoryThrowsIfRequiredArgumentMissing(): void
    {
        $container = new ArgonContainer();
        $container->set(DefaultValueService::class)
            ->factory(MailerFactory::class, 'createWithRequired');

        $compiled = $this->compileAndLoadContainer(
            $container,
            'testCompiledFactoryThrowsIfRequiredArgumentMissing'
        );

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage("Missing required argument 'label'");

        $compiled->get(DefaultValueService::class);
    }

    /**
     * @throws ReflectionException
     * @throws ContainerException
     * @throws NotFoundException
     */
    public function testCompiledFactoryReturningNonObjectThrowsContainerException(): void
    {
        $container = new ArgonContainer();
        $container->set(DefaultValueService::class)
            ->factory(MailerFactory::class, 'createString');

        $compiled = $this->compileAndLoadContainer(
            $container,
            'testCompiledFactoryReturningNonObjectThrowsContainerException'
        );

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Factory method "' . MailerFactory::class . '::createString()"');
        $this->expectExceptionMessage('must return an object, got string');

        $compiled->get(DefaultValueService::class);
    }

    /**
     * @throws ReflectionException
     * @throws ContainerException
     */
    public function testGenerateServiceMethodInvokerInjectsClassFromAtSymbol(): void
    {
        $container = new ArgonContainer();

        $container->set(Logger::class);
        $container->set(ServiceWithDependency::class)
            ->defineInvocation('doSomething', [
                'logger' => '@' . Logger::class,
            ]);

        $compiled = $this->compileAndLoadContainer(
            $container,
            'testGenerateServiceMethodInvokerInjectsClassFromAtSymbol'
        );

        // The method should be compiled to: invoke_ServiceWithDependency__doSomething
        $methodName = 'invoke_' . str_replace('\\', '_', ServiceWithDependency::class) . '__doSomething';

        $this->assertTrue(method_exists($compiled, $methodName), "Expected compiled method {$methodName} to exist");

        $result = $compiled->{$methodName}([]);
        $this->assertSame('from-invoker', $result);
    }

    /**
     * @throws ReflectionException
     * @throws ContainerException
     */
    public function testCompileFailsWhenClosureIsNotIgnored(): void
    {
        $container = new \Maduser\Argon\Container\ArgonContainer();

        $container->set('some.closure', fn () => new \stdClass());

        $output = self::compilerCacheFile('testCompileFailsWhenClosureIsNotIgnored');
        $compiler = new \Maduser\Argon\Container\Compiler\ContainerCompiler($container);

        try {
            $compiler->compile(
                $output,
                'testCompileFailsWhenClosureIsNotIgnored',
                'Tests\\Integration\\Compiler'
            );
            $this->fail('Expected closure binding validation to fail.');
        } catch (ContainerException $exception) {
            $this->assertStringContainsString(
                'Cannot compile a container with closures: [some.closure]. ' .
                'Use skipCompilation() to exclude it, or register the closure during boot/runtime after compilation.',
                $exception->getMessage()
            );
            $this->assertFileDoesNotExist($output);
        }
    }

    /**
     * @throws ReflectionException
     */
    public function testCompilerValidatesFactoryMethodBeforeWritingFile(): void
    {
        $container = new ArgonContainer();
        $container->set(DefaultValueService::class)->factory(MailerFactory::class, 'missingMethod');

        $output = self::compilerCacheFile('testCompilerValidatesFactoryMethodBeforeWritingFile');
        $compiler = new ContainerCompiler($container);

        try {
            $compiler->compile(
                $output,
                'testCompilerValidatesFactoryMethodBeforeWritingFile',
                'Tests\\Integration\\Compiler'
            );
            $this->fail('Expected factory method validation to fail.');
        } catch (ContainerException $exception) {
            $this->assertStringContainsString(
                'Factory method "missingMethod" not found on class "' . MailerFactory::class . '".',
                $exception->getMessage()
            );
            $this->assertFileDoesNotExist($output);
        }
    }

    /**
     * @throws ReflectionException
     */
    public function testCompilerValidatesInvocationMethodBeforeWritingFile(): void
    {
        $container = new ArgonContainer();
        $container->set(RouteStyleController::class)->defineInvocation('missingMethod', []);

        $output = self::compilerCacheFile('testCompilerValidatesInvocationMethodBeforeWritingFile');
        $compiler = new ContainerCompiler($container);

        try {
            $compiler->compile(
                $output,
                'testCompilerValidatesInvocationMethodBeforeWritingFile',
                'Tests\\Integration\\Compiler'
            );
            $this->fail('Expected invocation method validation to fail.');
        } catch (ContainerException $exception) {
            $this->assertStringContainsString(
                'Invocation method "missingMethod" not found on class "' . RouteStyleController::class . '".',
                $exception->getMessage()
            );
            $this->assertFileDoesNotExist($output);
        }
    }

    /**
     * @throws ReflectionException
     * @throws ContainerException
     */
    public function testCompilerSkipsClosureWhenMarkedSkipCompilation(): void
    {
        $descriptor = $this->createConfiguredMock(ServiceDescriptorInterface::class, [
            'getId' => 'ClosureService',
            'getConcrete' => fn() => new \stdClass(),
            'shouldCompile' => false, // <- user opted out
        ]);

        $container = $this->createConfiguredMock(ArgonContainer::class, [
            'getBindings' => ['ClosureService' => $descriptor],
            'getTags' => [],
            'getPostInterceptors' => [],
        ]);

        $compiler = new ContainerCompiler($container);
        $output = self::compilerCacheFile('TestClosureSkip');

        $compiler->compile($output, 'TestClosureSkip');

        $compiled = file_get_contents($output);
        $this->assertNotFalse($compiled);
        $this->assertStringNotContainsString('ClosureService', $compiled);
    }

    /**
     * @throws ReflectionException
     */
    public function testCompilerThrowsForNonInstantiableClass(): void
    {
        $descriptor = $this->createConfiguredMock(ServiceDescriptorInterface::class, [
            'getId' => SomeInterface::class,
            'getConcrete' => SomeInterface::class,
            'shouldCompile' => true,
            'hasFactory' => false,
        ]);

        $container = $this->createConfiguredMock(ArgonContainer::class, [
            'getBindings' => [SomeInterface::class => $descriptor],
            'getTags' => [],
            'getPostInterceptors' => [],
        ]);

        $compiler = new ContainerCompiler($container);
        $output = self::compilerCacheFile('Boom');

        try {
            $compiler->compile($output, 'Boom');
            $this->fail('Expected non-instantiable service validation to fail.');
        } catch (ContainerException $exception) {
            $this->assertMatchesRegularExpression('/non-instantiable class/', $exception->getMessage());
            $this->assertFileDoesNotExist($output);
        }
    }
}
