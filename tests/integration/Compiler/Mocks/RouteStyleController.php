<?php

declare(strict_types=1);

namespace Tests\Integration\Compiler\Mocks;

final class RouteStyleController
{
    public int $calls = 0;

    public function show(Logger $logger, string $id): array
    {
        return [
            'id' => $id,
            'log' => $logger->log('route-hit'),
        ];
    }

    public function typed(Logger $logger, int $id, float $ratio = 1.5): array
    {
        $this->calls++;
        return [
            'id' => $id,
            'ratio' => $ratio,
            'log' => $logger->log('typed-route-hit'),
        ];
    }
}
