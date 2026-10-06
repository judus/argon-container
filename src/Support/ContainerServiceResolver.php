<?php

declare(strict_types=1);

namespace Maduser\Argon\Container\Support;

use Maduser\Argon\Container\ArgonContainer;
use Maduser\Argon\Container\Contracts\ServiceResolverInterface;
use Override;

/**
 * Routes reflective invocation dependencies through the compiled container's get().
 */
final readonly class ContainerServiceResolver implements ServiceResolverInterface
{
    public function __construct(private ArgonContainer $container)
    {
    }

    /** @inheritDoc */
    #[Override]
    public function resolve(string $id, array $args = []): object
    {
        return $this->container->get($id, $args);
    }
}
