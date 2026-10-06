<?php

declare(strict_types=1);

namespace Tests\Integration\Compiler\Mocks;

use Maduser\Argon\Container\Contracts\PostResolutionInterceptorInterface;
use stdClass;

final class CyclicPostInterceptor implements PostResolutionInterceptorInterface
{
    public function __construct(public stdClass $dependency)
    {
    }

    #[\Override]
    public static function supports(object|string $target): bool
    {
        return $target instanceof stdClass;
    }

    #[\Override]
    public function intercept(object $instance): void
    {
    }
}
