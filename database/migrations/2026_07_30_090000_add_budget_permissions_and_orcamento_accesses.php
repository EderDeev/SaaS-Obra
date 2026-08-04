<?php

use App\Support\BudgetPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_users', function (Blueprint $table): void {
            $table->json('budget_permissions')->nullable()->after('project_permissions');
        });

        DB::table('tenant_users')
            ->select(['id', 'role'])
            ->orderBy('id')
            ->eachById(function (object $membership): void {
                DB::table('tenant_users')
                    ->where('id', $membership->id)
                    ->update([
                        'budget_permissions' => json_encode(BudgetPermissions::defaultForRole($membership->role)),
                    ]);
            });

        Schema::create('orcamento_acessos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('orcamento_id')->constrained('orcamentos')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('access_level', 20);
            $table->foreignId('granted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['orcamento_id', 'user_id']);
            $table->index(['tenant_id', 'user_id', 'access_level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orcamento_acessos');

        Schema::table('tenant_users', function (Blueprint $table): void {
            $table->dropColumn('budget_permissions');
        });
    }
};
