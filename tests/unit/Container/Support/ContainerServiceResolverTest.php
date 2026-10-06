<?php

declare(strict_types=1);

namespace Tests\Unit\Container\Support;

use Maduser\Argon\Container\ArgonContainer;
use Maduser\Argon\Container\Support\ContainerServiceResolver;
use PHPUnit\Framework\TestCase;
use stdClass;

final class ContainerServiceResolverTest extends TestCase
{
    public function testForwardsServiceIdAndRuntimeArgumentsToContainer(): void
    {
        $container = $this->createMock(ArgonContainer::class);
        $instance = new stdClass();
        $container->expects(self::once())
            ->method('get')
            ->with('configured.service', ['value' => null])
            ->willReturn($instance);

        $resolver = new ContainerServiceResolver($container);

        self::assertSame($instance, $resolver->resolve('configured.service', ['value' => null]));
    }
}
