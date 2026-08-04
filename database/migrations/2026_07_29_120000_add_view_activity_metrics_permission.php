<?php

use App\Support\ActivityPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->updatePermissionLists(function (array $permissions): array {
            if (
                in_array(ActivityPermissions::EDIT, $permissions, true)
                && ! in_array(ActivityPermissions::VIEW_METRICS, $permissions, true)
            ) {
                $permissions[] = ActivityPermissions::VIEW_METRICS;
            }

            return ActivityPermissions::normalize($permissions);
        });
    }

    public function down(): void
    {
        $this->updatePermissionLists(fn (array $permissions): array => array_values(array_filter(
            $permissions,
            fn (string $permission): bool => $permission !== ActivityPermissions::VIEW_METRICS,
        )));
    }

    private function updatePermissionLists(callable $transform): void
    {
        foreach (['tenant_users', 'contract_participants'] as $table) {
            DB::table($table)
                ->whereNotNull('activity_permissions')
                ->orderBy('id')
                ->chunkById(200, function ($rows) use ($table, $transform): void {
                    foreach ($rows as $row) {
                        $permissions = json_decode($row->activity_permissions, true);

                        if (! is_array($permissions)) {
                            continue;
                        }

                        DB::table($table)
                            ->where('id', $row->id)
                            ->update([
                                'activity_permissions' => json_encode($transform($permissions)),
                            ]);
                    }
                });
        }
    }
};
