<?php

declare(strict_types=1);

namespace Tests\Integration\Compiler\Mocks;

use stdClass;

final class CyclicFactory
{
    public function create(stdClass $dependency): stdClass
    {
        return $dependency;
    }
}
