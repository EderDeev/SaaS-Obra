<?php

namespace Tests\Unit;

use App\Services\Storage\StoredFileMaterializer;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class StoredFileMaterializerTest extends TestCase
{
    public function test_it_uses_the_existing_path_for_a_local_disk(): void
    {
        $storedPath = 'materializer-tests/'.Str::uuid().'/test.txt';
        Storage::disk('local')->put($storedPath, 'local contents');

        try {
            $callbackPath = app(StoredFileMaterializer::class)->withLocalPath(
                'local',
                $storedPath,
                function (string $path): string {
                    $this->assertFileExists($path);
                    $this->assertSame('local contents', file_get_contents($path));

                    return $path;
                },
            );

            $this->assertFileExists($callbackPath);
        } finally {
            Storage::disk('local')->deleteDirectory(dirname($storedPath));
        }
    }

    public function test_it_cleans_up_a_materialized_remote_file_after_use(): void
    {
        Storage::fake('materializer-remote');
        config()->set('filesystems.disks.materializer-remote.materialize', true);
        Storage::disk('materializer-remote')->put('documents/test.pdf', 'remote contents');

        $callbackPath = app(StoredFileMaterializer::class)->withLocalPath(
            'materializer-remote',
            'documents/test.pdf',
            function (string $path): string {
                $this->assertFileExists($path);
                $this->assertSame('pdf', pathinfo($path, PATHINFO_EXTENSION));
                $this->assertSame('remote contents', file_get_contents($path));

                return $path;
            },
        );

        $this->assertFileDoesNotExist($callbackPath);
    }

    public function test_it_cleans_up_a_materialized_file_when_the_callback_fails(): void
    {
        Storage::fake('materializer-failure');
        config()->set('filesystems.disks.materializer-failure.materialize', true);
        Storage::disk('materializer-failure')->put('documents/test.dwg', 'project contents');
        $callbackPath = null;

        try {
            app(StoredFileMaterializer::class)->withLocalPath(
                'materializer-failure',
                'documents/test.dwg',
                function (string $path) use (&$callbackPath): never {
                    $callbackPath = $path;
                    throw new \RuntimeException('Expected failure.');
                },
            );
        } catch (\RuntimeException $exception) {
            $this->assertSame('Expected failure.', $exception->getMessage());
        }

        $this->assertNotNull($callbackPath);
        $this->assertFileDoesNotExist($callbackPath);
    }
}
