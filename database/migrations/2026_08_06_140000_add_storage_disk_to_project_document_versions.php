<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_document_versions', function (Blueprint $table): void {
            $table->string('storage_disk', 32)->nullable()->after('file_path');
        });

        $this->moveFiles('public', 'local');
    }

    public function down(): void
    {
        $this->moveFiles('local', 'public');

        Schema::table('project_document_versions', function (Blueprint $table): void {
            $table->dropColumn('storage_disk');
        });
    }

    private function moveFiles(string $sourceDisk, string $targetDisk): void
    {
        DB::table('project_document_versions')
            ->select(['id', 'file_path', 'storage_disk'])
            ->whereNotNull('file_path')
            ->when(
                $sourceDisk === 'public',
                fn ($query) => $query->where(
                    fn ($diskQuery) => $diskQuery->whereNull('storage_disk')->orWhere('storage_disk', 'public')
                ),
                fn ($query) => $query->where('storage_disk', 'local'),
            )
            ->orderBy('id')
            ->chunkById(100, function ($versions) use ($sourceDisk, $targetDisk): void {
                $source = Storage::disk($sourceDisk);
                $target = Storage::disk($targetDisk);

                foreach ($versions as $version) {
                    $path = ltrim(str_replace('\\', '/', (string) $version->file_path), '/');

                    if ($path === '') {
                        continue;
                    }

                    if (! $target->exists($path) && $source->exists($path)) {
                        $stream = $source->readStream($path);

                        if ($stream === false) {
                            continue;
                        }

                        try {
                            $target->writeStream($path, $stream);
                        } finally {
                            if (is_resource($stream)) {
                                fclose($stream);
                            }
                        }
                    }

                    if (! $target->exists($path)) {
                        continue;
                    }

                    DB::table('project_document_versions')
                        ->where('id', $version->id)
                        ->update(['storage_disk' => $targetDisk]);

                    if ($source->exists($path)) {
                        $source->delete($path);
                    }
                }
            });
    }
};
