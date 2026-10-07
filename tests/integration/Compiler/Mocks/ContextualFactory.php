<?php

declare(strict_types=1);

namespace Tests\Integration\Compiler\Mocks;

use Tests\Integration\Mocks\LoggerInterface;

final class ContextualFactory extends ContextualFactoryMethods
{
    public function __construct(public LoggerInterface $logger)
    {
    }
}
