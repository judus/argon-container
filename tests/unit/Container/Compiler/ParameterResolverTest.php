<?php

declare(strict_types=1);

namespace Tests\Unit\Container\Compiler;

use Maduser\Argon\Container\ArgonContainer;
use Maduser\Argon\Container\Compiler\ParameterExpressionResolver;
use Maduser\Argon\Container\Support\ArgumentResolutionPlan;
use Maduser\Argon\Container\Support\ArgumentResolutionStep;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use Tests\Integration\Mocks\Logger;
use Tests\Mocks\LoggerInterface;

final class ParameterResolverTest extends TestCase
{
    public function testAliasCycleProducesResolutionFailureExpression(): void
    {
        $container = new ArgonContainer();
        $container->set(\Tests\Integration\Compiler\Mocks\AliasBase::class, Logger::class);
        $container->set(Logger::class, \Tests\Integration\Compiler\Mocks\AliasBase::class);
        $code = $this->makeResolver($container)->resolveParameter(
            $this->makeParameterMock('id'),
            Logger::class
        );
        self::assertStringStartsWith('throw ContainerException::forCircularDependency(', $code);
        self::assertStringContainsString(var_export(Logger::class, true), $code);
    }

    public function testReturnsArgumentAccessWhenNoFallbacks(): void
    {
        $param = $this->makeParameterMock('id', allowsNull: false, hasDefault: false);
        $resolver = $this->makeResolver();

        $code = $resolver->resolveParameter($param, 'SomeService');
        $this->assertSame(
            "array_key_exists('id', \$args) ? \$args['id'] : " .
            "throw ContainerException::fromServiceId('SomeService', 'Missing required argument \\'id\\'')",
            $code
        );
    }

    public function testReturnsNullFallbackIfAllowedAndNothingElse(): void
    {
        $param = $this->makeParameterMock('logger', allowsNull: true, hasDefault: false);
        $resolver = $this->makeResolver();

        $code = $resolver->resolveParameter($param, 'NoBindings');
        $this->assertSame("array_key_exists('logger', \$args) ? \$args['logger'] : null", $code);
    }

    public function testUsesContextualBindingIfAvailable(): void
    {
        $param = $this->makeParameterMock('service', LoggerInterface::class, isBuiltin: false);
        $container = new ArgonContainer();
        $container->for('SomeService')->set(LoggerInterface::class, Logger::class);
        $resolver = $this->makeResolver($container);

        $code = $resolver->resolveParameter($param, 'SomeService');
        $this->assertSame(
            "array_key_exists('service', \$args) ? " .
            "(is_string(\$args['service']) && is_a(\$args['service'], " .
            var_export(LoggerInterface::class, true) .
            ", true) ? " .
            "\$this->get(\$args['service']) : \$args['service']) : " .
            "\$this->get(" . var_export(Logger::class, true) . ")",
            $code
        );
    }

    public function testUsesObjectFallbackForNonNullableClass(): void
    {
        $param = $this->makeParameterMock('logger', Logger::class, isBuiltin: false);
        $resolver = $this->makeResolver();

        $code = $resolver->resolveParameter($param, 'AnyService');
        $this->assertSame(
            "array_key_exists('logger', \$args) ? " .
            "(is_string(\$args['logger']) && is_a(\$args['logger'], " .
            var_export(Logger::class, true) .
            ", true) ? " .
            "\$this->get(\$args['logger']) : \$args['logger']) : " .
            "\$this->get(" . var_export(Logger::class, true) . ")",
            $code
        );
    }

    public function testResolvesDescriptorArgumentValue(): void
    {
        $param = $this->makeParameterMock('foo', allowsNull: false, hasDefault: false);
        $container = new ArgonContainer();
        $container->set(Logger::class, args: ['foo' => 123]);
        $resolver = $this->makeResolver($container);

        $code = $resolver->resolveParameter($param, Logger::class);
        $this->assertSame("array_key_exists('foo', \$args) ? \$args['foo'] : 123", $code);
    }

    public function testResolvesDescriptorArgumentAsClass(): void
    {
        $param = $this->makeParameterMock('foo', Logger::class, isBuiltin: false);
        $container = new ArgonContainer();
        $container->set(Logger::class, args: ['foo' => Logger::class]);
        $resolver = $this->makeResolver($container);

        $code = $resolver->resolveParameter($param, Logger::class);
        $this->assertSame(
            "array_key_exists('foo', \$args) ? " .
            "(is_string(\$args['foo']) && is_a(\$args['foo'], " .
            var_export(Logger::class, true) .
            ", true) ? " .
            "\$this->get(\$args['foo']) : \$args['foo']) : " .
            "\$this->get(" . var_export(Logger::class, true) . ")",
            $code
        );
    }

    public function testPreservesExplicitNullDescriptorArgument(): void
    {
        $param = $this->makeParameterMock('foo', allowsNull: true);
        $container = new ArgonContainer();
        $container->set(Logger::class, args: ['foo' => null]);
        $resolver = $this->makeResolver($container);

        $code = $resolver->resolveParameter($param, Logger::class);
        $this->assertSame("array_key_exists('foo', \$args) ? \$args['foo'] : NULL", $code);
    }

    public function testReturnsDefaultValue(): void
    {
        $param = $this->makeParameterMock('foo', hasDefault: true, defaultValue: 'default');
        $resolver = $this->makeResolver();

        $code = $resolver->resolveParameter($param, 'ServiceId');
        $this->assertSame("array_key_exists('foo', \$args) ? \$args['foo'] : 'default'", $code);
    }

    public function testReturnsFailureForUnresolvableMixedParameter(): void
    {
        $param = $this->makeParameterMock('value', 'mixed');
        $resolver = $this->makeResolver();

        $code = $resolver->resolveParameter($param, 'MixedService');

        $this->assertSame(
            "array_key_exists('value', \$args) ? \$args['value'] : " .
            "throw ContainerException::fromServiceId('MixedService', " .
            "'Cannot resolve parameter \$value in MixedService::__construct(): " .
            "parameter is of type \\'mixed\\' with no default or nullability')",
            $code
        );
    }

    public function testContextualClosureBindingRendersCompilationFailure(): void
    {
        $param = $this->makeParameterMock('logger', LoggerInterface::class, isBuiltin: false);
        $container = new ArgonContainer();
        $container->for('SomeService')->set(LoggerInterface::class, fn(): Logger => new Logger());
        $resolver = $this->makeResolver($container);

        $code = $resolver->resolveParameter($param, 'SomeService');

        $this->assertSame(
            "array_key_exists('logger', \$args) ? " .
            "(is_string(\$args['logger']) && is_a(\$args['logger'], " .
            var_export(LoggerInterface::class, true) .
            ", true) ? " .
            "\$this->get(\$args['logger']) : \$args['logger']) : " .
            "throw ContainerException::fromServiceId('SomeService', " .
            "'Cannot compile contextual closure binding for parameter \\'logger\\'. " .
            "Use skipCompilation() to exclude it, " .
            "or register the closure during boot/runtime after compilation.')",
            $code
        );
    }

    public function testRenderStepFailsWhenPlanHasNoSteps(): void
    {
        $resolver = $this->makeResolver();
        $plan = $this->makePlan([]);

        $code = $this->invokeRenderStep($resolver, $plan, []);

        $this->assertSame(
            "throw ContainerException::fromServiceId('SomeService', 'Missing required argument \\'value\\'')",
            $code
        );
    }

    public function testRenderStepFailsWhenServiceStepMissesServiceId(): void
    {
        $resolver = $this->makeResolver();
        $plan = $this->makePlan([]);
        $step = $this->makeStep(ArgumentResolutionStep::SERVICE);

        $code = $this->invokeRenderStep($resolver, $plan, [$step]);

        $this->assertSame(
            "throw ContainerException::fromServiceId('SomeService', 'Service resolution step misses service id.')",
            $code
        );
    }

    // === Helpers ===

    private function makeResolver(?ArgonContainer $container = null): ParameterExpressionResolver
    {
        $container ??= new ArgonContainer();

        return new ParameterExpressionResolver($container, $container->getContextualBindings());
    }

    /**
     * @param list<ArgumentResolutionStep> $steps
     */
    private function makePlan(array $steps): ArgumentResolutionPlan
    {
        return new ArgumentResolutionPlan(
            'value',
            'SomeContext',
            'SomeService',
            'mixed',
            null,
            false,
            $steps
        );
    }

    /**
     * @param list<ArgumentResolutionStep> $steps
     */
    private function invokeRenderStep(
        ParameterExpressionResolver $resolver,
        ArgumentResolutionPlan $plan,
        array $steps
    ): string {
        $method = new \ReflectionMethod(ParameterExpressionResolver::class, 'renderStep');

        $result = $method->invoke($resolver, $plan, $steps, 0, '$args');
        self::assertIsString($result);

        return $result;
    }

    private function makeStep(string $kind): ArgumentResolutionStep
    {
        $reflection = new ReflectionClass(ArgumentResolutionStep::class);
        $step = $reflection->newInstanceWithoutConstructor();
        $constructor = $reflection->getConstructor();
        self::assertNotNull($constructor);
        $constructor->invoke($step, $kind);

        return $step;
    }

    private function makeParameterMock(
        string $name,
        string $typeName = 'string',
        bool $allowsNull = false,
        bool $isBuiltin = true,
        bool $hasDefault = false,
        mixed $defaultValue = null
    ): ReflectionParameter {
        $type = $this->createMock(ReflectionNamedType::class);
        $type->method('getName')->willReturn($typeName);
        $type->method('allowsNull')->willReturn($allowsNull);
        $type->method('isBuiltin')->willReturn($isBuiltin);

        $param = $this->createMock(ReflectionParameter::class);
        $param->method('getName')->willReturn($name);
        $param->method('getType')->willReturn($type);
        $param->method('getDeclaringClass')->willReturn(null);
        $param->method('allowsNull')->willReturn($allowsNull);
        $param->method('isDefaultValueAvailable')->willReturn($hasDefault);
        if ($hasDefault) {
            $param->method('getDefaultValue')->willReturn($defaultValue);
        }

        return $param;
    }
}
