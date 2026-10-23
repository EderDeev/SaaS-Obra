<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instrucoes_campo', fn (Blueprint $table) => $table->string('resolution_result', 16)->nullable());
        // Preserve the recorded outcome and history of the previous workflow.
        DB::table('instrucoes_campo')->where('status', 'closed')->update(['resolution_result' => 'yes']);
        DB::table('instrucoes_campo')->where('status', 'rnc_required')->update(['status' => 'closed', 'resolution_result' => 'no']);
        DB::table('instrucoes_campo')->where('status', 'correction_submitted')->update(['status' => 'notified']);
    }

    public function down(): void
    {
        Schema::table('instrucoes_campo', fn (Blueprint $table) => $table->dropColumn('resolution_result'));
    }
};
