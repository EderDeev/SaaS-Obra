<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Tenant\RdoController;
use App\Models\RdoDiario;
use App\Models\RdoEquipamentoCadastro;
use App\Models\RdoMaoObraCadastro;
use App\Models\RdoSubcontratadaCadastro;
use App\Models\Tenant;
use App\Support\DiarioObraPermissions;
use App\Support\RdoWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class MobileRdoController extends Controller
{
    public function submit(Request $request, int $rdoId): JsonResponse
    {
        $tenant = $request->attributes->get('mobile_tenant');
        abort_unless($tenant instanceof Tenant, 401);
        $rdo = RdoDiario::where('tenant_id', $tenant->id)->findOrFail($rdoId);
        DB::transaction(function () use ($request, $tenant, $rdo) {
            $locked = $rdo->newQuery()->whereKey($rdo->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($locked->status, RdoWorkflow::FILL_STATUSES, true), 409, 'Este RDO já saiu da etapa de preenchimento. Atualize os dados.');
            $request->merge(['action' => 'submit']);
            app(RdoController::class)->changeFlow($request, $tenant, $locked);
        });
        return response()->json([
            'message' => RdoWorkflow::savesWithoutApproval($rdo) ? 'RDO finalizado.' : 'RDO completo encaminhado para análise.',
            'status' => $rdo->fresh()->status,
        ]);
    }

    public function storeOffline(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('mobile_tenant');
        abort_unless($tenant instanceof Tenant, 401);
        if ($request->has('sections_json')) {
            $request->validate(['sections_json' => ['required', 'json']]);
            $request->merge(['secoes' => json_decode($request->input('sections_json'), true)]);
        }
        $data = $request->validate([
            'local_uuid' => ['required', 'string', 'max:120'],
            'rdo_diario_id' => ['required', 'integer'],
            'obra_id' => ['required', 'integer'],
            'filling_party' => ['required', Rule::in(RdoWorkflow::FILLING_PARTIES)],
            'reference_date' => ['required', 'date_format:Y-m-d'],
            'is_holiday' => ['sometimes', 'nullable', 'boolean'],
            'revision' => ['required', 'string', 'size:64'],
            'secoes' => ['required', 'array:clima,mao_obra,equipamentos,atividades,fotos,comentarios'],
            'secoes.*' => ['array'],
            'secoes.clima' => ['present', 'array'],
            'secoes.mao_obra' => ['present', 'array'],
            'secoes.equipamentos' => ['present', 'array'],
            'secoes.atividades' => ['present', 'array'],
            'secoes.fotos' => ['sometimes', 'array'],
            'secoes.comentarios' => ['present', 'array'],
            'fotos' => ['nullable', 'array', 'max:20'],
            'fotos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:12288'],
        ]);
        $rdo = RdoDiario::where('tenant_id', $tenant->id)->findOrFail($data['rdo_diario_id']);
        abort_unless(DiarioObraPermissions::can($request->user(), $tenant, DiarioObraPermissions::FILL_RDO, $rdo->contract), 403);
        $fingerprint = hash('sha256', json_encode([$data, collect($request->file('fotos', []))->map(fn ($file) => hash_file('sha256', $file->getRealPath()))]));
        $result = DB::transaction(function () use ($tenant, $request, $rdo, $data, $fingerprint) {
            $rdo = $rdo->newQuery()->whereKey($rdo->id)->lockForUpdate()->firstOrFail();
            $receipt = DB::table('rdo_mobile_syncs')->where('tenant_id', $tenant->id)->where('user_id', $request->user()->id)->where('local_uuid', $data['local_uuid'])->first();
            if ($receipt) {
                abort_unless(hash_equals($receipt->request_hash, $fingerprint), 409, 'Este envio já foi utilizado. Salve uma nova versão do rascunho.');

                return json_decode($receipt->response, true);
            }
            abort_unless($rdo->reference_date->format('Y-m-d') === $data['reference_date'], 422, 'A data não corresponde ao RDO.');
            $controller = app(RdoController::class);
            $caps = $controller->flowCapabilities($tenant, $rdo, $request->user(), $data['filling_party']);
            abort_unless($caps['can_edit'] && in_array($data['obra_id'], $caps['editable_obra_ids'], true), 403, 'Usuário sem responsabilidade para preencher esta parte e frente do RDO.');
            abort_unless(hash_equals(self::revision($rdo, $data['obra_id'], $data['filling_party']), $data['revision']), 409, 'Este preenchimento foi alterado no sistema. Atualize os dados antes de sincronizar para não sobrescrever alterações.');
            $controller->saveAllSections($request, $tenant, $rdo);
            $state = self::state($rdo->fresh(), $data['obra_id'], $data['filling_party']);
            $response = ['message' => 'RDO sincronizado com sucesso.', 'rdo' => ['id' => $rdo->id, 'status' => $rdo->status, ...$state]];
            DB::table('rdo_mobile_syncs')->insert(['tenant_id' => $tenant->id, 'user_id' => $request->user()->id, 'local_uuid' => $data['local_uuid'], 'request_hash' => $fingerprint, 'response' => json_encode($response), 'created_at' => now()]);

            return $response;
        });

        return response()->json($result, 201);
    }

    public static function revision(RdoDiario $rdo, int $obraId, string $party): string
    {
        $sections = $rdo->secoes()->where('obra_id', $obraId)->where('filling_party', $party)->orderBy('secao')->get(['id', 'secao', 'dados', 'updated_at'])->toArray();
        $legacySections = self::managerInspectionSections($rdo, $obraId, $party);
        $fallback = array_diff_key($legacySections, array_column($sections, 'dados', 'secao'));
        if ($fallback) {
            return hash('sha256', json_encode(['sections' => $sections, 'inspection' => $fallback]));
        }
        return hash('sha256', json_encode(
            $rdo->is_holiday === null || ! RdoWorkflow::canSetHoliday($rdo, $party)
                ? $sections
                : ['sections' => $sections, 'is_holiday' => $rdo->is_holiday]
        ));
    }

    public static function state(RdoDiario $rdo, int $obraId, string $party): array
    {
        $sections = $rdo->secoes()->where('obra_id', $obraId)->where('filling_party', $party)->get()->mapWithKeys(fn ($row) => [$row->secao => $row->dados])->all();
        $sections += self::managerInspectionSections($rdo, $obraId, $party);

        return ['activity_subjects' => \App\Support\RdoPdfTemplates::activitySubjects($rdo), 'is_holiday' => $rdo->is_holiday,
            'can_set_holiday' => RdoWorkflow::canSetHoliday($rdo, $party), 'revision' => self::revision($rdo, $obraId, $party), 'sections' => $sections,
            'photos' => collect(data_get($sections, 'fotos.arquivos', []))->map(fn ($photo, $i) => [
                'localId' => $photo['path'], 'serverPath' => $photo['path'], 'uri' => Storage::disk('public')->url($photo['path']),
                'comment' => $photo['comment'] ?? $photo['legenda'] ?? '', 'position' => $i + 1,
            ])->values()->all()];
    }

    private static function managerInspectionSections(RdoDiario $rdo, int $obraId, string $party): array
    {
        if (!RdoWorkflow::isManagerFieldRecord($rdo, $party)) return [];

        $record = $rdo->loadMissing('fiscalizacaoRegistros')->fiscalizacaoRegistros
            ->where('version_number', RdoWorkflow::inspectionVersion($rdo))->firstWhere('obra_id', $obraId);

        return $record ? RdoWorkflow::inspectionFieldSections($record) : [];
    }

    public static function bootstrapPayload(Tenant $tenant, mixed $user): array
    {
        $ids = DiarioObraPermissions::contractIdsFor($user, $tenant, DiarioObraPermissions::FILL_RDO);
        $query = RdoDiario::where('tenant_id', $tenant->id)->with(['configuracao.obras', 'contract', 'secoes'])
            ->whereIn('status', [...RdoWorkflow::FILL_STATUSES, RdoWorkflow::REVIEW_STATUSES['gerenciadora']])
            ->whereBetween('reference_date', [now()->subDays(15)->toDateString(), now()->addDays(15)->toDateString()])->orderByDesc('reference_date');
        if ($ids !== null) {
            $query->whereIn('contract_id', $ids);
        }
        $rdos = $query->get()->flatMap(function ($rdo) use ($tenant, $user) {
            if (RdoWorkflow::isReview($rdo->status) && !RdoWorkflow::managerFillsDuringReview($rdo)) {
                return [];
            }
            $items = [];
            foreach (RdoWorkflow::fieldParties($rdo) as $party) {
                if (! in_array($party, RdoWorkflow::FILLING_PARTIES, true)) {
                    continue;
                }
                $caps = app(RdoController::class)->flowCapabilities($tenant, $rdo, $user, $party);
                foreach ($rdo->configuracao?->obras ?? [] as $obra) {
                    if (! in_array((int) $obra->id, $caps['editable_obra_ids'], true)) {
                        continue;
                    }
                    $items[] = ['id' => $rdo->id, 'rdo_configuracao_id' => $rdo->rdo_configuracao_id, 'contract_id' => $rdo->contract_id,
                        'obra_id' => $obra->id, 'code' => $rdo->code, 'reference_date' => $rdo->reference_date->format('Y-m-d'), 'status' => $rdo->status,
                        'can_submit' => isset($caps['actions']['submit']), 'workflow' => RdoWorkflow::forNewDiary(RdoWorkflow::definition($rdo)), 'filling_party' => $party, 'party_label' => RdoWorkflow::PARTIES[$party],
                        'parallel_manager_filling' => RdoWorkflow::hasParallelManagerFilling($rdo),
                        'obra_label' => trim($obra->codigo.' - '.$obra->nome), 'contract_label' => trim($rdo->contract->code.' - '.$rdo->contract->name),
                        ...self::state($rdo, $obra->id, $party)];
                }
            }

            return $items;
        })->values();
        $contractIds = $rdos->pluck('contract_id')->unique();

        return ['rdo_diarios' => $rdos, 'rdo_contracts' => $tenant->contracts()->whereIn('id', $contractIds)->get(['id', 'code', 'name']),
            'rdo_mao_obra' => RdoMaoObraCadastro::where('tenant_id', $tenant->id)->whereIn('contract_id', $contractIds)->where('active', true)->orderBy('descricao')->get()->map(fn ($item) => ['id' => $item->id, 'contract_id' => $item->contract_id, 'label' => $item->descricao, 'meta' => $item->unidade]),
            'rdo_equipamentos' => RdoEquipamentoCadastro::where('tenant_id', $tenant->id)->whereIn('contract_id', $contractIds)->where('active', true)->orderBy('descricao')->get()->map(fn ($item) => ['id' => $item->id, 'contract_id' => $item->contract_id, 'label' => $item->descricao, 'meta' => $item->unidade]),
            'rdo_subcontratadas' => RdoSubcontratadaCadastro::where('tenant_id', $tenant->id)->whereIn('contract_id', $contractIds)->where('active', true)->orderBy('razao_social')->get()->map(fn ($item) => ['id' => $item->id, 'contract_id' => $item->contract_id, 'label' => $item->nome_fantasia ?: $item->razao_social, 'meta' => $item->cnpj])];
    }
}
