<?php

declare(strict_types=1);

namespace Tests\Integration\Compiler\Mocks;

final class SelfDependent
{
    public function __construct(public SelfDependent $dependency)
    {
    }
}
