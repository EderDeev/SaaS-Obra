<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ged_documents', function (Blueprint $table) {
            $table->unsignedSmallInteger('sequence_year')->nullable()->after('document_number');
            $table->unsignedInteger('sequence_number')->nullable()->after('sequence_year');
        });

        $documents = DB::table('ged_documents')
            ->select('id', 'tenant_id', 'document_date', 'created_at')
            ->orderBy('tenant_id')
            ->orderBy('id')
            ->get()
            ->groupBy(function ($document) {
                $date = $document->document_date ?: $document->created_at;

                return $document->tenant_id.'-'.date('Y', strtotime($date ?: now()));
            });

        foreach ($documents as $group) {
            $sequence = 1;

            foreach ($group as $document) {
                $date = $document->document_date ?: $document->created_at;
                $year = (int) date('Y', strtotime($date ?: now()));

                DB::table('ged_documents')
                    ->where('id', $document->id)
                    ->update([
                        'sequence_year' => $year,
                        'sequence_number' => $sequence,
                        'document_number' => str_pad((string) $sequence, 3, '0', STR_PAD_LEFT).'/'.$year,
                    ]);

                $sequence++;
            }
        }

        Schema::table('ged_documents', function (Blueprint $table) {
            $table->unique(['tenant_id', 'sequence_year', 'sequence_number'], 'ged_documents_tenant_year_sequence_unique');
            $table->index(['tenant_id', 'sequence_year', 'sequence_number'], 'ged_documents_tenant_year_sequence_index');
        });
    }

    public function down(): void
    {
        Schema::table('ged_documents', function (Blueprint $table) {
            $table->dropUnique('ged_documents_tenant_year_sequence_unique');
            $table->dropIndex('ged_documents_tenant_year_sequence_index');
            $table->dropColumn(['sequence_year', 'sequence_number']);
        });
    }
};
