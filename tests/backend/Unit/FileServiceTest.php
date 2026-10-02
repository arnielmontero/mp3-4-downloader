<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\FileService;
use App\Support\ApiException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestCase;

final class FileServiceTest extends TestCase
{
    // ------------------------------------------------------------ filename sanitization

    /** @return array<string,array{string,string}> */
    public static function filenames(): array
    {
        return [
            'spec example' => ['My Video: Episode 1 / Test?', 'My Video - Episode 1 - Test'],
            'backslash' => ['a\\b\\c', 'a - b - c'],
            'illegal chars' => ['what*is<this>"quoted"|pipe?', 'whatisthisquotedpipe'],
            'control chars' => ["Tab\there\x00and\x1Fmore\r\nlines", 'Tabhereandmorelines'],
            'path traversal' => ['../../etc/passwd', 'etc - passwd'],
            'windows path' => ['..\\..\\Windows\\System32', 'Windows - System32'],
            'absolute path' => ['/etc/shadow', 'etc - shadow'],
            'drive letter' => ['C:\\Users\\x', 'C - Users - x'],
            'dots only' => ['....', 'download'],
            'trailing dots and spaces' => ['name. . ', 'name'],
            'leading dot kept visible' => ['.hidden', 'hidden'],
            'whitespace collapsed' => ["a    b \t c", 'a b c'],
            'empty' => ['', 'download'],
            'only illegal' => ['???***', 'download'],
            'unicode kept' => ['Música ñandú 日本語', 'Música ñandú 日本語'],
            'emoji kept' => ['Party 🎉 mix', 'Party 🎉 mix'],
            'reserved CON' => ['CON', '_CON'],
            'reserved lower nul' => ['nul', '_nul'],
            'reserved COM1 with ext' => ['com1.txt', '_com1.txt'],
            'reserved LPT9' => ['LPT9', '_LPT9'],
            'not reserved' => ['CONSOLE', 'CONSOLE'],
        ];
    }

    #[DataProvider('filenames')]
    public function testSanitizeFilename(string $input, string $expected): void
    {
        $this->assertSame($expected, FileService::sanitizeFilename($input));
    }

    public function testSanitizedNamesAreAlwaysSafe(): void
    {
        $nasty = ['a/b', '..', '../..', "x\0y", 'CON', 'a:b', str_repeat('é', 400), '<>:"/\\|?*', ' . ', "\u{202E}evil", '~$tmp'];
        foreach ($nasty as $name) {
            $safe = FileService::sanitizeFilename($name);
            $this->assertNotSame('', $safe);
            $this->assertSame($safe, basename($safe), "no path separators: $safe");
            $this->assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F\/\\\\:*?"<>|]/u', $safe);
            $this->assertLessThanOrEqual(150, strlen($safe), 'byte length bounded');
            $this->assertTrue(mb_check_encoding($safe, 'UTF-8'), 'still valid UTF-8 after truncation');
            $this->assertStringEndsNotWith('.', $safe);
            $this->assertStringEndsNotWith(' ', $safe);
        }
    }

    public function testFallbackNameIsUsedWhenEmpty(): void
    {
        $this->assertSame('dQw4w9WgXcQ', FileService::sanitizeFilename('???', 'dQw4w9WgXcQ'));
    }

    public function testUniqueFilenameNeverOverwrites(): void
    {
        $dir = $this->storage . '/downloads';
        $this->assertSame('video.mp4', FileService::uniqueFilename($dir, 'video', 'mp4'));
        touch("$dir/video.mp4");
        $this->assertSame('video (1).mp4', FileService::uniqueFilename($dir, 'video', 'mp4'));
        touch("$dir/video (1).mp4");
        touch("$dir/video (2).mp4");
        $this->assertSame('video (3).mp4', FileService::uniqueFilename($dir, 'video', 'mp4'));
        $this->assertSame('video.mp3', FileService::uniqueFilename($dir, 'video', 'mp3'));
    }

    // ------------------------------------------------------------ paths and authorisation

    public function testJobDirsRejectInvalidIds(): void
    {
        $files = new FileService($this->config());
        $id = $this->validId();
        $this->assertSame($this->storage . DIRECTORY_SEPARATOR . 'temp' . DIRECTORY_SEPARATOR . $id, $files->jobTempDir($id));
        foreach (['../x', '..', 'a/b', '', str_repeat('z', 32)] as $bad) {
            try {
                $files->jobTempDir($bad);
                $this->fail("accepted $bad");
            } catch (ApiException $e) {
                $this->assertSame('INVALID_JOB_ID', $e->errorCode());
            }
        }
    }

    public function testResolveJobFileReturnsOnlyFilesOfThatJob(): void
    {
        $files = new FileService($this->config());
        $id = $this->validId();
        $dir = $this->storage . '/downloads/' . $id;
        mkdir($dir, 0775, true);
        file_put_contents("$dir/Song.mp3", 'abc');

        $job = ['id' => $id, 'filename' => 'Song.mp3', 'format' => 'mp3'];
        $file = $files->resolveJobFile($job);
        $this->assertNotNull($file);
        $this->assertSame('audio/mpeg', $file['type']);
        $this->assertSame(3, $file['size']);
        $this->assertSame(realpath("$dir/Song.mp3"), $file['path']);

        // a different job id must not reach this file
        $this->assertNull($files->resolveJobFile(['id' => $this->validId(), 'filename' => 'Song.mp3', 'format' => 'mp3']));
        // wrong format / extension
        $this->assertNull($files->resolveJobFile(['id' => $id, 'filename' => 'Song.mp3', 'format' => 'mp4']));
        // missing file
        $this->assertNull($files->resolveJobFile(['id' => $id, 'filename' => 'Nope.mp3', 'format' => 'mp3']));
    }

    public function testResolveJobFileBlocksTraversalInStoredFilename(): void
    {
        $files = new FileService($this->config());
        $id = $this->validId();
        $other = $this->validId();
        mkdir($this->storage . '/downloads/' . $id, 0775, true);
        mkdir($this->storage . '/downloads/' . $other, 0775, true);
        file_put_contents($this->storage . '/downloads/' . $other . '/secret.mp3', 'secret');
        file_put_contents($this->storage . '/secret.mp4', 'secret');

        foreach (["../$other/secret.mp3", '../../secret.mp4', "..\\..\\secret.mp4", '/etc/passwd.mp3', "secret.mp3\0.txt", $this->storage . '/secret.mp4', ''] as $name) {
            $fmt = str_ends_with($name, '.mp4') ? 'mp4' : 'mp3';
            $this->assertNull($files->resolveJobFile(['id' => $id, 'filename' => $name, 'format' => $fmt]), "must reject: $name");
        }
        $this->assertNull($files->resolveJobFile(['id' => '../' . $id, 'filename' => 'x.mp3', 'format' => 'mp3']));
    }

    public function testResolveJobFileRejectsSymlinks(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('symlinks need elevated rights on Windows');
        }
        $files = new FileService($this->config());
        $id = $this->validId();
        $dir = $this->storage . '/downloads/' . $id;
        mkdir($dir, 0775, true);
        file_put_contents($this->storage . '/outside.mp3', 'outside');
        symlink($this->storage . '/outside.mp3', "$dir/link.mp3");
        $this->assertNull($files->resolveJobFile(['id' => $id, 'filename' => 'link.mp3', 'format' => 'mp3']));
    }

    public function testContentDispositionIsSafe(): void
    {
        $header = FileService::contentDisposition("Evil\"; filename=\"x.exe\r\nX-Injected: 1.mp4");
        $this->assertStringStartsWith('attachment; filename="', $header);
        $this->assertStringNotContainsString("\r", $header);
        $this->assertStringNotContainsString("\n", $header);
        $unicode = FileService::contentDisposition('Música.mp3');
        $this->assertStringContainsString("filename*=UTF-8''M%C3%BAsica.mp3", $unicode);
    }

    // ------------------------------------------------------------ housekeeping helpers

    public function testRemoveDirAndDirSize(): void
    {
        $dir = $this->storage . '/temp/x';
        mkdir("$dir/sub", 0775, true);
        file_put_contents("$dir/a.bin", str_repeat('a', 100));
        file_put_contents("$dir/sub/b.bin", str_repeat('b', 50));
        $this->assertSame(150, FileService::dirSize($dir));
        FileService::removeDir($dir);
        $this->assertDirectoryDoesNotExist($dir);
        $this->assertSame(0, FileService::dirSize($dir));
        FileService::removeDir($dir); // idempotent
        $this->addToAssertionCount(1);
    }

    public function testFindOutputFileIgnoresPartials(): void
    {
        $dir = $this->storage . '/temp/y';
        mkdir($dir, 0775, true);
        file_put_contents("$dir/id.f137.mp4.part", 'x');
        file_put_contents("$dir/id.f137.mp4", 'x');
        file_put_contents("$dir/id.mp4", 'xx');
        $found = FileService::findOutputFile($dir, 'mp4');
        $this->assertNotNull($found);
        $this->assertStringEndsNotWith('.part', $found);
        $this->assertNull(FileService::findOutputFile($dir, 'mp3'));
    }
}
