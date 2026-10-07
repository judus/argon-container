<?php

declare(strict_types=1);

namespace Maduser\Argon\Container\Compiler;

final readonly class CircularAlias
{
    /** @param list<string> $chain */
    public function __construct(public string $id, public array $chain)
    {
    }
}
