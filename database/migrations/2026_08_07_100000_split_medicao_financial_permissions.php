<?php

use App\Support\MedicaoPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->transform(function (array $permissions): array {
            if (in_array(MedicaoPermissions::ITEMS, $permissions, true)) {
                $permissions = array_merge($permissions, [
                    MedicaoPermissions::IMPORT_ITEMS,
                    MedicaoPermissions::ADDITIVES,
                    MedicaoPermissions::ADJUSTMENT_INDICES,
                ]);
            }

            if (in_array(MedicaoPermissions::REPORTS, $permissions, true)) {
                $permissions[] = MedicaoPermissions::BI;
            }

            return MedicaoPermissions::normalize($permissions);
        });
    }

    public function down(): void
    {
        $newPermissions = [
            MedicaoPermissions::IMPORT_ITEMS,
            MedicaoPermissions::ADDITIVES,
            MedicaoPermissions::ADJUSTMENT_INDICES,
            MedicaoPermissions::BI,
        ];

        $this->transform(fn (array $permissions): array => array_values(array_diff($permissions, $newPermissions)));
    }

    private function transform(callable $callback): void
    {
        foreach (['tenant_users', 'contract_participants'] as $table) {
            DB::table($table)
                ->select(['id', 'medicao_permissions'])
                ->whereNotNull('medicao_permissions')
                ->orderBy('id')
                ->chunkById(200, function ($rows) use ($callback, $table): void {
                    foreach ($rows as $row) {
                        $permissions = is_array($row->medicao_permissions)
                            ? $row->medicao_permissions
                            : (json_decode((string) $row->medicao_permissions, true) ?: []);

                        DB::table($table)
                            ->where('id', $row->id)
                            ->update(['medicao_permissions' => json_encode($callback($permissions))]);
                    }
                });
        }
    }
};
