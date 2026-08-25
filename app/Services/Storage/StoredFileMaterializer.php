<?php

namespace App\Services\Storage;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class StoredFileMaterializer
{
    /**
     * Execute a callback with a local path for a stored file.
     *
     * Remote files are copied to an isolated temporary directory and removed
     * immediately after the callback finishes, including when it throws.
     */
    public function withLocalPath(string $diskName, string $path, callable $callback): mixed
    {
        $disk = Storage::disk($diskName);

        if (! $disk->exists($path)) {
            throw new RuntimeException("Arquivo nao encontrado no disco [{$diskName}]: {$path}");
        }

        $diskConfig = (array) config("filesystems.disks.{$diskName}", []);
        $requiresMaterialization = ($diskConfig['driver'] ?? null) !== 'local'
            || (bool) ($diskConfig['materialize'] ?? false);

        if (! $requiresMaterialization) {
            $localPath = $disk->path($path);

            if (! is_file($localPath)) {
                throw new RuntimeException("Arquivo local nao encontrado no disco [{$diskName}]: {$path}");
            }

            return $callback($localPath);
        }

        $temporaryDirectory = storage_path('app/private/materialized-files');
        File::ensureDirectoryExists($temporaryDirectory);

        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        $temporaryPath = $temporaryDirectory.'/'.Str::uuid().($extension !== '' ? ".{$extension}" : '');
        $source = $disk->readStream($path);

        if (! is_resource($source)) {
            throw new RuntimeException("Nao foi possivel ler o arquivo no disco [{$diskName}]: {$path}");
        }

        $destination = fopen($temporaryPath, 'wb');

        if (! is_resource($destination)) {
            fclose($source);
            throw new RuntimeException('Nao foi possivel criar o arquivo temporario local.');
        }

        try {
            if (stream_copy_to_stream($source, $destination) === false) {
                throw new RuntimeException('Nao foi possivel materializar o arquivo armazenado.');
            }

            fclose($source);
            $source = null;
            fclose($destination);
            $destination = null;

            return $callback($temporaryPath);
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }

            if (is_resource($destination)) {
                fclose($destination);
            }

            File::delete($temporaryPath);
        }
    }
}
