<?php

declare(strict_types=1);

namespace Tests\Integration\Compiler\Mocks;

use Maduser\Argon\Container\Contracts\PreResolutionInterceptorInterface;

final class AliasCycleInterceptor implements PreResolutionInterceptorInterface
{
    #[\Override]
    public static function supports(object|string $target): bool
    {
        return $target === AliasBase::class;
    }

    #[\Override]
    public function intercept(string $id, array &$parameters): ?object
    {
        return new AliasLeaf('intercepted');
    }
}
