<?php

declare(strict_types=1);

namespace Tests\Integration\Compiler\Mocks;

use Maduser\Argon\Container\ArgonContainer;
use Psr\Container\ContainerInterface;

final class ContainerConsumer
{
    public function __construct(public ArgonContainer $argon, public ContainerInterface $psr)
    {
    }
}
