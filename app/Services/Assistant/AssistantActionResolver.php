<?php

namespace App\Services\Assistant;

use App\Models\Contract;
use App\Models\Disciplina;
use App\Models\Empresa;
use App\Models\Obra;
use App\Models\Tenant;
use App\Models\User;
use App\Support\ActivityPermissions;
use App\Support\RncPermissions;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AssistantActionResolver
{
    private const ACTIVITY_CATEGORIES = [
        'project', 'quality', 'budget', 'measurement', 'documentation', 'service_order',
        'construction_diary', 'contract', 'administrative', 'field', 'client',
    ];

    public function __construct(private readonly AssistantAccessScope $access) {}

    /**
     * @param  array<string, mixed>|null  $proposal
     * @param  array<int, array<string, mixed>>  $sources
     * @return array<string, mixed>|null
     */
    public function resolve(User $user, Tenant $tenant, ?array $proposal, array $sources): ?array
    {
        if (! $proposal || ! is_string($proposal['type'] ?? null)) {
            return null;
        }

        return match ($proposal['type']) {
            'navigate' => $this->navigation($user, $tenant, $proposal, $sources),
            'draft' => $this->draft($user, $tenant, $proposal),
            default => null,
        };
    }

    /** @return array<string, mixed>|null */
    private function navigation(User $user, Tenant $tenant, array $proposal, array $sources): ?array
    {
        $sourceId = Str::upper(trim((string) ($proposal['source_id'] ?? '')));
        $source = collect($sources)->firstWhere('id', $sourceId);

        if (! $source || ! filled($source['url'] ?? null)) {
            return null;
        }

        $url = (string) $source['url'];
        $filters = is_array($proposal['filters'] ?? null) ? $proposal['filters'] : [];
        $query = array_filter([
            'contract_id' => filled($filters['contract_code'] ?? null)
                ? $this->resolveContract(
                    $this->accessibleContracts($user, $tenant),
                    ['contract_code' => $filters['contract_code']]
                )?->id
                : null,
            'status' => $this->text($filters['status'] ?? '', 50),
            'search' => $this->text($filters['search'] ?? '', 120),
        ], fn ($value): bool => filled($value));

        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?').http_build_query($query);
        }

        return [
            'type' => 'navigate',
            'label' => 'Abrir '.Str::limit((string) $source['title'], 70),
            'url' => $url,
        ];
    }

    /** @return array<string, mixed>|null */
    private function draft(User $user, Tenant $tenant, array $proposal): ?array
    {
        $draftType = (string) ($proposal['draft_type'] ?? '');
        $fields = is_array($proposal['fields'] ?? null) ? $proposal['fields'] : [];

        return match ($draftType) {
            'activity' => $this->activityDraft($user, $tenant, $fields),
            'rnc' => $this->rncDraft($user, $tenant, $fields),
            'service_order' => $this->serviceOrderDraft($user, $tenant, $fields),
            default => null,
        };
    }

    /** @return array<string, mixed>|null */
    private function activityDraft(User $user, Tenant $tenant, array $fields): ?array
    {
        $contracts = $this->accessibleContracts($user, $tenant)
            ->filter(fn (Contract $contract): bool => ActivityPermissions::can(
                $user,
                $tenant,
                ActivityPermissions::CREATE,
                $contract
            ));
        $contract = $this->resolveContract($contracts, $fields);

        if (! $contract) {
            return null;
        }

        $payload = [
            'contract_id' => (string) $contract->id,
            'title' => $this->text($fields['title'] ?? '', 255),
            'description' => $this->text($fields['description'] ?? '', 5000),
            'category' => $this->enum($fields['category'] ?? null, self::ACTIVITY_CATEGORIES, 'project'),
            'visibility' => $this->enum($fields['visibility'] ?? null, ['public', 'restricted'], 'public'),
            'priority' => $this->enum($fields['priority'] ?? null, ['low', 'normal', 'high', 'urgent'], 'normal'),
            'due_date' => $this->date($fields['due_date'] ?? null) ?? '',
        ];

        return $this->draftAction(
            'activity',
            'Revisar atividade',
            route('tenant.activities.index', $tenant),
            $payload,
            "Atividade em {$contract->code}"
        );
    }

    /** @return array<string, mixed>|null */
    private function rncDraft(User $user, Tenant $tenant, array $fields): ?array
    {
        $contracts = $this->accessibleContracts($user, $tenant)
            ->filter(fn (Contract $contract): bool => RncPermissions::can(
                $user,
                $tenant,
                RncPermissions::CREATE,
                $contract
            ));
        $contract = $this->resolveContract($contracts, $fields);

        if (! $contract) {
            return null;
        }

        $obra = $this->resolveObra($tenant, $contract, $fields);
        $disciplina = $this->resolveDisciplina($tenant, $contract, $fields['disciplina'] ?? null);
        $contratante = $this->resolveEmpresa($tenant, $contract, $fields['contratante'] ?? null, 'cliente');
        $contratada = $this->resolveEmpresa($tenant, $contract, $fields['contratada'] ?? null, 'construtora');
        $openedAt = $this->date($fields['opened_at'] ?? null) ?? now()->toDateString();
        $payload = array_filter([
            'obra_id' => $obra ? (string) $obra->id : null,
            'contratante_empresa_id' => $contratante ? (string) $contratante->id : null,
            'contratada_empresa_id' => $contratada ? (string) $contratada->id : null,
            'opened_at' => $openedAt,
            'disciplina_id' => $disciplina ? (string) $disciplina->id : null,
            'gravidade' => $this->enum($fields['gravidade'] ?? null, ['Leve', 'Média', 'Grave', 'Gravíssima'], 'Leve'),
            'descricao_problema' => $this->text($fields['descricao_problema'] ?? '', 10000),
            'observacao' => $this->text($fields['observacao'] ?? '', 10000),
            'acoes_corretivas_recomendadas' => $this->text($fields['acoes_corretivas_recomendadas'] ?? '', 10000),
            'prazo_resposta_acao_corretiva' => $this->date($fields['prazo_resposta_acao_corretiva'] ?? null),
        ], fn ($value): bool => $value !== null);

        return $this->draftAction(
            'rnc',
            'Revisar RNC',
            route('tenant.qualidade.rnc.create', $tenant),
            $payload,
            "RNC em {$contract->code}"
        );
    }

    /** @return array<string, mixed>|null */
    private function serviceOrderDraft(User $user, Tenant $tenant, array $fields): ?array
    {
        $contract = $this->resolveContract($this->accessibleContracts($user, $tenant), $fields);

        if (! $contract) {
            return null;
        }

        $obra = $this->resolveObra($tenant, $contract, $fields);
        $gerenciadora = $this->resolveEmpresa($tenant, $contract, $fields['gerenciadora'] ?? null, 'gerenciadora');
        $construtora = $this->resolveEmpresa($tenant, $contract, $fields['construtora'] ?? null, 'construtora');
        $payload = array_filter([
            'contract_id' => (string) $contract->id,
            'obra_id' => $obra ? (string) $obra->id : null,
            'gerenciadora_empresa_id' => $gerenciadora ? (string) $gerenciadora->id : null,
            'construtora_empresa_id' => $construtora ? (string) $construtora->id : null,
            'titulo' => $this->text($fields['titulo'] ?? '', 255),
            'descricao' => $this->text($fields['descricao'] ?? '', 10000),
            'prazo_execucao' => $this->date($fields['prazo_execucao'] ?? null),
            'custo_previsto' => $this->text($fields['custo_previsto'] ?? '', 50),
            'custo_observacao' => $this->text($fields['custo_observacao'] ?? '', 5000),
        ], fn ($value): bool => $value !== null);

        return $this->draftAction(
            'service_order',
            'Revisar OS',
            route('tenant.ordem-servico.os.index', ['tenant' => $tenant, 'contract_id' => $contract->id]),
            $payload,
            "OS em {$contract->code}"
        );
    }

    /** @return Collection<int, Contract> */
    private function accessibleContracts(User $user, Tenant $tenant): Collection
    {
        return Contract::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('id', $this->access->contractIds($user, $tenant))
            ->orderBy('code')
            ->get(['id', 'tenant_id', 'code', 'name']);
    }

    /** @param Collection<int, Contract> $contracts */
    private function resolveContract(Collection $contracts, array $fields): ?Contract
    {
        $reference = $this->normalize($fields['contract_code'] ?? $fields['contract'] ?? '');

        if ($reference === '') {
            return $contracts->count() === 1 ? $contracts->first() : null;
        }

        return $contracts->first(fn (Contract $contract): bool => in_array($reference, [
            $this->normalize($contract->code),
            $this->normalize($contract->name),
            $this->normalize($contract->code.' '.$contract->name),
        ], true));
    }

    private function resolveObra(Tenant $tenant, Contract $contract, array $fields): ?Obra
    {
        $obras = Obra::query()
            ->where('tenant_id', $tenant->id)
            ->where('contract_id', $contract->id)
            ->orderBy('codigo')
            ->get(['id', 'codigo', 'nome']);
        $reference = $this->normalize($fields['obra_code'] ?? $fields['obra'] ?? '');

        if ($reference === '') {
            return $obras->count() === 1 ? $obras->first() : null;
        }

        return $obras->first(fn (Obra $obra): bool => in_array($reference, [
            $this->normalize($obra->codigo),
            $this->normalize($obra->nome),
            $this->normalize($obra->codigo.' '.$obra->nome),
        ], true));
    }

    private function resolveDisciplina(Tenant $tenant, Contract $contract, mixed $reference): ?Disciplina
    {
        $needle = $this->normalize($reference);
        $disciplinas = Disciplina::query()
            ->where('tenant_id', $tenant->id)
            ->where('contract_id', $contract->id)
            ->get(['id', 'sigla', 'nome']);

        if ($needle === '') {
            return $disciplinas->count() === 1 ? $disciplinas->first() : null;
        }

        return $disciplinas->first(fn (Disciplina $disciplina): bool => in_array($needle, [
            $this->normalize($disciplina->sigla),
            $this->normalize($disciplina->nome),
            $this->normalize($disciplina->sigla.' '.$disciplina->nome),
        ], true));
    }

    private function resolveEmpresa(Tenant $tenant, Contract $contract, mixed $reference, string $fallbackType): ?Empresa
    {
        $empresas = Empresa::query()
            ->where('tenant_id', $tenant->id)
            ->where('contract_id', $contract->id)
            ->with('tipoEmpresa:id,nome')
            ->get(['id', 'contract_id', 'tipo_empresa_id', 'nome', 'sigla']);
        $needle = $this->normalize($reference);

        if ($needle !== '') {
            return $empresas->first(fn (Empresa $empresa): bool => in_array($needle, [
                $this->normalize($empresa->nome),
                $this->normalize($empresa->sigla),
                $this->normalize($empresa->sigla.' '.$empresa->nome),
            ], true));
        }

        $matchingType = $empresas->filter(fn (Empresa $empresa): bool => str_contains(
            $this->normalize($empresa->tipoEmpresa?->nome),
            $this->normalize($fallbackType)
        ));

        return $matchingType->count() === 1 ? $matchingType->first() : null;
    }

    /** @return array<string, mixed> */
    private function draftAction(string $draftType, string $label, string $url, array $payload, string $summary): array
    {
        return compact('label', 'url', 'payload', 'summary') + [
            'type' => 'draft',
            'draft_type' => $draftType,
        ];
    }

    private function text(mixed $value, int $limit): string
    {
        return Str::limit(trim((string) $value), $limit, '');
    }

    private function enum(mixed $value, array $allowed, string $default): string
    {
        return in_array($value, $allowed, true) ? (string) $value : $default;
    }

    private function date(mixed $value): ?string
    {
        $value = trim((string) $value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }

    private function normalize(mixed $value): string
    {
        return Str::lower(trim(preg_replace('/\s+/', ' ', Str::ascii((string) $value)) ?? ''));
    }
}
