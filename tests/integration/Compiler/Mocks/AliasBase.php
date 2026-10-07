<?php

declare(strict_types=1);

namespace Tests\Integration\Compiler\Mocks;

class AliasBase implements AliasContract
{
    public function __construct(
        public string $label = 'default',
        public ?string $nullable = 'default',
        public string $inherited = 'default'
    ) {
    }

    #[\Override]
    public function describe(): string
    {
        return $this->label;
    }
}
