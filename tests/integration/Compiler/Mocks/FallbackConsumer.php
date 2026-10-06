<?php

declare(strict_types=1);

namespace Tests\Integration\Compiler\Mocks;

use Tests\Integration\Mocks\NeedsLogger;

final class FallbackConsumer
{
    public function __construct(
        public NeedsLogger $nested,
        public PrimitiveService $configured,
        public DefaultValueService $product,
        public Logger $logger
    ) {
    }
}
