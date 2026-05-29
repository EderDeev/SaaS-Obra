<?php

namespace App\Support;

class TenantRoles
{
    public const OWNER = 'tenant_owner';
    public const ADMIN = 'tenant_admin';

    public const GROUPS = [
        'Sistema' => [
            self::OWNER => 'Proprietario',
            self::ADMIN => 'Administrador',
        ],
        'Gestao' => [
            'diretor_engenharia' => 'Diretor de Engenharia',
            'diretor_operacoes' => 'Diretor de Operacoes',
            'gerente_obras' => 'Gerente de Obras',
            'gerente_contrato' => 'Gerente de Contrato',
        ],
        'Coordenacao' => [
            'coordenador_obras' => 'Coordenador de Obras',
            'coordenador_projetos' => 'Coordenador de Projetos',
            'coordenador_planejamento' => 'Coordenador de Planejamento',
            'coordenador_qualidade' => 'Coordenador de Qualidade',
        ],
        'Engenharia' => [
            'engenheiro_planejamento' => 'Engenheiro de Planejamento',
            'engenheiro_custos' => 'Engenheiro de Custos',
            'engenheiro_qualidade' => 'Engenheiro de Qualidade',
            'engenheiro_seguranca_trabalho' => 'Engenheiro de Seguranca do Trabalho',
            'engenheiro_campo' => 'Engenheiro de Campo',
            'engenheiro_projetos' => 'Engenheiro de Projetos',
            'engenheiro_residente' => 'Engenheiro Residente',
            'engenheiro_medicoes' => 'Engenheiro de Medicoes',
        ],
        'Supervisao de campo' => [
            'supervisor_obra' => 'Supervisor de Obra',
            'mestre_obras' => 'Mestre de Obras',
            'encarregado_obra' => 'Encarregado de Obra',
            'encarregado_frente_servico' => 'Encarregado de Frente de Servico',
        ],
        'Tecnicos' => [
            'tecnico_edificacoes' => 'Tecnico de Edificacoes',
            'tecnico_seguranca_trabalho' => 'Tecnico de Seguranca do Trabalho',
            'tecnico_qualidade' => 'Tecnico de Qualidade',
            'tecnico_planejamento' => 'Tecnico de Planejamento',
            'tecnico_meio_ambiente' => 'Tecnico de Meio Ambiente',
            'tecnico_topografia' => 'Tecnico de Topografia',
        ],
        'Administrativo' => [
            'administrativo_obra' => 'Administrativo de Obra',
            'assistente_administrativo' => 'Assistente Administrativo',
            'analista_financeiro' => 'Analista Financeiro',
            'analista_contratos' => 'Analista de Contratos',
            'compras_suprimentos' => 'Compras e Suprimentos',
            'controladoria' => 'Controladoria',
            'controlador_documentos' => 'Controlador de Documentos',
            'almoxarife' => 'Almoxarife',
        ],
    ];

    private const LEGACY_LABELS = [
        'obras_manager' => 'Gestor de Obras',
        'engineer' => 'Engenheiro',
        'financial' => 'Financeiro',
        'viewer' => 'Visualizador',
    ];

    public static function groups(): array
    {
        return self::GROUPS;
    }

    public static function all(): array
    {
        return array_keys(self::labels());
    }

    public static function labels(): array
    {
        $groups = array_values(self::GROUPS);
        $groups[] = self::LEGACY_LABELS;

        return array_merge(...$groups);
    }

    public static function label(?string $role): string
    {
        if (! $role) {
            return 'Participante';
        }

        return self::labels()[$role] ?? $role;
    }

    public static function defaultRole(): string
    {
        return 'engenheiro_planejamento';
    }

    public static function isTenantAdmin(?string $role): bool
    {
        return in_array($role, [self::OWNER, self::ADMIN], true);
    }

    public static function canManageContracts(?string $role): bool
    {
        return in_array($role, [
            self::OWNER,
            self::ADMIN,
            'obras_manager',
            'diretor_engenharia',
            'diretor_operacoes',
            'gerente_obras',
            'gerente_contrato',
            'coordenador_obras',
        ], true);
    }

    public static function managementRoles(): array
    {
        return [
            'obras_manager',
            'diretor_engenharia',
            'diretor_operacoes',
            'gerente_obras',
            'gerente_contrato',
        ];
    }

    public static function coordinationRoles(): array
    {
        return [
            'coordenador_obras',
            'coordenador_projetos',
            'coordenador_planejamento',
            'coordenador_qualidade',
        ];
    }

    public static function engineeringRoles(): array
    {
        return [
            'engineer',
            'engenheiro_planejamento',
            'engenheiro_custos',
            'engenheiro_qualidade',
            'engenheiro_seguranca_trabalho',
            'engenheiro_campo',
            'engenheiro_projetos',
            'engenheiro_residente',
            'engenheiro_medicoes',
        ];
    }

    public static function supervisionRoles(): array
    {
        return [
            'supervisor_obra',
            'mestre_obras',
            'encarregado_obra',
            'encarregado_frente_servico',
        ];
    }

    public static function technicalRoles(): array
    {
        return [
            'tecnico_edificacoes',
            'tecnico_seguranca_trabalho',
            'tecnico_qualidade',
            'tecnico_planejamento',
            'tecnico_meio_ambiente',
            'tecnico_topografia',
        ];
    }

    public static function administrativeRoles(): array
    {
        return [
            'financial',
            'viewer',
            'administrativo_obra',
            'assistente_administrativo',
            'analista_financeiro',
            'analista_contratos',
            'compras_suprimentos',
            'controladoria',
            'controlador_documentos',
            'almoxarife',
        ];
    }
}
