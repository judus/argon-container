<?php

declare(strict_types=1);

namespace Tests\Integration\Compiler\Mocks;

use Maduser\Argon\Container\Contracts\PreResolutionInterceptorInterface;
use RuntimeException;
use stdClass;

final class ResolutionLimitInterceptor implements PreResolutionInterceptorInterface
{
    public static int $calls = 0;
    public static ?object $replacement = null;

    #[\Override]
    public static function supports(object|string $target): bool
    {
        return is_string($target) && ltrim($target, '\\') !== self::class;
    }

    #[\Override]
    public function intercept(string $id, array &$parameters): ?object
    {
        // Bound recursion so a missing production guard fails instead of exhausting PHP.
        if (++self::$calls > 20) {
            throw new RuntimeException('Test resolution limit exceeded.');
        }

        return $id === stdClass::class ? self::$replacement : null;
    }
}
