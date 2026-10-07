<?php

declare(strict_types=1);

namespace Tests\Integration\Compiler;

use Maduser\Argon\Container\ArgonContainer;
use Maduser\Argon\Container\Compiler\ContainerCompiler;
use Maduser\Argon\Container\Exceptions\ContainerException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Tests\Integration\Compiler\Mocks\FilePublicationFaults;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CompilerPublicationTest extends TestCase
{
    private string $directory;

    #[\Override]
    protected function setUp(): void
    {
        require_once __DIR__ . '/Mocks/file-publication-functions.php';
        $this->directory = sys_get_temp_dir() . '/argon-publication-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory));
    }

    #[\Override]
    protected function tearDown(): void
    {
        $files = scandir($this->directory);
        self::assertIsArray($files);
        foreach ($files as $name) {
            if ($name !== '.' && $name !== '..') {
                unlink($this->directory . '/' . $name);
            }
        }
        rmdir($this->directory);
    }

    public function testPublishesAndSkipsUnchangedOutput(): void
    {
        $path = $this->directory . '/container.php';
        $compiler = new ContainerCompiler(new ArgonContainer());
        $compiler->compile($path, 'Published');
        self::assertFileExists($path);
        $permissions = fileperms($path);
        self::assertNotFalse($permissions);
        self::assertSame(0666 & ~umask(), $permissions & 0777);
        self::assertTrue(chmod($path, 0640));
        self::assertTrue(touch($path, 1000000000));
        clearstatcache(true, $path);
        $inode = fileinode($path);
        $compiler->compile($path, 'Published');
        clearstatcache(true, $path);
        self::assertSame(1000000000, filemtime($path));
        self::assertSame($inode, fileinode($path));
        $compiler->compile($path, 'Replaced');
        clearstatcache(true, $path);
        $permissions = fileperms($path);
        self::assertNotFalse($permissions);
        self::assertSame(0640, $permissions & 0777);
        self::assertNotSame($inode, fileinode($path));
        self::assertStringContainsString('class Replaced', (string) file_get_contents($path));
        self::assertSame(['.', '..', 'container.php'], scandir($this->directory));
    }

    public function testPublishesThroughExistingSymlink(): void
    {
        $target = $this->directory . '/target.php';
        file_put_contents($target, 'old');
        $link = $this->directory . '/link.php';
        self::assertTrue(symlink($target, $link));
        (new ContainerCompiler(new ArgonContainer()))->compile($link, 'Linked');
        self::assertTrue(is_link($link));
        self::assertSame(file_get_contents($target), file_get_contents($link));
        self::assertStringContainsString('class Linked', (string) file_get_contents($target));
    }

    public function testMissingDirectoryThrows(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('destination directory does not exist');
        (new ContainerCompiler(new ArgonContainer()))->compile($this->directory . '/missing/container.php', 'Missing');
    }

    /** @return iterable<string, array{string, string}> */
    public static function failures(): iterable
    {
        yield 'permissions' => ['permissions', 'Cannot read compiled container permissions'];
        yield 'temporary' => ['temporary', 'Cannot create temporary compiled container for'];
        yield 'fallback' => ['fallback', 'Cannot create temporary compiled container in'];
        yield 'write' => ['write', 'Cannot write complete compiled container'];
        yield 'short write' => ['short', 'Cannot write complete compiled container'];
        yield 'chmod' => ['chmod', 'Cannot set compiled container permissions'];
        yield 'rename' => ['rename', 'Cannot publish compiled container'];
    }

    #[DataProvider('failures')]
    public function testFailurePreservesExistingFileAndCleansTemporary(string $failure, string $message): void
    {
        $path = $this->directory . '/container.php';
        file_put_contents($path, 'previous cache');
        FilePublicationFaults::$failure = $failure;
        try {
            (new ContainerCompiler(new ArgonContainer()))->compile($path, 'Failed');
            self::fail('Expected publication failure.');
        } catch (ContainerException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
        }
        self::assertSame('previous cache', file_get_contents($path));
        self::assertSame(['.', '..', 'container.php'], scandir($this->directory));
    }

    public function testCleanupFailureIsReported(): void
    {
        FilePublicationFaults::$failure = 'rename';
        FilePublicationFaults::$cleanupFails = true;
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Cannot remove temporary compiled container');
        (new ContainerCompiler(new ArgonContainer()))->compile($this->directory . '/container.php', 'Failed');
    }
}
