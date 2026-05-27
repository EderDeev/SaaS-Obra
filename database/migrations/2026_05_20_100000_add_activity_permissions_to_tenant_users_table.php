<?php

use App\Support\ActivityPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tenant_users', function (Blueprint $table) {
            $table->json('activity_permissions')->nullable()->after('status');
        });

        DB::table('tenant_users')
            ->orderBy('id')
            ->get(['id', 'role'])
            ->each(function ($membership): void {
                DB::table('tenant_users')
                    ->where('id', $membership->id)
                    ->update([
                        'activity_permissions' => json_encode(ActivityPermissions::defaultForRole($membership->role)),
                    ]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenant_users', function (Blueprint $table) {
            $table->dropColumn('activity_permissions');
        });
    }
};
