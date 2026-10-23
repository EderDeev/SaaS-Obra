<?php

namespace App\Http\Controllers\Tenant\Qualidade;

use App\Http\Controllers\Controller;
use App\Models\InstrucaoCampo;
use App\Models\InstrucaoCampoAttachment;
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
    private const RELATIONS = ['contract:id,code,name', 'obra', 'disciplina', 'contratante', 'contratada', 'creator:id,name', 'closedBy:id,name', 'photos', 'attachments', 'events.user:id,name'];

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
                    $q->whereRaw('LOWER(assunto) LIKE ?', [$like])->orWhereRaw('LOWER(instrucao) LIKE ?', [$like])
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
        $obras = $tenant->obras()->withTrashed()->whereIn('contract_id', $contracts->pluck('id'))->orderBy('nome')->get(['id', 'contract_id', 'codigo', 'nome']);
        $disciplinas = $tenant->disciplinas()->withTrashed()->whereIn('contract_id', $contracts->pluck('id'))->orderBy('nome')->get(['id', 'contract_id', 'nome']);
        $filters = $request->validate([
            'contract_id' => ['nullable', Rule::in($contracts->pluck('id')->all())],
            'obra_id' => ['nullable', Rule::in($obras->pluck('id')->all())],
            'disciplina_id' => ['nullable', Rule::in($disciplinas->pluck('id')->all())],
            'gravidade' => ['nullable', Rule::in(['Leve', 'Média', 'Grave', 'Gravíssima'])],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('date_from') ? ['after_or_equal:date_from'] : [])],
        ], ['date_to.after_or_equal' => 'A data final deve ser igual ou posterior à data inicial.'],
            ['date_from' => 'data inicial', 'date_to' => 'data final']);
        foreach (['obra_id' => $obras, 'disciplina_id' => $disciplinas] as $field => $options) {
            if (! empty($filters['contract_id']) && ! empty($filters[$field]) && (int) $options->firstWhere('id', $filters[$field])->contract_id !== (int) $filters['contract_id']) {
                throw ValidationException::withMessages([$field => 'Selecione uma opção do contrato informado.']);
            }
        }
        $query = $tenant->instrucoesCampo()->whereIn('contract_id', $contracts->pluck('id'))
            ->when($filters['contract_id'] ?? null, fn ($q, $id) => $q->where('contract_id', $id))
            ->when($filters['obra_id'] ?? null, fn ($q, $id) => $q->where('obra_id', $id))
            ->when($filters['disciplina_id'] ?? null, fn ($q, $id) => $q->where('disciplina_id', $id))
            ->when($filters['gravidade'] ?? null, fn ($q, $value) => $q->where('gravidade', $value))
            ->when($filters['date_from'] ?? null, fn ($q, $date) => $q->whereDate('opened_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($q, $date) => $q->whereDate('opened_at', '<=', $date));
        $counts = (clone $query)->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');
        $resolutionCounts = (clone $query)->where('status', 'closed')->selectRaw('resolution_result, COUNT(*) AS total')->groupBy('resolution_result')->pluck('total', 'resolution_result');
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
        return Inertia::render('Tenant/Qualidade/InstrucaoCampo/Dashboard', [
            'tenant' => $tenant, 'contracts' => $contracts, 'obras' => $obras, 'disciplinas' => $disciplinas, 'filters' => $filters,
            'counts' => ['all' => (int) $counts->sum(), ...$counts->all(),
                'resolved_yes' => (int) ($resolutionCounts['yes'] ?? 0), 'resolved_partial' => (int) ($resolutionCounts['partial'] ?? 0), 'resolved_no' => (int) ($resolutionCounts['no'] ?? 0)],
            'statuses' => InstrucaoCampo::STATUSES, 'months' => $months, 'byContract' => $byContract,
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
            'assunto' => ['required', 'string', 'max:255'],
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

    private function photos(Request $request, Tenant $tenant, InstrucaoCampo $ic, ?int $eventId = null, array &$storedPaths = []): void
    {
        $positions = $request->input('photo_positions', []);
        if ($positions && count($positions) !== count($request->file('photos', []))) {
            throw ValidationException::withMessages(['photos' => 'Informe uma posição para cada nova foto.']);
        }
        $position = (int) $ic->photos()->max('position');
        foreach ($request->file('photos', []) as $i => $file) {
            $storedPaths[] = $path = $file->store("tenants/{$tenant->id}/ic/{$ic->id}", 'local');
            $ic->photos()->create(['tenant_id' => $tenant->id, 'event_id' => $eventId,
                'path' => $path,
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
            'permissions' => $this->permissions($request, $tenant, $ic), 'statuses' => InstrucaoCampo::STATUSES, 'resolutionOptions' => InstrucaoCampo::RESOLUTION_RESULTS]);
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
                    throw ValidationException::withMessages(['responsaveis' => 'Cadastre Supervisão, construtora e cliente com acesso ao contrato antes de comunicar a IC.']);
                }
            }
            $ic->update(['status' => 'notified', 'notified_at' => now()]);
            $this->event($request, $ic, 'notified');
            $this->notifyFlow($request, $ic, $recipients, 'notified');
        });

        return back()->with('success', 'IC comunicada aos envolvidos.');
    }

    public function finalize(Request $request, Tenant $tenant, InstrucaoCampo $ic)
    {
        $this->authorizeIc($request, $tenant, $ic, Permissions::REVIEW);
        $data = $request->validate(['comment' => ['nullable', 'string', 'max:10000'],
            'from_modal' => ['sometimes', 'boolean'],
            'resolution_result' => ['required', 'string', Rule::in(array_keys(InstrucaoCampo::RESOLUTION_RESULTS))], ...$this->photoRules(),
            'attachments' => ['nullable', 'array', 'max:12'],
            'attachments.*' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf,doc,docx,xls,xlsx,ppt,pptx,odt,ods,csv,txt,zip', 'max:10240'],
        ], [], ['resolution_result' => 'resposta sobre a resolução do problema', 'comment' => 'descrição das evidências', 'attachments' => 'evidências', 'attachments.*' => 'anexo']);
        $files = array_merge($request->file('photos', []), $request->file('attachments', []));
        if (count($files) < 1) {
            throw ValidationException::withMessages(['attachments' => 'Anexe pelo menos uma evidência para finalizar a IC.']);
        }
        if (count($files) > 12 || array_sum(array_map(fn ($file) => $file->getSize(), $files)) > 50 * 1024 * 1024) {
            throw ValidationException::withMessages(['attachments' => 'Envie até 12 arquivos, somando no máximo 50 MB.']);
        }
        $storedPaths = [];
        try {
            DB::transaction(function () use ($request, $tenant, $ic, $data, &$storedPaths) {
                $ic = InstrucaoCampo::whereKey($ic->id)->lockForUpdate()->firstOrFail();
                if ($ic->status !== 'notified' && ($data['from_modal'] ?? false)) {
                    throw ValidationException::withMessages(['ic' => 'Esta IC não está mais aguardando evidências. Feche o registro e atualize a página.']);
                }
                abort_unless($ic->status === 'notified', 409, 'A IC não está aguardando evidências.');
                $event = $this->event($request, $ic, 'finalized', $data['comment'] ?? null);
                $this->photos($request, $tenant, $ic, $event->id, $storedPaths);
                foreach ($request->file('attachments', []) as $file) {
                    $storedPaths[] = $path = $file->store("tenants/{$tenant->id}/ic/{$ic->id}/evidencias", 'local');
                    $ic->attachments()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'path' => $path,
                        'original_name' => mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', $file->getClientOriginalName()), 0, 255),
                        'mime_type' => $file->getMimeType(), 'size_bytes' => $file->getSize()]);
                }
                $ic->update(['status' => 'closed', 'resolution_result' => $data['resolution_result'],
                    'closed_at' => now(), 'closed_by_id' => $request->user()->id, 'closing_comment' => $data['comment'] ?? null]);
                $this->notifyFlow($request, $ic, $this->recipients($tenant, $ic), 'finalized');
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($storedPaths);
            throw $exception;
        }

        return ($data['from_modal'] ?? false)
            ? back()->with('success', 'IC finalizada com as evidências e o resultado informado.')
            : to_route('tenant.qualidade.ic.show', [$tenant, $ic])->with('success', 'IC finalizada com as evidências e o resultado informado.');
    }

    public function evidence(Request $request, Tenant $tenant, InstrucaoCampo $ic)
    {
        $this->authorizeIc($request, $tenant, $ic, Permissions::REVIEW);
        abort_unless($ic->status === 'notified', 409, 'A IC não está aguardando evidências.');

        $data = ['tenant' => $tenant, 'ic' => $ic->load(self::RELATIONS), 'resolutionOptions' => InstrucaoCampo::RESOLUTION_RESULTS];
        if ($request->expectsJson()) {
            return response()->json($data)->header('Cache-Control', 'private, no-store');
        }

        return Inertia::render('Tenant/Qualidade/InstrucaoCampo/Evidence', $data);
    }

    public function attachment(Request $request, Tenant $tenant, InstrucaoCampo $ic, InstrucaoCampoAttachment $attachment)
    {
        $this->authorizeIc($request, $tenant, $ic, Permissions::VIEW);
        abort_unless((int) $attachment->tenant_id === (int) $tenant->id && (int) $attachment->instrucao_campo_id === (int) $ic->id, 404);
        abort_unless(Storage::disk('local')->exists($attachment->path), 404);
        $headers = ['Content-Type' => $attachment->mime_type, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'];
        if ($request->boolean('preview') && in_array($attachment->mime_type, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return response()->file(Storage::disk('local')->path($attachment->path), $headers);
        }

        return response()->download(Storage::disk('local')->path($attachment->path), $attachment->original_name, $headers);
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
