<?php

namespace App\Support;

use App\Models\RncPdfSetting;
use App\Models\Tenant;
use Illuminate\Support\Arr;

class RncPdfTemplates
{
    public const VIEWS = ['standard' => 'pdf.rnc', 'inspection_form' => 'pdf.rnc-form'];
    public const EDITABLE_OPTIONS = ['title', 'color', 'contract_notes', 'work_type', 'pavement_type'];
    public const LOGO_OPTIONS = ['left_logo_path', 'right_logo_path', 'left_logo_name', 'right_logo_name'];

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
            'contract_notes' => '', 'work_type' => '', 'pavement_type' => '',
        ];
    }

    public static function configuration(string $layout = 'standard', array $options = []): array
    {
        $layout = array_key_exists($layout, self::VIEWS) ? $layout : 'standard';
        $defaults = self::defaults($layout);
        $options = array_replace($defaults, array_fill_keys(self::LOGO_OPTIONS, ''), Arr::only($options, [...self::EDITABLE_OPTIONS, ...self::LOGO_OPTIONS]));
        foreach ($options as $key => $value) {
            $options[$key] = is_string($value) ? trim($value) : ($defaults[$key] ?? '');
        }
        $options['title'] = $options['title'] ?: $defaults['title'];
        if (! preg_match('/^#[0-9a-fA-F]{6}$/', $options['color'])) $options['color'] = $defaults['color'];
        return ['layout' => $layout, 'options' => $options];
    }

    public static function logoDirectory(Tenant $tenant, int $contractId): string
    {
        return "tenants/{$tenant->id}/rnc/pdf-logos/contracts/{$contractId}";
    }

    public static function validLogoPath(Tenant $tenant, int $contractId, string $path): bool
    {
        $directory = preg_quote(self::logoDirectory($tenant, $contractId), '~');
        return (bool) preg_match('~^'.$directory.'/[a-zA-Z0-9-]+\.(png|jpe?g|webp)$~D', $path);
    }

    public static function forContract(Tenant $tenant, int $contractId): array
    {
        $setting = RncPdfSetting::where('tenant_id', $tenant->id)->where('contract_id', $contractId)->first();

        return self::configuration($setting?->layout ?? 'standard', $setting?->options ?? []);
    }
}
