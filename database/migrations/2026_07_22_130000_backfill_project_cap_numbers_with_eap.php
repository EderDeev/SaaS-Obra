<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('project_document_versions as versions')
            ->join('project_documents as documents', 'documents.id', '=', 'versions.project_document_id')
            ->whereNotNull('versions.cap_number')
            ->select(['versions.id', 'versions.revision', 'documents.code'])
            ->orderBy('versions.id')
            ->get()
            ->each(function (object $version): void {
                $parts = array_values(array_filter(explode('-', (string) $version->code)));

                if (count($parts) < 2) {
                    return;
                }

                $parts[count($parts) - 2] = 'CAP';
                $parts[] = mb_strtoupper((string) $version->revision);

                DB::table('project_document_versions')
                    ->where('id', $version->id)
                    ->update(['cap_number' => implode('-', $parts)]);
            });
    }

    public function down(): void
    {
        DB::table('project_document_versions')
            ->whereNotNull('cap_number')
            ->whereNotNull('cap_sequence')
            ->whereNotNull('cap_year')
            ->select(['id', 'cap_sequence', 'cap_year'])
            ->orderBy('id')
            ->get()
            ->each(function (object $version): void {
                $number = 'CAP-'.str_pad((string) $version->cap_sequence, 3, '0', STR_PAD_LEFT).'-'.$version->cap_year;

                DB::table('project_document_versions')
                    ->where('id', $version->id)
                    ->update(['cap_number' => $number]);
            });
    }
};
