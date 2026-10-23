<?php

namespace App\Http\Controllers\Tenant\Qualidade;

use App\Http\Controllers\Controller;
use App\Models\InstrucaoCampo;
use App\Models\InstrucaoCampoPhoto;
use App\Models\InstrucaoCampoResponsavel;
use App\Models\Tenant;
use App\Notifications\IcFlowNotification;
use App\Support\IcPermissions as Permissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class InstrucaoCampoController extends Controller
{
    private const RELATIONS = ['contract:id,code,name', 'obra', 'disciplina', 'contratante', 'contratada', 'creator:id,name', 'closedBy:id,name', 'photos', 'events.user:id,name'];

    private function contracts(Request $request, Tenant $tenant, string $permission)
    {
        abort_unless(Permissions::canAny($request->user(), $tenant, $permission), 403);
        $ids = Permissions::contractIdsFor($request->user(), $tenant, $permission);

        return $tenant->contracts()->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->orderBy('code')->get(['id', 'code', 'name']);
    }

    private function authorizeIc(Request $request, Tenant $tenant, InstrucaoCampo $ic, string $permission): void
    {
        abort_unless((int) $ic->tenant_id === (int) $tenant->id, 404);
        abort_unless(Permissions::can($request->user(), $tenant, $permission, $ic->contract), 403);
    }

    private function permissions(Request $request, Tenant $tenant, InstrucaoCampo $ic): array
    {
        return array_fill_keys(Permissions::permissionsFor($request->user(), $tenant, $ic->contract), true);
    }

    public function index(Request $request, Tenant $tenant)
    {
        $contracts = $this->contracts($request, $tenant, Permissions::VIEW);
        $obras = $tenant->obras()->whereIn('contract_id', $contracts->pluck('id'))->orderBy('nome')->get(['id', 'contract_id', 'codigo', 'nome']);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'contract_id' => ['nullable', Rule::in($contracts->pluck('id')->all())],
            'obra_id' => ['nullable', Rule::in($obras->pluck('id')->all())],
            'status' => ['nullable', Rule::in(array_keys(InstrucaoCampo::STATUSES))],
        ]);
        if (! empty($filters['contract_id']) && ! empty($filters['obra_id']) && (int) $obras->firstWhere('id', $filters['obra_id'])->contract_id !== (int) $filters['contract_id']) {
            throw ValidationException::withMessages(['obra_id' => 'Selecione uma obra do contrato informado.']);
        }
        $query = $tenant->instrucoesCampo()->whereIn('contract_id', $contracts->pluck('id'))
            ->when($filters['contract_id'] ?? null, fn ($q, $id) => $q->where('contract_id', $id))
            ->when($filters['obra_id'] ?? null, fn ($q, $id) => $q->where('obra_id', $id))
            ->when(trim($filters['q'] ?? ''), function ($q, $term) {
                $q->where(function ($q) use ($term) {
                    $like = '%'.mb_strtolower($term).'%';
                    $q->whereRaw('LOWER(instrucao) LIKE ?', [$like])
                        ->orWhereHas('contract', fn ($q) => $q->whereRaw('LOWER(code) LIKE ?', [$like])->orWhereRaw('LOWER(name) LIKE ?', [$like]))
                        ->orWhereHas('obra', fn ($q) => $q->whereRaw('LOWER(nome) LIKE ?', [$like])->orWhereRaw('LOWER(codigo) LIKE ?', [$like]));
                    if (preg_match('/^(?:IC\s*)?(\d+)(?:[-\/](\d{4}))?$/i', $term, $number)) {
                        $q->orWhere(fn ($q) => $q->where('sequence_number', (int) $number[1])->when($number[2] ?? null, fn ($q, $year) => $q->where('sequence_year', $year)));
                    }
                });
            });
        $counts = (clone $query)->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');
        $ics = $query->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))->with(self::RELATIONS)->withCount('photos')
            ->orderByDesc('id')->paginate(10)->withQueryString()->through(function ($ic) use ($request, $tenant) {
                $ic->setAttribute('user_permissions', $this->permissions($request, $tenant, $ic));

                return $ic;
            });

        return Inertia::render('Tenant/Qualidade/InstrucaoCampo/Index', compact('tenant', 'contracts', 'obras', 'filters', 'ics') + [
            'counts' => ['all' => (int) $counts->sum(), ...$counts->all()], 'statuses' => InstrucaoCampo::STATUSES,
            'canCreate' => Permissions::canAny($request->user(), $tenant, Permissions::CREATE),
            'canManageResponsibles' => Permissions::canAny($request->user(), $tenant, Permissions::RESPONSIBLES),
        ]);
    }

    public function dashboard(Request $request, Tenant $tenant)
    {
        $contracts = $this->contracts($request, $tenant, Permissions::DASHBOARD);
        $filters = $request->validate(['contract_id' => ['nullable', Rule::in($contracts->pluck('id')->all())]]);
        $query = $tenant->instrucoesCampo()->whereIn('contract_id', $contracts->pluck('id'))
            ->when($filters['contract_id'] ?? null, fn ($q, $id) => $q->where('contract_id', $id));
        $counts = (clone $query)->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');
        $monthly = (clone $query)->whereBetween('opened_at', [now()->startOfMonth()->subMonths(5)->toDateString(), now()->endOfMonth()->toDateString()])
            ->selectRaw('opened_at, COUNT(*) AS total')->groupBy('opened_at')->get();
        $months = collect(range(5, 0))->map(function ($offset) use ($monthly) {
            $month = now()->startOfMonth()->subMonths($offset);

            return ['label' => $month->translatedFormat('M/y'), 'total' => (int) $monthly
                ->filter(fn ($row) => $row->opened_at->format('Y-m') === $month->format('Y-m'))->sum('total')];
        });
        $byContract = (clone $query)->selectRaw('contract_id, COUNT(*) AS total')->groupBy('contract_id')
            ->orderByDesc('total')->orderBy('contract_id')->limit(10)->get()
            ->map(fn ($row) => ['label' => $contracts->firstWhere('id', $row->contract_id)?->code, 'total' => (int) $row->total]);
        $recent = (clone $query)->select(['id', 'tenant_id', 'contract_id', 'obra_id', 'sequence_number', 'sequence_year', 'opened_at', 'status'])
            ->with(['contract:id,code,name', 'obra:id,codigo,nome'])->orderByDesc('id')->limit(6)->get()
            ->map(function ($ic) use ($request, $tenant) {
                $ic->setAttribute('can_view', Permissions::can($request->user(), $tenant, Permissions::VIEW, $ic->contract));

                return $ic;
            });

        return Inertia::render('Tenant/Qualidade/InstrucaoCampo/Dashboard', [
            'tenant' => $tenant, 'contracts' => $contracts, 'filters' => $filters,
            'counts' => ['all' => (int) $counts->sum(), ...$counts->all()],
            'statuses' => InstrucaoCampo::STATUSES, 'months' => $months, 'byContract' => $byContract, 'recent' => $recent,
        ]);
    }

    private function form(Request $request, Tenant $tenant, string $permission, ?InstrucaoCampo $ic = null)
    {
        $contracts = $this->contracts($request, $tenant, $permission);

        return Inertia::render('Tenant/Qualidade/InstrucaoCampo/Create', [
            'tenant' => $tenant, 'ic' => $ic?->load(self::RELATIONS), 'contracts' => $contracts,
            'obras' => $tenant->obras()->whereIn('contract_id', $contracts->pluck('id'))->orderBy('nome')->get(['id', 'contract_id', 'codigo', 'nome']),
            'empresas' => $tenant->empresas()->whereIn('contract_id', $contracts->pluck('id'))->orderBy('nome')->get(['id', 'contract_id', 'nome']),
            'disciplinas' => $tenant->disciplinas()->whereIn('contract_id', $contracts->pluck('id'))->orderBy('nome')->get(['id', 'contract_id', 'nome', 'sigla']),
        ]);
    }

    public function create(Request $request, Tenant $tenant)
    {
        return $this->form($request, $tenant, Permissions::CREATE);
    }

    public function edit(Request $request, Tenant $tenant, InstrucaoCampo $ic)
    {
        $this->authorizeIc($request, $tenant, $ic, Permissions::EDIT);
        abort_unless($ic->status === 'draft', 409, 'A IC só pode ser editada enquanto é rascunho.');

        return $this->form($request, $tenant, Permissions::EDIT, $ic);
    }

    private function data(Request $request, Tenant $tenant, string $permission): array
    {
        $contracts = $this->contracts($request, $tenant, $permission);
        $data = $request->validate([
            'contract_id' => ['required', Rule::in($contracts->pluck('id')->all())],
            'obra_id' => ['required', 'integer'], 'disciplina_id' => ['required', 'integer'],
            'contratante_empresa_id' => ['required', 'integer'], 'contratada_empresa_id' => ['required', 'integer'],
            'opened_at' => ['required', 'date_format:Y-m-d'], 'gravidade' => ['required', Rule::in(['Leve', 'Média', 'Grave', 'Gravíssima'])],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'], 'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'instrucao' => ['required', 'string', 'max:10000', function ($key, $value, $fail) {
                if (! trim($value)) {
                    $fail('Informe a instrução.');
                }
            }],
            'project_document_id' => ['prohibited'], 'project_document_ids' => ['prohibited'], 'prazo_resposta_acao_corretiva' => ['prohibited'],
            ...$this->photoRules(),
            'remove_photos' => ['nullable', 'array', 'max:12'], 'remove_photos.*' => ['integer', 'distinct'],
            'existing_photos' => ['nullable', 'array', 'max:12'],
            'existing_photos.*.id' => ['required', 'integer', 'distinct'],
            'existing_photos.*.comment' => ['nullable', 'string', 'max:1000'],
            'existing_photos.*.position' => ['required', 'integer', 'min:1', 'max:999', 'distinct'],
        ], [], ['instrucao' => 'instrução', 'contract_id' => 'contrato', 'obra_id' => 'obra', 'disciplina_id' => 'disciplina', 'opened_at' => 'data de abertura', 'photos' => 'fotos']);
        foreach (['obra_id' => 'obras', 'disciplina_id' => 'disciplinas', 'contratante_empresa_id' => 'empresas', 'contratada_empresa_id' => 'empresas'] as $field => $relation) {
            if (! $tenant->$relation()->where('contract_id', $data['contract_id'])->whereKey($data[$field])->exists()) {
                throw ValidationException::withMessages([$field => 'Selecione um registro vinculado a este contrato.']);
            }
        }

        return collect($data)->except(['photos', 'photo_comments', 'photo_positions', 'remove_photos', 'existing_photos'])->all();
    }

    private function photoRules(): array
    {
        return ['photos' => ['nullable', 'array', 'max:12'], 'photos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'photo_comments' => ['nullable', 'array'], 'photo_comments.*' => ['nullable', 'string', 'max:1000'],
            'photo_positions' => ['nullable', 'array', 'max:12'], 'photo_positions.*' => ['required', 'integer', 'min:1', 'max:999', 'distinct']];
    }

    private function photos(Request $request, Tenant $tenant, InstrucaoCampo $ic, ?int $eventId = null): void
    {
        $positions = $request->input('photo_positions', []);
        if ($positions && count($positions) !== count($request->file('photos', []))) {
            throw ValidationException::withMessages(['photos' => 'Informe uma posição para cada nova foto.']);
        }
        $position = (int) $ic->photos()->max('position');
        foreach ($request->file('photos', []) as $i => $file) {
            $ic->photos()->create(['tenant_id' => $tenant->id, 'event_id' => $eventId,
                'path' => $file->store("tenants/{$tenant->id}/ic/{$ic->id}", 'local'),
                'original_name' => $file->getClientOriginalName(), 'mime_type' => $file->getMimeType(),
                'position' => $positions[$i] ?? ++$position, 'comment' => $request->input("photo_comments.$i")]);
        }
    }

    private function event(Request $request, InstrucaoCampo $ic, string $type, ?string $comment = null)
    {
        return $ic->events()->create(['tenant_id' => $ic->tenant_id, 'user_id' => $request->user()->id, 'type' => $type, 'comment' => $comment]);
    }

    public function store(Request $request, Tenant $tenant)
    {
        $data = $this->data($request, $tenant, Permissions::CREATE);
        if ($request->filled('existing_photos') || $request->filled('remove_photos')) {
            throw ValidationException::withMessages(['photos' => 'Uma nova IC deve conter apenas novas fotos.']);
        }
        $ic = DB::transaction(function () use ($request, $tenant, $data) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $year = (int) substr($data['opened_at'], 0, 4);
            $number = 1 + (int) $tenant->instrucoesCampo()->withTrashed()->where('sequence_year', $year)->max('sequence_number');
            $ic = $tenant->instrucoesCampo()->create($data + ['sequence_year' => $year, 'sequence_number' => $number, 'created_by_id' => $request->user()->id]);
            $this->photos($request, $tenant, $ic);
            $this->event($request, $ic, 'created');

            return $ic;
        });

        return to_route('tenant.qualidade.ic.show', [$tenant, $ic])->with('success', 'IC salva como rascunho.');
    }

    public function update(Request $request, Tenant $tenant, InstrucaoCampo $ic)
    {
        $this->authorizeIc($request, $tenant, $ic, Permissions::EDIT);
        $data = $this->data($request, $tenant, Permissions::EDIT);
        DB::transaction(function () use ($request, $tenant, $ic, $data) {
            $ic = InstrucaoCampo::whereKey($ic->id)->lockForUpdate()->firstOrFail();
            abort_unless($ic->status === 'draft', 409, 'A IC só pode ser editada enquanto é rascunho.');
            abort_unless((int) $data['contract_id'] === (int) $ic->contract_id, 422, 'O contrato de uma IC já criada não pode ser alterado.');
            $ids = $request->input('remove_photos', []);
            $photos = $ic->photos()->whereNull('event_id')->whereIn('id', $ids)->get();
            if ($photos->count() !== count($ids)) {
                throw ValidationException::withMessages(['remove_photos' => 'Selecione apenas fotos da abertura desta IC.']);
            }
            if ($ic->photos()->count() - $photos->count() + count($request->file('photos', [])) > 12) {
                throw ValidationException::withMessages(['photos' => 'A abertura permite até 12 fotos.']);
            }
            $remaining = $ic->photos()->whereNull('event_id')->whereNotIn('id', $ids)->get()->keyBy('id');
            $updates = collect($request->input('existing_photos', []))->keyBy('id');
            if ($updates->keys()->diff($remaining->keys())->isNotEmpty()
                || ($request->has('existing_photos') && $remaining->keys()->diff($updates->keys())->isNotEmpty())) {
                throw ValidationException::withMessages(['photos' => 'Selecione apenas as fotos da abertura desta IC.']);
            }
            $positions = $remaining->map(fn ($photo) => (int) ($updates->get($photo->id)['position'] ?? $photo->position))
                ->concat(array_map('intval', $request->input('photo_positions', [])));
            if ($positions->unique()->count() !== $positions->count()) {
                throw ValidationException::withMessages(['photos' => 'Cada foto deve ter uma posição diferente.']);
            }
            foreach ($updates as $id => $metadata) {
                $remaining->get($id)->update(['comment' => $metadata['comment'] ?? null, 'position' => $metadata['position']]);
            }
            $ic->update($data);
            foreach ($photos as $photo) {
                $photo->delete();
            } // Retain files until the transaction commits.
            $this->photos($request, $tenant, $ic);
            $this->event($request, $ic, 'updated');
            DB::afterCommit(fn () => $photos->each(fn ($photo) => Storage::disk('local')->delete($photo->path)));
        });

        return to_route('tenant.qualidade.ic.show', [$tenant, $ic])->with('success', 'Rascunho atualizado.');
    }

    public function show(Request $request, Tenant $tenant, InstrucaoCampo $ic)
    {
        $this->authorizeIc($request, $tenant, $ic, Permissions::VIEW);

        return Inertia::render('Tenant/Qualidade/InstrucaoCampo/Show', ['tenant' => $tenant, 'ic' => $ic->load(self::RELATIONS),
            'permissions' => $this->permissions($request, $tenant, $ic), 'statuses' => InstrucaoCampo::STATUSES]);
    }

    private function recipients(Tenant $tenant, InstrucaoCampo $ic)
    {
        return InstrucaoCampoResponsavel::where('tenant_id', $tenant->id)->where('contract_id', $ic->contract_id)->where('status', 'active')
            ->with('user')->get()->filter(fn ($r) => $r->user && Permissions::can($r->user, $tenant, Permissions::VIEW, $ic->contract));
    }

    private function notifyFlow(Request $request, InstrucaoCampo $ic, $recipients, string $event): void
    {
        Notification::send($recipients->pluck('user')->unique('id'), new IcFlowNotification($ic, $request->user(), $event));
    }

    public function notify(Request $request, Tenant $tenant, InstrucaoCampo $ic)
    {
        $this->authorizeIc($request, $tenant, $ic, Permissions::NOTIFY);
        DB::transaction(function () use ($request, $tenant, $ic) {
            $ic = InstrucaoCampo::whereKey($ic->id)->lockForUpdate()->firstOrFail();
            abort_unless($ic->status === 'draft', 409, 'Esta IC já foi comunicada.');
            $recipients = $this->recipients($tenant, $ic);
            foreach (Permissions::responsibilityTypes() as $type) {
                if (! $recipients->contains('responsibility_type', $type)) {
                    throw ValidationException::withMessages(['responsaveis' => 'Cadastre engenheiro Concremat, construtora e cliente com acesso ao contrato antes de comunicar a IC.']);
                }
            }
            $ic->update(['status' => 'notified', 'notified_at' => now()]);
            $this->event($request, $ic, 'notified');
            $this->notifyFlow($request, $ic, $recipients, 'notified');
        });

        return back()->with('success', 'IC comunicada aos envolvidos.');
    }

    public function correction(Request $request, Tenant $tenant, InstrucaoCampo $ic)
    {
        $this->authorizeIc($request, $tenant, $ic, Permissions::CORRECTIVE_ACTION);
        $data = $request->validate(['comment' => ['required', 'string', 'max:10000'], ...$this->photoRules()]);
        if (! trim($data['comment'])) {
            throw ValidationException::withMessages(['comment' => 'Descreva a correção realizada.']);
        }
        DB::transaction(function () use ($request, $tenant, $ic, $data) {
            $ic = InstrucaoCampo::whereKey($ic->id)->lockForUpdate()->firstOrFail();
            abort_unless($ic->status === 'notified', 409, 'A IC não está aguardando correção.');
            $event = $this->event($request, $ic, 'correction_submitted', $data['comment']);
            $this->photos($request, $tenant, $ic, $event->id);
            $ic->update(['status' => 'correction_submitted']);
            $this->notifyFlow($request, $ic, $this->recipients($tenant, $ic), 'correction_submitted');
        });

        return back()->with('success', 'Correção enviada para verificação da Concremat.');
    }

    public function close(Request $request, Tenant $tenant, InstrucaoCampo $ic)
    {
        $this->authorizeIc($request, $tenant, $ic, Permissions::REVIEW);
        $data = $request->validate(['comment' => ['required', 'string', 'max:10000'], 'decision' => ['required', Rule::in(['resolved', 'return', 'rnc_required'])]]);
        if (! trim($data['comment'])) {
            throw ValidationException::withMessages(['comment' => 'Informe o comentário da verificação.']);
        }
        DB::transaction(function () use ($request, $tenant, $ic, $data) {
            $ic = InstrucaoCampo::whereKey($ic->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($ic->status, ['notified', 'correction_submitted'], true), 409, 'Esta IC não está em acompanhamento.');
            $status = match ($data['decision']) {
                'resolved' => 'closed', 'return' => 'notified', default => 'rnc_required'
            };
            if ($data['decision'] === 'return') {
                abort_unless($ic->status === 'correction_submitted', 409, 'Não há correção para devolver.');
            }
            $ic->update(['status' => $status, 'closing_comment' => $status === 'notified' ? null : $data['comment'],
                'closed_at' => $status === 'notified' ? null : now(), 'closed_by_id' => $status === 'notified' ? null : $request->user()->id]);
            $this->event($request, $ic, $data['decision'] === 'return' ? 'returned' : $status, $data['comment']);
            $this->notifyFlow($request, $ic, $this->recipients($tenant, $ic), $data['decision'] === 'return' ? 'returned' : $status);
        });

        return back()->with('success', $data['decision'] === 'return' ? 'IC devolvida à construtora.' : 'Verificação da IC registrada.');
    }

    public function photo(Request $request, Tenant $tenant, InstrucaoCampo $ic, InstrucaoCampoPhoto $photo)
    {
        $this->authorizeIc($request, $tenant, $ic, Permissions::VIEW);
        abort_unless((int) $photo->tenant_id === (int) $tenant->id && (int) $photo->instrucao_campo_id === (int) $ic->id, 404);
        abort_unless(Storage::disk('local')->exists($photo->path), 404);

        return response()->file(Storage::disk('local')->path($photo->path), ['Content-Type' => $photo->mime_type, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function destroy(Request $request, Tenant $tenant, InstrucaoCampo $ic)
    {
        $this->authorizeIc($request, $tenant, $ic, Permissions::DELETE);
        DB::transaction(function () use ($ic, $request) {
            $ic = InstrucaoCampo::whereKey($ic->id)->lockForUpdate()->firstOrFail();
            abort_unless($ic->status === 'draft', 409, 'Somente ICs em rascunho podem ser excluídas.');
            $this->event($request, $ic, 'deleted');
            $ic->delete();
        });

        return to_route('tenant.qualidade.ic.index', $tenant)->with('success', 'Rascunho excluído.');
    }
}
