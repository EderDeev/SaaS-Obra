<?php

use App\Support\OrdemServicoPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->updatePermissionColumn(true);
    }

    public function down(): void
    {
        $this->updatePermissionColumn(false);
    }

    private function updatePermissionColumn(bool $add): void
    {
        foreach (['tenant_users', 'contract_participants'] as $table) {
            DB::table($table)
                ->whereNotNull('ordem_servico_permissions')
                ->orderBy('id')
                ->chunkById(200, function ($records) use ($table, $add): void {
                    foreach ($records as $record) {
                        $permissions = json_decode($record->ordem_servico_permissions, true);

                        if (! is_array($permissions)) {
                            continue;
                        }

                        if ($add) {
                            if (
                                in_array(OrdemServicoPermissions::RESPONSIBLES, $permissions, true)
                                && ! in_array(OrdemServicoPermissions::SETTINGS, $permissions, true)
                            ) {
                                $permissions[] = OrdemServicoPermissions::SETTINGS;
                            }
                        } else {
                            $permissions = array_values(array_filter(
                                $permissions,
                                fn ($permission): bool => $permission !== OrdemServicoPermissions::SETTINGS
                            ));
                        }

                        DB::table($table)
                            ->where('id', $record->id)
                            ->update(['ordem_servico_permissions' => json_encode(array_values(array_unique($permissions)))]);
                    }
                });
        }
    }
};
