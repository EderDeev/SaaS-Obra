<?php

namespace Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PrivateStorageServingTest extends TestCase
{
    public function test_private_volume_files_are_blocked_while_public_files_remain_available(): void
    {
        $directory = sys_get_temp_dir().'/sigworks-storage-'.bin2hex(random_bytes(8));
        $files = new Filesystem;
        $files->makeDirectory($directory.'/public', 0755, true);
        $files->makeDirectory($directory.'/storage/app/public/.sigworks-private', 0700, true);
        $files->copy(base_path('server.php'), $directory.'/server.php');
        $files->put($directory.'/public/index.php', '<?php http_response_code(404);');
        $files->put($directory.'/public/normal.txt', 'public file');
        $files->put($directory.'/storage/app/public/.sigworks-private/secret.txt', 'private evidence');
        // A real symlink is used in production; Windows can disable symlink creation.
        $hasSymlink = @symlink($directory.'/storage/app/public', $directory.'/public/storage');
        if (! $hasSymlink) {
            $files->makeDirectory($directory.'/public/storage/.sigworks-private', 0700, true);
            $files->put($directory.'/public/storage/.sigworks-private/secret.txt', 'private evidence');
        }
        $hasAlias = @symlink($directory.'/storage/app/public/.sigworks-private/secret.txt', $directory.'/public/alias.txt');
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $server = new Process([PHP_BINARY, '-S', $address, '-t', $directory.'/public', $directory.'/server.php'], $directory,
            ['RAILWAY_VOLUME_MOUNT_PATH' => $directory.'/storage/app/public']);

        try {
            $server->start();
            $ready = false;
            for ($attempt = 0; $attempt < 50; $attempt++) {
                $connection = @stream_socket_client('tcp://'.$address, $error, $message, 0.1);
                if ($connection) {
                    fclose($connection);
                    $ready = true;
                    break;
                }
                usleep(100000);
            }
            $this->assertTrue($ready, $server->getErrorOutput());
            $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 5]]);
            $this->assertSame('public file', file_get_contents('http://'.$address.'/normal.txt', false, $context));
            $paths = ['/storage/.sigworks-private/secret.txt', '/storage/%2esigworks-private/secret.txt', '/storage/.sigworks-private/../.sigworks-private/secret.txt'];
            if ($hasAlias) {
                $paths[] = '/alias.txt';
            }
            foreach ($paths as $path) {
                $body = file_get_contents('http://'.$address.$path, false, $context);
                $this->assertStringContainsString('404', $http_response_header[0], $path);
                $this->assertStringNotContainsString('private evidence', $body, $path);
            }
        } finally {
            $server->stop();
            // Remove only this randomly named test fixture, never application storage.
            if ($hasSymlink) {
                unlink($directory.'/public/storage');
            }
            if ($hasAlias) {
                unlink($directory.'/public/alias.txt');
            }
            $files->deleteDirectory($directory);
        }
    }
}
