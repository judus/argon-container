<?php

declare(strict_types=1);

namespace Tests\Integration\Compiler\Mocks;

use Countable;
use DomainException;
use Iterator;
use Tests\Integration\Mocks\LoggerInterface;

final class InvocationTarget
{
    public function __construct(private string $prefix = 'default')
    {
    }

    public function __invoke(string $value): string
    {
        return $this->prefix . ':' . $value;
    }

    public static function staticMethod(string $value): string
    {
        return 'static:' . $value;
    }

    public function union(int|string $value): int|string
    {
        return $value;
    }

    /** @param Countable&Iterator<array-key, mixed> $value */
    public function intersection(Countable&Iterator $value): int
    {
        return count($value);
    }

    public function nullable(?LoggerInterface $logger = null): ?LoggerInterface
    {
        return $logger;
    }

    public function dependency(LoggerInterface $logger): LoggerInterface
    {
        return $logger;
    }

    public function defaults(int $number = 42, ?string $text = 'default'): array
    {
        return [$number, $text];
    }

    public function fail(): never
    {
        throw new DomainException('application failure');
    }
}
