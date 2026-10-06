<?php

declare(strict_types=1);

namespace Tests\Integration\Compiler\Mocks;

use Maduser\Argon\Container\Contracts\PreResolutionInterceptorInterface;
use stdClass;

final class CyclicPreInterceptor implements PreResolutionInterceptorInterface
{
    public function __construct(private stdClass $dependency)
    {
    }

    #[\Override]
    public static function supports(object|string $target): bool
    {
        return $target === stdClass::class;
    }

    #[\Override]
    public function intercept(string $id, array &$parameters): ?object
    {
        return $this->dependency;
    }
}
