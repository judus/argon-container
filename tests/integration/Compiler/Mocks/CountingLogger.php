<?php

declare(strict_types=1);

namespace Tests\Integration\Compiler\Mocks;

use Tests\Integration\Mocks\LoggerInterface;

final class CountingLogger implements LoggerInterface
{
    public static int $constructions = 0;

    public function __construct()
    {
        self::$constructions++;
    }

    public static function constructions(): int
    {
        return self::$constructions;
    }
}
