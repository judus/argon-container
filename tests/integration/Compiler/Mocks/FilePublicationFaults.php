<?php

declare(strict_types=1);

namespace Tests\Integration\Compiler\Mocks;

final class FilePublicationFaults
{
    public static string $failure = '';
    public static bool $cleanupFails = false;
}
