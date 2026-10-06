<?php

// Match reflection's scalar argument coercion only at the final compiled call boundary.
declare(strict_types=0);

namespace Maduser\Argon\Container\Support;

final class CompiledCallableInvoker
{
    /** @param array<array-key, mixed> $arguments */
    public static function invoke(callable $target, array $arguments): mixed
    {
        return $target(...$arguments);
    }
}
