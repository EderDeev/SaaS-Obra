<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->expandPermissions('tenant_users');
        $this->expandPermissions('contract_participants');
    }

    public function down(): void
    {
        $this->removePermissions('tenant_users');
        $this->removePermissions('contract_participants');
    }

    private function expandPermissions(string $table): void
    {
        DB::table($table)
            ->whereNotNull('project_permissions')
            ->orderBy('id')
            ->chunkById(500, function ($records) use ($table): void {
                foreach ($records as $record) {
                    $permissions = $this->decodePermissions($record->project_permissions);

                    if (in_array('upload_project', $permissions, true)) {
                        $permissions[] = 'upload_project_batch';
                    }

                    if (in_array('review_project', $permissions, true)) {
                        $permissions[] = 'review_project_batch';
                        $permissions[] = 'manage_project_comments';
                    }

                    DB::table($table)->where('id', $record->id)->update([
                        'project_permissions' => json_encode(array_values(array_unique($permissions))),
                    ]);
                }
            });
    }

    private function removePermissions(string $table): void
    {
        DB::table($table)
            ->whereNotNull('project_permissions')
            ->orderBy('id')
            ->chunkById(500, function ($records) use ($table): void {
                foreach ($records as $record) {
                    $permissions = array_values(array_diff(
                        $this->decodePermissions($record->project_permissions),
                        ['upload_project_batch', 'review_project_batch', 'manage_project_comments'],
                    ));

                    DB::table($table)->where('id', $record->id)->update([
                        'project_permissions' => json_encode($permissions),
                    ]);
                }
            });
    }

    /**
     * @return array<int, string>
     */
    private function decodePermissions(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }
};
