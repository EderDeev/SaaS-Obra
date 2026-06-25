<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('rdo_secao_registros')
            ->where('secao', 'clima')
            ->select(['id', 'dados'])
            ->orderBy('id')
            ->each(function (object $record): void {
                $data = is_array($record->dados)
                    ? $record->dados
                    : json_decode((string) $record->dados, true);

                if (! is_array($data) || ! array_key_exists('precipitacao_mm', $data)) {
                    return;
                }

                if ($data['precipitacao_mm'] !== null && $data['precipitacao_mm'] !== '') {
                    $data['precipitacao_total_anterior_mm'] = $data['precipitacao_mm'];
                }

                unset($data['precipitacao_mm']);
                $data['precipitacao_manha_mm'] ??= '';
                $data['precipitacao_tarde_mm'] ??= '';
                $data['precipitacao_noite_mm'] ??= '';

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
