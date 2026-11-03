<?php

namespace App\Support;

use App\Models\RdoDiario;
use App\Models\RdoPdfSetting;
use App\Models\Tenant;

class RdoPdfTemplates
{
    public const ACTIVITY_SUBJECTS = ['engenharia' => 'Engenharia', 'seguranca_trabalho' => 'Segurança do trabalho', 'meio_ambiente' => 'Meio ambiente'];

    public const VIEWS = ['standard' => 'pdf.rdo', 'concremat_diary' => 'pdf.rdo-concremat'];

    public const STYLES = [
        'navy' => ['name' => 'Azul sóbrio', 'color' => '#23466b', 'description' => 'Faixas azuladas suaves e bordas neutras.'],
        'graphite' => ['name' => 'Cinza técnico', 'color' => '#334155', 'description' => 'Tons de cinza para uma apresentação discreta.'],
        'forest' => ['name' => 'Verde institucional', 'color' => '#2f6557', 'description' => 'Destaques verdes suaves e fundo branco.'],
    ];

    public static function styles(): array
    {
        return collect(self::STYLES)->map(fn ($style, $key) => ['value' => $key, ...$style])->values()->all();
    }

    public static function catalog(): array
    {
        return [['value' => 'standard', 'name' => 'Padrão do sistema', 'description' => 'Cabeçalho, tempo, atividades, recursos, fotos, comentários e assinaturas.'], ['value' => 'concremat_diary', 'name' => 'Diário de obra — Concremat', 'workflow_model' => 'gerenciadora_cliente', 'description' => 'Inspirado no documento enviado: faixa azul, quadro de prazo e clima, atividades por assunto, fotos em duas colunas e equipe envolvida. Fluxo Gerenciadora + Cliente.']];
    }

    public static function activitySubjects(RdoDiario $rdo): array
    {
        return self::forDiary($rdo)['layout'] === 'concremat_diary'
            ? collect(self::ACTIVITY_SUBJECTS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values()->all()
            : [];
    }

    public static function supportsWorkflow(string $layout, array $workflow): bool
    {
        if ($layout !== 'concremat_diary') return true;
        return ($workflow['fill_parties'] ?? [$workflow['fill_party'] ?? 'construtora']) === ['gerenciadora']
            && ($workflow['review_parties'] ?? []) === ['cliente'];
    }

    public static function configuration(array $data = []): array
    {
        $layout = $data['layout'] ?? 'standard';
        $title = trim((string) ($data['title'] ?? 'Relatório Diário de Obra'));
        $theme = isset(self::STYLES[$data['theme'] ?? '']) ? $data['theme'] : null;
        $color = $data['color'] ?? ($theme ? self::STYLES[$theme]['color'] : '#111111');
        $titleColor = $data['title_color'] ?? null;

        return [...($theme ? ['theme' => $theme] : []), ...($titleColor && preg_match('/^#[a-fA-F0-9]{6}$/', $titleColor) ? ['title_color' => $titleColor] : []), ...(array_key_exists('logos', $data) ? ['logos' => $data['logos']] : []), 'schema_version' => 1, 'layout' => array_key_exists($layout, self::VIEWS) ? $layout : 'standard',
            'company_name' => trim((string) ($data['company_name'] ?? '')), 'title' => $title ?: 'Relatório Diário de Obra', 'color' => preg_match('/^#[a-fA-F0-9]{6}$/', $color) ? $color : '#111111'];
    }

    public static function forContract(Tenant $tenant, int $contractId): array
    {
        return self::configuration(RdoPdfSetting::where('tenant_id', $tenant->id)->where('contract_id', $contractId)->first()?->configuration ?? []);
    }

    public static function forDiary(RdoDiario $rdo): array
    {
        // Legacy records retain the original document, independently of later settings.
        return self::configuration($rdo->pdf_configuration_snapshot ?? []);
    }
}
