<?php

declare(strict_types=1);

namespace Tests\Integration\Compiler\Mocks;

use Tests\Integration\Mocks\LoggerInterface;

class ContextualFactoryMethods
{
    public function __invoke(LoggerInterface $logger, ?LoggerInterface $optional = null): AliasLeaf
    {
        return self::create($logger, $optional);
    }

    public function make(LoggerInterface $logger, ?LoggerInterface $optional = null): AliasLeaf
    {
        return self::create($logger, $optional);
    }

    public static function create(LoggerInterface $logger, ?LoggerInterface $optional = null): AliasLeaf
    {
        return new AliasLeaf($logger::class, $optional === null ? null : $optional::class);
    }
}
