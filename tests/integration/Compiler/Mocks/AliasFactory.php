<?php

declare(strict_types=1);

namespace Tests\Integration\Compiler\Mocks;

final class AliasFactory
{
    public function __invoke(string $label, ?string $nullable, string $inherited): AliasLeaf
    {
        return self::create($label, $nullable, $inherited);
    }

    public static function create(string $label, ?string $nullable, string $inherited): AliasLeaf
    {
        return new AliasLeaf('factory:' . $label, $nullable, $inherited);
    }
}
