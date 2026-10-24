<?php

namespace App\Support;

use App\Models\RncPdfSetting;
use App\Models\Tenant;
use Illuminate\Support\Arr;

class RncPdfTemplates
{
    public const VIEWS = ['standard' => 'pdf.rnc', 'inspection_form' => 'pdf.rnc-form'];

    public static function catalog(): array
    {
        return [
            ['value' => 'standard', 'name' => 'Padrão do sistema', 'description' => 'Modelo atual, com identificação, textos e registro fotográfico em seções.'],
            ['value' => 'inspection_form', 'name' => 'Formulário de inspeção', 'description' => 'Baseado no modelo enviado: campos com bordas, faixas de identificação e fotos em duas colunas.'],
        ];
    }

    public static function defaults(string $layout): array
    {
        return [
            'title' => $layout === 'inspection_form' ? 'REGISTRO DE NÃO CONFORMIDADE - RNC' : 'Relatório de Não Conformidade',
            'color' => $layout === 'inspection_form' ? '#354150' : '#182033',
            'left_logo' => 'contratante', 'right_logo' => 'contratada',
            'contract_notes' => '', 'work_type' => '', 'pavement_type' => '',
        ];
    }

    public static function configuration(string $layout = 'standard', array $options = []): array
    {
        $layout = array_key_exists($layout, self::VIEWS) ? $layout : 'standard';
        $defaults = self::defaults($layout);
        $options = array_replace($defaults, Arr::only($options, array_keys($defaults)));
        foreach ($options as $key => $value) {
            $options[$key] = is_string($value) ? trim($value) : $defaults[$key];
        }
        $options['title'] = $options['title'] ?: $defaults['title'];
        if (! preg_match('/^#[0-9a-fA-F]{6}$/', $options['color'])) $options['color'] = $defaults['color'];
        foreach (['left_logo', 'right_logo'] as $key) {
            if (! in_array($options[$key], ['contratante', 'contratada', 'fiscalizadora', 'none'], true)) $options[$key] = $defaults[$key];
        }

        return ['layout' => $layout, 'options' => $options];
    }

    public static function forContract(Tenant $tenant, int $contractId): array
    {
        $setting = RncPdfSetting::where('tenant_id', $tenant->id)->where('contract_id', $contractId)->first();

        return self::configuration($setting?->layout ?? 'standard', $setting?->options ?? []);
    }
}
