<?php

declare(strict_types=1);

namespace Maduser\Argon\Container\Compiler;

use Tests\Integration\Compiler\Mocks\FilePublicationFaults;

function fileperms(string $path): int|false
{
    return FilePublicationFaults::$failure === 'permissions' ? false : \fileperms($path);
}

function tempnam(string $directory, string $prefix): string|false
{
    return match (FilePublicationFaults::$failure) {
        'temporary' => false,
        'fallback' => \tempnam(sys_get_temp_dir(), $prefix),
        default => \tempnam($directory, $prefix),
    };
}

function file_put_contents(string $path, string $contents): int|false
{
    return match (FilePublicationFaults::$failure) {
        'write' => false,
        'short' => \file_put_contents($path, substr($contents, 0, 10)),
        default => \file_put_contents($path, $contents),
    };
}

function chmod(string $path, int $permissions): bool
{
    return FilePublicationFaults::$failure === 'chmod' ? false : \chmod($path, $permissions);
}

function rename(string $from, string $to): bool
{
    return FilePublicationFaults::$failure === 'rename' ? false : \rename($from, $to);
}

function unlink(string $path): bool
{
    return !FilePublicationFaults::$cleanupFails && \unlink($path);
}
