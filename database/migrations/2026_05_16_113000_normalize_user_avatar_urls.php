<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('users')
            ->whereNotNull('avatar_url')
            ->orderBy('id')
            ->chunkById(100, function ($users): void {
                foreach ($users as $user) {
                    $urlPath = parse_url($user->avatar_url, PHP_URL_PATH) ?: $user->avatar_url;

                    if (! str_contains($urlPath, '/storage/')) {
                        continue;
                    }

                    DB::table('users')
                        ->where('id', $user->id)
                        ->update([
                            'avatar_url' => '/storage/'.ltrim(str($urlPath)->after('/storage/')->toString(), '/'),
                        ]);
                }
            });
    }
};
