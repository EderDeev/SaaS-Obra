<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('rdo_secao_registros')
            ->where('secao', 'mao_obra')
            ->select(['id', 'dados'])
            ->orderBy('id')
            ->each(function (object $record): void {
                $data = is_array($record->dados)
                    ? $record->dados
                    : json_decode((string) $record->dados, true);

                if (! is_array($data)) {
                    return;
                }

                $data['subcontratadas'] ??= [];

                if (! empty($data['subcontratada_id'])) {
                    $data['subcontratadas'][(string) $data['subcontratada_id']] ??= 0;
                }

                unset($data['subcontratada_id']);

                DB::table('rdo_secao_registros')
                    ->where('id', $record->id)
                    ->update(['dados' => json_encode($data, JSON_UNESCAPED_UNICODE)]);
            });
    }

    public function down(): void
    {
        //
    }
};
