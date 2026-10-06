<?php

declare(strict_types=1);

namespace Tests\Unit\Container\Support;

use DomainException;
use Maduser\Argon\Container\Support\CompiledCallableInvoker;
use PHPUnit\Framework\TestCase;
use ReflectionFunction;
use stdClass;
use TypeError;

final class CompiledCallableInvokerTest extends TestCase
{
    public function testNativeCoercionPreservesResolvedObjectsAndDefaults(): void
    {
        $dependency = new stdClass();
        $target = static fn(
            int $id,
            float $ratio,
            string $label,
            bool $enabled,
            stdClass $dependency,
            ?int $optional = null
        ): array => [$id, $ratio, $label, $enabled, $dependency, $optional];

        self::assertSame([42, 3.5, '12', true, $dependency, null], CompiledCallableInvoker::invoke($target, [
            'id' => '42',
            'ratio' => '3.5',
            'label' => 12,
            'enabled' => 1,
            'dependency' => $dependency,
        ]));
    }

    public function testExplicitNullIsPreserved(): void
    {
        self::assertNull(CompiledCallableInvoker::invoke(static fn(?int $value = 7): ?int => $value, [null]));
    }

    public function testNanCannotBeCoercedToInteger(): void
    {
        $target = static fn(int $value): int => $value;
        try {
            (new ReflectionFunction($target))->invokeArgs([NAN]);
            self::fail('Reflection must reject NAN for an integer parameter.');
        } catch (TypeError) {
            $this->expectException(TypeError::class);
        }
        CompiledCallableInvoker::invoke($target, [NAN]);
    }

    public function testCallsInsideTheTargetRemainStrict(): void
    {
        $target = static fn(callable $inner, mixed $value): mixed => $inner($value);

        $this->expectException(TypeError::class);
        CompiledCallableInvoker::invoke($target, [
            'inner' => static fn(int $value): int => $value,
            'value' => '42',
        ]);
    }

    public function testApplicationExceptionIsNotWrapped(): void
    {
        $exception = new DomainException('application failure');
        try {
            CompiledCallableInvoker::invoke(static fn(): never => throw $exception, []);
            self::fail('Expected the application exception.');
        } catch (DomainException $caught) {
            self::assertSame($exception, $caught);
        }
    }

    public function testLossyNumericConversionsMatchReflectionIncludingDeprecations(): void
    {
        $target = static fn(int $value): int => $value;
        foreach ([1.5, '1.5'] as $value) {
            $warnings = [];
            set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
                $warnings[] = [$severity, $message];
                return true;
            });
            try {
                $expected = (new ReflectionFunction($target))->invokeArgs([$value]);
                $runtimeWarnings = $warnings;
                $warnings = [];
                self::assertSame($expected, CompiledCallableInvoker::invoke($target, [$value]));
                self::assertSame($runtimeWarnings, $warnings);
                self::assertNotEmpty($warnings);
            } finally {
                restore_error_handler();
            }
        }
    }
}
