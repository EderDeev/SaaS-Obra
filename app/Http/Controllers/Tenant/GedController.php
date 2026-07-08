<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessGedDocumentOcrJob;
use App\Models\Contract;
use App\Models\Empresa;
use App\Models\GedCorrespondent;
use App\Models\GedDocument;
use App\Models\GedDocumentEvent;
use App\Models\GedDocumentNote;
use App\Models\GedDocumentType;
use App\Models\GedDocumentVersion;
use App\Models\GedTag;
use App\Models\Tenant;
use App\Models\TenantUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use setasign\Fpdi\Fpdi;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

class GedController extends Controller
{
    private const SECTIONS = [
        'details' => 'Detalhes',
        'content' => 'Conteúdo',
        'metadata' => 'Metadados',
        'notes' => 'Notas',
        'history' => 'Histórico',
        'permissions' => 'Permissões',
    ];

    public function index(Request $request, Tenant $tenant): Response
    {
        $user = $request->user();
        $tenantRole = $user?->tenantRole($tenant);
        $canSeeAllContracts = $user?->is_platform_admin || in_array($tenantRole, ['tenant_owner', 'tenant_admin'], true);

        $accessibleContracts = Contract::query()
            ->where('tenant_id', $tenant->id)
            ->when(! $canSeeAllContracts, function ($query) use ($user): void {
                $query->whereHas('participants', function ($query) use ($user): void {
                    $query->where('user_id', $user->id)->where('status', 'active');
                });
            })
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        $accessibleContractIds = $accessibleContracts->pluck('id');

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', 'max:40'],
            'type_id' => ['nullable', 'integer'],
            'tag_id' => ['nullable', 'integer'],
            'correspondent_id' => ['nullable', 'integer'],
            'contract_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'sort' => ['nullable', 'string', 'max:40'],
            'direction' => ['nullable', 'in:asc,desc'],
        ]);

        $sort = $filters['sort'] ?? 'added';
        $direction = $filters['direction'] ?? 'desc';
        $allowedSorts = ['nsa', 'correspondent', 'title', 'type', 'created', 'added', 'modified', 'notes', 'owner', 'pages'];
        $sort = in_array($sort, $allowedSorts, true) ? $sort : 'added';

        $documents = GedDocument::query()
            ->select('ged_documents.*')
            ->where('ged_documents.tenant_id', $tenant->id)
            ->whereIn('ged_documents.contract_id', $accessibleContractIds)
            ->with(['contract:id,code,name', 'type:id,name', 'correspondent:id,name', 'tags:id,name,color', 'uploader:id,name'])
            ->withCount('notes')
            ->when($filters['q'] ?? null, function ($query, string $term) {
                $query->where(function ($inner) use ($term) {
                    $inner
                        ->where('ged_documents.title', 'ilike', "%{$term}%")
                        ->orWhere('ged_documents.document_number', 'ilike', "%{$term}%")
                        ->orWhere('ged_documents.original_filename', 'ilike', "%{$term}%")
                        ->orWhere('ged_documents.extracted_text', 'ilike', "%{$term}%");
                });
            })
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('ged_documents.status', $status))
            ->when($filters['type_id'] ?? null, fn ($query, int $typeId) => $query->where('ged_documents.document_type_id', $typeId))
            ->when($filters['correspondent_id'] ?? null, fn ($query, int $correspondentId) => $query->where('ged_documents.correspondent_id', $correspondentId))
            ->when($filters['contract_id'] ?? null, fn ($query, int $contractId) => $query->where('ged_documents.contract_id', $contractId))
            ->when($filters['date_from'] ?? null, fn ($query, string $date) => $query->whereDate('ged_documents.created_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($query, string $date) => $query->whereDate('ged_documents.created_at', '<=', $date))
            ->when($filters['tag_id'] ?? null, fn ($query, int $tagId) => $query->whereHas('tags', fn ($tagQuery) => $tagQuery->where('ged_tags.id', $tagId)))
            ->when($sort === 'correspondent', fn ($query) => $query->leftJoin('ged_correspondents as sort_correspondents', 'sort_correspondents.id', '=', 'ged_documents.correspondent_id'))
            ->when($sort === 'type', fn ($query) => $query->leftJoin('ged_document_types as sort_types', 'sort_types.id', '=', 'ged_documents.document_type_id'))
            ->when($sort === 'owner', fn ($query) => $query->leftJoin('users as sort_users', 'sort_users.id', '=', 'ged_documents.uploaded_by_id'))
            ->orderBy(match ($sort) {
                'nsa' => 'ged_documents.document_number',
                'correspondent' => 'sort_correspondents.name',
                'title' => 'ged_documents.title',
                'type' => 'sort_types.name',
                'created' => 'ged_documents.document_date',
                'modified' => 'ged_documents.updated_at',
                'notes' => 'notes_count',
                'owner' => 'sort_users.name',
                'pages' => 'ged_documents.page_count',
                default => 'ged_documents.created_at',
            }, $direction)
            ->orderByDesc('ged_documents.id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (GedDocument $document) => [
                'id' => $document->id,
                'title' => $document->title,
                'document_number' => $document->document_number,
                'document_date' => $document->document_date?->format('Y-m-d'),
                'status' => $document->status,
                'description' => $document->description,
                'original_filename' => $document->original_filename,
                'mime_type' => $document->mime_type,
                'extension' => $document->extension,
                'size_bytes' => $document->size_bytes,
                'page_count' => $document->page_count,
                'notes_count' => $document->notes_count ?? 0,
                'checksum' => $document->checksum,
                'created_at' => $document->created_at?->format('Y-m-d H:i:s'),
                'updated_at' => $document->updated_at?->format('Y-m-d H:i:s'),
                'details_url' => route('tenant.ged.details', [$tenant->slug, $document]),
                'download_url' => route('tenant.ged.download', [$tenant->slug, $document]),
                'preview_url' => route('tenant.ged.preview', [$tenant->slug, $document]),
                'contract' => $document->contract ? [
                    'id' => $document->contract->id,
                    'code' => $document->contract->code,
                    'name' => $document->contract->name,
                ] : null,
                'type' => $document->type ? [
                    'id' => $document->type->id,
                    'name' => $document->type->name,
                ] : null,
                'correspondent' => $document->correspondent ? [
                    'id' => $document->correspondent->id,
                    'name' => $document->correspondent->name,
                ] : null,
                'tags' => $document->tags->map(fn (GedTag $tag) => [
                    'id' => $tag->id,
                    'name' => $tag->name,
                    'color' => $tag->color,
                ])->values(),
                'uploader' => $document->uploader?->name,
            ]);

        return Inertia::render('Tenant/Ged/Index', [
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
            ],
            'documents' => $documents,
            'filters' => $filters,
            'contracts' => $accessibleContracts,
            'types' => GedDocumentType::query()
                ->where('tenant_id', $tenant->id)
                ->whereIn('contract_id', $accessibleContractIds)
                ->orderBy('name')
                ->get(['id', 'contract_id', 'name']),
            'tags' => GedTag::query()
                ->where('tenant_id', $tenant->id)
                ->whereIn('contract_id', $accessibleContractIds)
                ->orderBy('name')
                ->get(['id', 'contract_id', 'name', 'color']),
            'correspondents' => Empresa::query()
                ->where('tenant_id', $tenant->id)
                ->whereIn('contract_id', $accessibleContractIds)
                ->orderBy('nome')
                ->get(['id', 'contract_id', 'nome', 'cnpj', 'sigla']),
            'filterCorrespondents' => GedCorrespondent::query()
                ->where('tenant_id', $tenant->id)
                ->whereIn('contract_id', $accessibleContractIds)
                ->orderBy('name')
                ->get(['id', 'contract_id', 'name']),
            'stats' => [
                'total' => GedDocument::where('tenant_id', $tenant->id)->whereIn('contract_id', $accessibleContractIds)->count(),
                'uploaded' => GedDocument::where('tenant_id', $tenant->id)->whereIn('contract_id', $accessibleContractIds)->where('status', 'uploaded')->count(),
                'indexed' => GedDocument::where('tenant_id', $tenant->id)->whereIn('contract_id', $accessibleContractIds)->where('status', 'indexed')->count(),
                'processing' => GedDocument::where('tenant_id', $tenant->id)->whereIn('contract_id', $accessibleContractIds)->where('status', 'processing')->count(),
            ],
        ]);
    }

    public function settings(Tenant $tenant): Response
    {
        return Inertia::render('Tenant/Ged/Settings', [
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
            ],
            'contracts' => Contract::query()
                ->where('tenant_id', $tenant->id)
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
            'types' => GedDocumentType::query()
                ->where('tenant_id', $tenant->id)
                ->with('contract:id,code,name')
                ->withCount('documents')
                ->orderBy('name')
                ->get(['id', 'contract_id', 'name', 'description', 'created_at']),
            'tags' => GedTag::query()
                ->where('tenant_id', $tenant->id)
                ->with('contract:id,code,name')
                ->withCount('documents')
                ->orderBy('name')
                ->get(['id', 'contract_id', 'name', 'color', 'is_inbox', 'created_at']),
        ]);
    }

    public function details(Tenant $tenant, GedDocument $document): Response
    {
        return $this->renderDocumentWorkspace($tenant, $document, 'details');
    }

    public function content(Tenant $tenant, GedDocument $document): Response
    {
        return $this->renderDocumentWorkspace($tenant, $document, 'content');
    }

    public function metadata(Tenant $tenant, GedDocument $document): Response
    {
        return $this->renderDocumentWorkspace($tenant, $document, 'metadata');
    }

    public function notes(Tenant $tenant, GedDocument $document): Response
    {
        return $this->renderDocumentWorkspace($tenant, $document, 'notes');
    }

    public function storeNote(Request $request, Tenant $tenant, GedDocument $document): RedirectResponse
    {
        abort_unless((int) $document->tenant_id === (int) $tenant->id, 404);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $note = GedDocumentNote::create([
            'tenant_id' => $tenant->id,
            'document_id' => $document->id,
            'user_id' => auth()->id(),
            'body' => $data['body'],
        ]);

        $this->logGedEvent(
            $document,
            'note.created',
            'Nota adicionada',
            'Uma nota foi adicionada ao documento.',
            ['note_id' => $note->id],
        );

        return back()->with('success', 'Nota adicionada.');
    }

    public function history(Tenant $tenant, GedDocument $document): Response
    {
        return $this->renderDocumentWorkspace($tenant, $document, 'history');
    }

    public function permissions(Tenant $tenant, GedDocument $document): Response
    {
        return $this->renderDocumentWorkspace($tenant, $document, 'permissions');
    }

    public function updatePermissions(Request $request, Tenant $tenant, GedDocument $document): RedirectResponse
    {
        abort_unless((int) $document->tenant_id === (int) $tenant->id, 404);

        $data = $request->validate([
            'owner_user_id' => ['nullable', 'integer'],
            'view_user_ids' => ['nullable', 'array'],
            'view_user_ids.*' => ['integer'],
            'view_empresa_ids' => ['nullable', 'array'],
            'view_empresa_ids.*' => ['integer'],
            'edit_user_ids' => ['nullable', 'array'],
            'edit_user_ids.*' => ['integer'],
            'edit_empresa_ids' => ['nullable', 'array'],
            'edit_empresa_ids.*' => ['integer'],
        ]);

        $availableUserIds = $tenant->users()
            ->wherePivot('status', 'active')
            ->pluck('users.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $availableEmpresaIds = $this->gedPermissionCompanies($tenant, $document)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $normalizeIds = fn (array $ids, array $allowed) => collect($ids)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->filter(fn (int $id) => in_array($id, $allowed, true))
            ->values()
            ->all();

        $ownerUserId = isset($data['owner_user_id']) ? (int) $data['owner_user_id'] : null;
        $ownerUserId = $ownerUserId && in_array($ownerUserId, $availableUserIds, true) ? $ownerUserId : null;

        $permissions = [
            'owner_user_id' => $ownerUserId,
            'view' => [
                'user_ids' => $normalizeIds($data['view_user_ids'] ?? [], $availableUserIds),
                'empresa_ids' => $normalizeIds($data['view_empresa_ids'] ?? [], $availableEmpresaIds),
            ],
            'edit' => [
                'user_ids' => $normalizeIds($data['edit_user_ids'] ?? [], $availableUserIds),
                'empresa_ids' => $normalizeIds($data['edit_empresa_ids'] ?? [], $availableEmpresaIds),
            ],
        ];

        $metadata = $document->metadata ?: [];
        $before = $metadata['permissions'] ?? null;
        $metadata['permissions'] = $permissions;

        $document->forceFill([
            'metadata' => $metadata,
        ])->save();

        $this->logGedEvent(
            $document,
            'permissions.updated',
            'Permissões atualizadas',
            'As permissões do documento foram alteradas.',
            [
                'before' => $before,
                'after' => $permissions,
            ],
        );

        return back()->with('success', 'Permissões atualizadas.');
    }

    public function queueOcr(Tenant $tenant, GedDocument $document): RedirectResponse
    {
        abort_unless((int) $document->tenant_id === (int) $tenant->id, 404);

        $metadata = $document->metadata ?: [];
        $metadata['ocr'] = array_merge($metadata['ocr'] ?? [], [
            'status' => 'queued',
            'queued_at' => now()->toDateTimeString(),
            'started_at' => null,
            'finished_at' => null,
            'engine' => 'ocrmypdf',
            'message' => 'Documento reenviado para fila de OCR.',
        ]);

        $document->forceFill([
            'status' => 'processing',
            'metadata' => $metadata,
        ])->save();

        $this->logGedEvent(
            $document,
            'ocr.queued',
            'OCR reenviado para processamento',
            'Documento reenviado manualmente para a fila de OCR.',
            ['engine' => 'ocrmypdf'],
        );

        ProcessGedDocumentOcrJob::dispatch($document->id)->afterResponse();

        return back()->with('success', 'Documento reenviado para a fila de OCR.');
    }

    public function update(Request $request, Tenant $tenant, GedDocument $document): RedirectResponse
    {
        abort_unless((int) $document->tenant_id === (int) $tenant->id, 404);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'document_date' => ['nullable', 'date'],
            'description' => ['nullable', 'string', 'max:2000'],
            'contract_id' => ['required', 'integer'],
            'document_type_id' => ['nullable', 'integer'],
            'correspondent_id' => ['nullable', 'integer'],
            'tag_ids' => ['nullable', 'array'],
            'tag_ids.*' => ['integer'],
        ]);

        $contractId = $data['contract_id'] ?? null;
        $typeId = $data['document_type_id'] ?? null;
        $correspondentId = $data['correspondent_id'] ?? null;
        $tagIds = collect($data['tag_ids'] ?? [])->filter()->map(fn ($id) => (int) $id)->unique()->values();
        $before = [
            'title' => $document->title,
            'document_date' => $document->document_date?->format('Y-m-d'),
            'description' => $document->description,
            'contract_id' => $document->contract_id,
            'document_type_id' => $document->document_type_id,
            'correspondent_id' => $document->correspondent_id,
            'tag_ids' => $document->tags()->pluck('ged_tags.id')->map(fn ($id) => (int) $id)->sort()->values()->all(),
        ];

        abort_unless(Contract::where('tenant_id', $tenant->id)->whereKey($contractId)->exists(), 422);

        if ($typeId) {
            abort_unless(GedDocumentType::where('tenant_id', $tenant->id)->where('contract_id', $contractId)->whereKey($typeId)->exists(), 422);
        }

        if ($correspondentId) {
            abort_unless(GedCorrespondent::where('tenant_id', $tenant->id)->where('contract_id', $contractId)->whereKey($correspondentId)->exists(), 422);
        }

        if ($tagIds->isNotEmpty()) {
            $validTagIds = GedTag::where('tenant_id', $tenant->id)->where('contract_id', $contractId)->whereIn('id', $tagIds)->pluck('id')->map(fn ($id) => (int) $id);
            abort_unless($validTagIds->count() === $tagIds->count(), 422);
        }

        DB::transaction(function () use ($document, $data, $contractId, $typeId, $correspondentId, $tagIds, $before) {
            $document->update([
                'title' => $data['title'],
                'document_date' => $data['document_date'] ?? null,
                'description' => $data['description'] ?? null,
                'contract_id' => $contractId,
                'obra_id' => null,
                'document_type_id' => $typeId,
                'correspondent_id' => $correspondentId,
            ]);

            $document->tags()->sync($tagIds);

            $after = [
                'title' => $data['title'],
                'document_date' => $data['document_date'] ?? null,
                'description' => $data['description'] ?? null,
                'contract_id' => $contractId,
                'document_type_id' => $typeId,
                'correspondent_id' => $correspondentId,
                'tag_ids' => $tagIds->sort()->values()->all(),
            ];

            $changes = collect($after)
                ->filter(fn ($value, string $key) => json_encode($before[$key] ?? null) !== json_encode($value))
                ->map(fn ($value, string $key) => [
                    'field' => $key,
                    'old' => $before[$key] ?? null,
                    'new' => $value,
                ])
                ->values()
                ->all();

            if ($changes !== []) {
                $this->logGedEvent(
                    $document,
                    'document.updated',
                    'Metadados atualizados',
                    'Os dados do documento foram alterados.',
                    ['changes' => $changes],
                );
            }
        });

        return back()->with('success', 'Dados do documento atualizados.');
    }

    public function store(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:51200'],
            'title' => ['nullable', 'string', 'max:180'],
            'document_date' => ['nullable', 'date'],
            'description' => ['nullable', 'string', 'max:2000'],
            'contract_id' => ['required', 'exists:contracts,id'],
            'document_type_id' => ['nullable', 'exists:ged_document_types,id'],
            'correspondent_empresa_id' => ['nullable', 'exists:empresas,id'],
            'tag_ids' => ['nullable', 'array'],
            'tag_ids.*' => ['integer', 'exists:ged_tags,id'],
        ]);

        $file = $request->file('file');
        $checksum = hash_file('sha256', $file->getRealPath());
        $originalMd5 = hash_file('md5', $file->getRealPath());
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $directory = 'ged/'.$tenant->id.'/'.now()->format('Y/m');
        $filename = Str::uuid().($extension ? ".{$extension}" : '');
        $storageDisk = (string) config('ged.document_disk', 'public');
        $path = $file->storeAs($directory, $filename, $storageDisk);

        $document = DB::transaction(function () use ($tenant, $data, $file, $path, $checksum, $originalMd5, $extension, $storageDisk) {
            $duplicate = GedDocument::query()
                ->where('tenant_id', $tenant->id)
                ->where('checksum', $checksum)
                ->first();

            $empresa = isset($data['correspondent_empresa_id'])
                ? Empresa::where('tenant_id', $tenant->id)->find($data['correspondent_empresa_id'])
                : null;

            abort_unless(Contract::where('tenant_id', $tenant->id)->whereKey($data['contract_id'])->exists(), 422);

            if (! empty($data['document_type_id'])) {
                abort_unless(GedDocumentType::where('tenant_id', $tenant->id)->where('contract_id', $data['contract_id'])->whereKey($data['document_type_id'])->exists(), 422);
            }

            if ($empresa) {
                abort_unless((int) $empresa->contract_id === (int) $data['contract_id'], 422);
            }

            $tagIds = collect($data['tag_ids'] ?? [])
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values();

            if ($tagIds->isNotEmpty()) {
                $validTagIds = GedTag::where('tenant_id', $tenant->id)
                    ->where('contract_id', $data['contract_id'])
                    ->whereIn('id', $tagIds)
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id);

                abort_unless($validTagIds->count() === $tagIds->count(), 422);
            }

            $correspondent = $empresa
                ? \App\Models\GedCorrespondent::firstOrCreate(
                    [
                        'tenant_id' => $tenant->id,
                        'empresa_id' => $empresa->id,
                    ],
                    [
                        'contract_id' => $empresa->contract_id,
                        'name' => $empresa->nome,
                        'document' => $empresa->cnpj,
                    ],
                )
                : null;

            $sequence = $this->nextGedDocumentSequence($tenant, $data['document_date'] ?? null);

            $document = GedDocument::create([
                'tenant_id' => $tenant->id,
                'contract_id' => $data['contract_id'] ?? null,
                'obra_id' => null,
                'document_type_id' => $data['document_type_id'] ?? null,
                'correspondent_id' => $correspondent?->id,
                'uploaded_by_id' => auth()->id(),
                'title' => $data['title'] ?: pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
                'document_number' => $sequence['document_number'],
                'sequence_year' => $sequence['sequence_year'],
                'sequence_number' => $sequence['sequence_number'],
                'document_date' => $data['document_date'] ?? null,
                'status' => config('ged.ocr.enabled', true) ? 'processing' : 'uploaded',
                'description' => $data['description'] ?? null,
                'original_filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'extension' => $extension,
                'size_bytes' => $file->getSize() ?: 0,
                'checksum' => $checksum,
                'storage_disk' => $storageDisk,
                'original_path' => $path,
                'metadata' => [
                    'source' => 'web_upload',
                    'original_md5' => $originalMd5,
                    'original_file_metadata' => $this->extractOriginalDocumentMetadata($file->getRealPath(), $file->getMimeType(), $extension),
                    'duplicate_of_id' => $duplicate?->id,
                    'ocr' => [
                        'status' => config('ged.ocr.enabled', true) ? 'queued' : 'disabled',
                        'queued_at' => config('ged.ocr.enabled', true) ? now()->toDateTimeString() : null,
                        'engine' => 'ocrmypdf',
                        'message' => config('ged.ocr.enabled', true)
                            ? 'Documento enviado para fila de OCR.'
                            : 'OCR automÃ¡tico desativado.',
                    ],
                    'paperless_reference' => [
                        'concepts' => ['document', 'checksum', 'tags', 'correspondent', 'document_type'],
                    ],
                ],
            ]);

            GedDocumentVersion::create([
                'document_id' => $document->id,
                'uploaded_by_id' => auth()->id(),
                'version_number' => 1,
                'original_filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size_bytes' => $file->getSize() ?: 0,
                'checksum' => $checksum,
                'storage_disk' => $storageDisk,
                'path' => $path,
                'notes' => 'VersÃ£o inicial enviada pelo GED.',
            ]);

            if ($tagIds->isNotEmpty()) {
                $document->tags()->sync($tagIds);
            }

            $this->logGedEvent(
                $document,
                'document.created',
                'Documento enviado',
                'Arquivo enviado para o GED.',
                [
                    'document_number' => $document->document_number,
                    'original_filename' => $file->getClientOriginalName(),
                    'size_bytes' => $file->getSize() ?: 0,
                    'checksum' => $checksum,
                    'md5' => $originalMd5,
                    'duplicate_of_id' => $duplicate?->id,
                    'tag_ids' => $tagIds->all(),
                ],
            );

            if (config('ged.ocr.enabled', true)) {
                $this->logGedEvent(
                    $document,
                    'ocr.queued',
                    'OCR colocado na fila',
                    'Documento enviado automaticamente para processamento OCR.',
                    ['engine' => 'ocrmypdf'],
                );
            }

            return $document;
        });

        if (config('ged.ocr.enabled', true)) {
            ProcessGedDocumentOcrJob::dispatch($document->id)->afterResponse();
        }

        return redirect()
            ->route('tenant.ged.index', $tenant->slug)
            ->with('success', "Documento {$document->title} enviado para o GED e colocado na fila de OCR.");
    }

    public function storeType(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'contract_id' => ['required', 'integer'],
        ]);

        $contractId = $data['contract_id'] ?? null;

        abort_unless(Contract::where('tenant_id', $tenant->id)->whereKey($contractId)->exists(), 422);

        GedDocumentType::firstOrCreate(
            ['tenant_id' => $tenant->id, 'contract_id' => $contractId, 'name' => $data['name']],
            ['description' => null],
        );

        return back()->with('success', 'Tipo documental cadastrado.');
    }

    public function storeCorrespondent(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'contract_id' => ['required', 'integer'],
        ]);

        $contractId = $data['contract_id'] ?? null;

        abort_unless(Contract::where('tenant_id', $tenant->id)->whereKey($contractId)->exists(), 422);

        GedCorrespondent::firstOrCreate(
            ['tenant_id' => $tenant->id, 'contract_id' => $contractId, 'name' => $data['name']],
            [
                'email' => null,
                'document' => null,
            ],
        );

        return back()->with('success', 'Correspondente cadastrado.');
    }

    public function storeTag(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'color' => ['nullable', 'string', 'max:7'],
            'contract_id' => ['required', 'integer'],
        ]);

        abort_unless(Contract::where('tenant_id', $tenant->id)->whereKey($data['contract_id'])->exists(), 422);

        GedTag::firstOrCreate(
            ['tenant_id' => $tenant->id, 'contract_id' => $data['contract_id'], 'name' => $data['name']],
            ['color' => $data['color'] ?: '#2563eb'],
        );

        return back()->with('success', 'Etiqueta cadastrada.');
    }

    private function nextGedDocumentSequence(Tenant $tenant, ?string $documentDate = null): array
    {
        Tenant::query()
            ->whereKey($tenant->id)
            ->lockForUpdate()
            ->first();

        $year = CarbonImmutable::parse($documentDate ?: now())->year;

        $lastDocument = GedDocument::query()
            ->where('tenant_id', $tenant->id)
            ->where('sequence_year', $year)
            ->orderByDesc('sequence_number')
            ->lockForUpdate()
            ->first(['id', 'sequence_number']);

        $sequenceNumber = ((int) ($lastDocument?->sequence_number ?? 0)) + 1;

        return [
            'sequence_year' => $year,
            'sequence_number' => $sequenceNumber,
            'document_number' => str_pad((string) $sequenceNumber, 3, '0', STR_PAD_LEFT).'/'.$year,
        ];
    }

    private function extractOriginalDocumentMetadata(string $path, ?string $mimeType, ?string $extension): array
    {
        $extension = strtolower((string) $extension);
        $mimeType = strtolower((string) $mimeType);

        if ($extension === 'pdf' || $mimeType === 'application/pdf') {
            return $this->extractPdfMetadata($path);
        }

        if (str_starts_with($mimeType, 'image/') && function_exists('exif_read_data')) {
            $exif = @exif_read_data($path, null, true);

            if (is_array($exif)) {
                return collect($exif)
                    ->flatMap(fn ($values, string $section) => collect((array) $values)
                        ->filter(fn ($value) => is_scalar($value))
                        ->mapWithKeys(fn ($value, string $key) => ["{$section}:{$key}" => (string) $value]))
                    ->take(30)
                    ->all();
            }
        }

        return [];
    }

    private function extractPdfMetadata(string $path): array
    {
        if (! File::exists($path)) {
            return [];
        }

        $content = (string) File::get($path);
        $metadata = [];

        if (preg_match('/%PDF-([0-9.]+)/', substr($content, 0, 32), $matches)) {
            $metadata['pdf:PDFVersion'] = $matches[1];
        }

        $map = [
            'Producer' => 'pdf:Producer',
            'Creator' => 'xmp:CreatorTool',
            'Author' => 'dc:creator',
            'Title' => 'dc:title',
            'Subject' => 'dc:subject',
            'CreationDate' => 'xmp:CreateDate',
            'ModDate' => 'xmp:ModifyDate',
        ];

        foreach ($map as $pdfKey => $metadataKey) {
            if (preg_match('/\/'.$pdfKey.'\s*\((.*?)\)/s', $content, $matches)) {
                $metadata[$metadataKey] = $this->decodePdfString($matches[1]);
            }
        }

        if (($metadata['pdf:PDFVersion'] ?? null) && ! isset($metadata['dc:format'])) {
            $metadata['dc:format'] = 'application/pdf';
        }

        return $metadata;
    }

    private function decodePdfString(string $value): string
    {
        $value = preg_replace('/\\\\([nrtbf()\\\\])/', '$1', $value) ?? $value;
        $value = trim($value);

        return mb_check_encoding($value, 'UTF-8')
            ? $value
            : mb_convert_encoding($value, 'UTF-8', 'UTF-8, Windows-1252, ISO-8859-1');
    }

    private function logGedEvent(GedDocument $document, string $type, string $title, ?string $description = null, array $properties = []): void
    {
        GedDocumentEvent::create([
            'tenant_id' => $document->tenant_id,
            'document_id' => $document->id,
            'actor_id' => auth()->id(),
            'event_type' => $type,
            'title' => $title,
            'description' => $description,
            'properties' => $properties === [] ? null : $properties,
        ]);
    }

    private function renderDocumentWorkspace(Tenant $tenant, GedDocument $document, string $section): Response
    {
        abort_unless((int) $document->tenant_id === (int) $tenant->id, 404);
        abort_unless(array_key_exists($section, self::SECTIONS), 404);

        $document->load([
            'contract:id,code,name,cliente_empresa_id,construtora_empresa_id,fiscalizadora_empresa_id',
            'type:id,name',
            'correspondent:id,name',
            'tags:id,name,color',
            'uploader:id,name,email',
            'versions.uploader:id,name,email',
            'events.actor:id,name,email',
            'notes.user:id,name,email',
        ]);

        $previous = GedDocument::query()
            ->where('tenant_id', $tenant->id)
            ->where('id', '<', $document->id)
            ->orderByDesc('id')
            ->first(['id']);

        $next = GedDocument::query()
            ->where('tenant_id', $tenant->id)
            ->where('id', '>', $document->id)
            ->orderBy('id')
            ->first(['id']);

        $tabs = collect(self::SECTIONS)->map(fn (string $label, string $key) => [
            'key' => $key,
            'label' => $label,
            'url' => route("tenant.ged.{$key}", [$tenant->slug, $document]),
        ])->values();

        $permissionCompanies = $this->gedPermissionCompanies($tenant, $document);
        $permissionCompanyUserCounts = TenantUser::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->whereIn('empresa_id', $permissionCompanies->pluck('id'))
            ->select('empresa_id', DB::raw('count(*) as users_count'))
            ->groupBy('empresa_id')
            ->pluck('users_count', 'empresa_id');

        $activeUsers = $tenant->users()
            ->wherePivot('status', 'active')
            ->orderBy('name')
            ->get(['users.id', 'users.name', 'users.email'])
            ->map(fn ($user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'empresa_id' => $user->pivot?->empresa_id,
                'empresa_name' => $user->pivot?->empresa_id
                    ? optional($permissionCompanies->firstWhere('id', (int) $user->pivot->empresa_id))->nome
                    : null,
            ]);

        $storedPermissions = $document->metadata['permissions'] ?? [];

        return Inertia::render('Tenant/Ged/Show', [
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
            ],
            'activeSection' => $section,
            'tabs' => $tabs,
            'document' => [
                'id' => $document->id,
                'title' => $document->title,
                'document_number' => $document->document_number,
                'document_date' => $document->document_date?->format('Y-m-d'),
                'status' => $document->status,
                'description' => $document->description,
                'extracted_text' => $document->extracted_text,
                'original_filename' => $document->original_filename,
                'mime_type' => $document->mime_type,
                'extension' => $document->extension,
                'size_bytes' => $document->size_bytes,
                'page_count' => $document->page_count,
                'checksum' => $document->checksum,
                'original_path' => $document->original_path,
                'archive_path' => $document->archive_path,
                'created_at' => $document->created_at?->format('Y-m-d H:i:s'),
                'updated_at' => $document->updated_at?->format('Y-m-d H:i:s'),
                'processed_at' => $document->processed_at?->format('Y-m-d H:i:s'),
                'metadata' => $document->metadata ?: [],
                'download_url' => route('tenant.ged.download', [$tenant->slug, $document]),
                'preview_url' => route('tenant.ged.preview', [$tenant->slug, $document]),
                'ocr_url' => route('tenant.ged.ocr', [$tenant->slug, $document]),
                'notes_store_url' => route('tenant.ged.notes.store', [$tenant->slug, $document]),
                'permissions_update_url' => route('tenant.ged.permissions.update', [$tenant->slug, $document]),
                'update_url' => route('tenant.ged.update', [$tenant->slug, $document]),
                'index_url' => route('tenant.ged.index', $tenant->slug),
                'contract' => $document->contract ? [
                    'id' => $document->contract->id,
                    'code' => $document->contract->code,
                    'name' => $document->contract->name,
                ] : null,
                'type' => $document->type ? [
                    'id' => $document->type->id,
                    'name' => $document->type->name,
                ] : null,
                'correspondent' => $document->correspondent ? [
                    'id' => $document->correspondent->id,
                    'name' => $document->correspondent->name,
                ] : null,
                'tags' => $document->tags->map(fn (GedTag $tag) => [
                    'id' => $tag->id,
                    'name' => $tag->name,
                    'color' => $tag->color,
                ])->values(),
                'uploader' => $document->uploader ? [
                    'id' => $document->uploader->id,
                    'name' => $document->uploader->name,
                    'email' => $document->uploader->email,
                ] : null,
                'versions' => $document->versions->map(fn (GedDocumentVersion $version) => [
                    'id' => $version->id,
                    'version_number' => $version->version_number,
                    'original_filename' => $version->original_filename,
                    'mime_type' => $version->mime_type,
                    'size_bytes' => $version->size_bytes,
                    'checksum' => $version->checksum,
                    'notes' => $version->notes,
                    'created_at' => $version->created_at?->format('Y-m-d H:i:s'),
                    'uploader' => $version->uploader ? [
                        'id' => $version->uploader->id,
                        'name' => $version->uploader->name,
                        'email' => $version->uploader->email,
                    ] : null,
                ])->values(),
                'history_events' => $document->events->map(fn (GedDocumentEvent $event) => [
                    'id' => $event->id,
                    'event_type' => $event->event_type,
                    'title' => $event->title,
                    'description' => $event->description,
                    'properties' => $event->properties ?: [],
                    'created_at' => $event->created_at?->format('Y-m-d H:i:s'),
                    'actor' => $event->actor ? [
                        'id' => $event->actor->id,
                        'name' => $event->actor->name,
                        'email' => $event->actor->email,
                    ] : null,
                ])->values(),
                'notes' => $document->notes->map(fn (GedDocumentNote $note) => [
                    'id' => $note->id,
                    'body' => $note->body,
                    'created_at' => $note->created_at?->format('Y-m-d H:i:s'),
                    'user' => $note->user ? [
                        'id' => $note->user->id,
                        'name' => $note->user->name,
                        'email' => $note->user->email,
                    ] : null,
                ])->values(),
                'permissions' => [
                    'owner_user_id' => $storedPermissions['owner_user_id'] ?? $document->uploaded_by_id,
                    'view' => [
                        'user_ids' => $storedPermissions['view']['user_ids'] ?? [],
                        'empresa_ids' => $storedPermissions['view']['empresa_ids'] ?? [],
                    ],
                    'edit' => [
                        'user_ids' => $storedPermissions['edit']['user_ids'] ?? [],
                        'empresa_ids' => $storedPermissions['edit']['empresa_ids'] ?? [],
                    ],
                ],
            ],
            'navigation' => [
                'previous_url' => $previous ? route('tenant.ged.details', [$tenant->slug, $previous]) : null,
                'next_url' => $next ? route('tenant.ged.details', [$tenant->slug, $next]) : null,
            ],
            'lookups' => [
                'contracts' => Contract::query()
                    ->where('tenant_id', $tenant->id)
                    ->orderBy('code')
                    ->get(['id', 'code', 'name']),
                'types' => GedDocumentType::query()
                    ->where('tenant_id', $tenant->id)
                    ->orderBy('name')
                    ->get(['id', 'contract_id', 'name']),
                'tags' => GedTag::query()
                    ->where('tenant_id', $tenant->id)
                    ->orderBy('name')
                    ->get(['id', 'contract_id', 'name', 'color']),
                'correspondents' => GedCorrespondent::query()
                    ->where('tenant_id', $tenant->id)
                    ->orderBy('name')
                    ->get(['id', 'contract_id', 'name', 'email', 'document']),
            ],
            'quickStoreUrls' => [
                'correspondent' => route('tenant.ged.correspondents.store', $tenant->slug),
                'type' => route('tenant.ged.types.store', $tenant->slug),
                'tag' => route('tenant.ged.tags.store', $tenant->slug),
            ],
            'users' => $activeUsers,
            'permissionGroups' => $permissionCompanies
                ->map(fn (Empresa $empresa) => [
                    'id' => $empresa->id,
                    'name' => $empresa->nome,
                    'sigla' => $empresa->sigla,
                    'users_count' => (int) ($permissionCompanyUserCounts[$empresa->id] ?? 0),
                ])
                ->values(),
        ]);
    }

    private function gedPermissionCompanies(Tenant $tenant, GedDocument $document)
    {
        $contractCompanyIds = collect([
            $document->contract?->cliente_empresa_id,
            $document->contract?->construtora_empresa_id,
            $document->contract?->fiscalizadora_empresa_id,
        ])
            ->filter()
            ->map(fn ($id) => (int) $id);

        return Empresa::query()
            ->where('tenant_id', $tenant->id)
            ->when($document->contract_id, function ($query) use ($document, $contractCompanyIds) {
                $query->where(function ($inner) use ($document, $contractCompanyIds) {
                    $inner->where('contract_id', $document->contract_id);

                    if ($contractCompanyIds->isNotEmpty()) {
                        $inner->orWhereIn('id', $contractCompanyIds);
                    }
                });
            })
            ->orderBy('nome')
            ->get(['id', 'nome', 'sigla']);
    }

    public function bulkAction(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:reprocess,rotate'],
            'degrees' => ['nullable', 'integer', 'in:-90,90,180'],
            'document_ids' => ['required', 'array', 'min:1'],
            'document_ids.*' => ['integer'],
        ]);

        $documents = GedDocument::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('id', collect($data['document_ids'])->map(fn ($id) => (int) $id)->unique())
            ->get();

        abort_if($documents->isEmpty(), 422, 'Nenhum documento válido selecionado.');

        if ($data['action'] === 'reprocess') {
            foreach ($documents as $document) {
                $metadata = $document->metadata ?: [];
                $metadata['ocr'] = array_merge($metadata['ocr'] ?? [], [
                    'status' => 'queued',
                    'queued_at' => now()->toDateTimeString(),
                    'started_at' => null,
                    'finished_at' => null,
                    'engine' => 'ocrmypdf',
                    'message' => 'Documento reenviado para fila de OCR em lote.',
                ]);

                $document->forceFill([
                    'status' => 'processing',
                    'metadata' => $metadata,
                ])->save();

                $this->logGedEvent(
                    $document,
                    'ocr.queued',
                    'OCR reenviado para processamento',
                    'Documento reenviado manualmente para a fila de OCR em lote.',
                    ['engine' => 'ocrmypdf', 'bulk' => true],
                );

                ProcessGedDocumentOcrJob::dispatch($document->id)->afterResponse();
            }

            return back()->with('success', $documents->count().' documento(s) reenviado(s) para OCR.');
        }

        if ($data['action'] === 'rotate') {
            $degrees = (int) ($data['degrees'] ?? 90);
            $rotated = 0;

            foreach ($documents as $document) {
                if (! $this->isPdfDocument($document)) {
                    continue;
                }

                $paths = collect([$document->original_path, $document->archive_path])
                    ->filter()
                    ->unique()
                    ->values();

                foreach ($paths as $path) {
                    $this->rotateStoredPdf($document, $path, $degrees);
                }

                $metadata = $document->metadata ?: [];
                $metadata['rotation'] = [
                    'degrees' => (($metadata['rotation']['degrees'] ?? 0) + $degrees) % 360,
                    'last_degrees' => $degrees,
                    'rotated_at' => now()->toDateTimeString(),
                    'rotated_by_id' => auth()->id(),
                ];

                $document->forceFill(['metadata' => $metadata])->save();

                $this->logGedEvent(
                    $document,
                    'document.rotated',
                    'Documento rotacionado',
                    'O PDF foi girado permanentemente.',
                    ['degrees' => $degrees, 'bulk' => true],
                );

                $rotated++;
            }

            abort_if($rotated === 0, 422, 'Nenhum PDF válido foi encontrado para girar.');

            return back()->with('success', $rotated.' PDF(s) girado(s).');
        }

        return back();
    }

    private function isPdfDocument(GedDocument $document): bool
    {
        return $document->mime_type === 'application/pdf'
            || strtolower((string) $document->extension) === 'pdf'
            || str_ends_with(strtolower((string) $document->original_filename), '.pdf');
    }

    private function rotateStoredPdf(GedDocument $document, string $path, int $degrees): void
    {
        $disk = Storage::disk($document->storage_disk ?: 'public');

        if (! $disk->exists($path)) {
            return;
        }

        $inputPath = tempnam(sys_get_temp_dir(), 'ged-rotate-in-');
        $outputPath = tempnam(sys_get_temp_dir(), 'ged-rotate-out-');

        try {
            File::put($inputPath, $disk->get($path));

            $pdf = new Fpdi();
            $pdf->SetAutoPageBreak(false);
            $pageCount = $pdf->setSourceFile($inputPath);
            $rotation = (($degrees % 360) + 360) % 360;

            for ($pageNumber = 1; $pageNumber <= $pageCount; $pageNumber++) {
                $templateId = $pdf->importPage($pageNumber);
                $size = $pdf->getTemplateSize($templateId);

                $pdf->AddPage($size['orientation'], [$size['width'], $size['height']], $rotation);
                $pdf->useTemplate($templateId, 0, 0, $size['width'], $size['height']);
            }

            $pdf->Output('F', $outputPath);
            $disk->put($path, File::get($outputPath));
        } finally {
            if ($inputPath && File::exists($inputPath)) {
                File::delete($inputPath);
            }

            if ($outputPath && File::exists($outputPath)) {
                File::delete($outputPath);
            }
        }
    }

    public function bulkDownload(Request $request, Tenant $tenant): StreamedResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'string'],
            'include_archive' => ['nullable', 'boolean'],
            'include_original' => ['nullable', 'boolean'],
            'use_formatted_name' => ['nullable', 'boolean'],
        ]);

        $ids = collect(explode(',', $data['ids']))
            ->map(fn ($id) => (int) trim($id))
            ->filter()
            ->unique()
            ->values();

        abort_if($ids->isEmpty(), 422, 'Nenhum documento selecionado.');

        $includeArchive = $request->boolean('include_archive', true);
        $includeOriginal = $request->boolean('include_original', false);
        $useFormattedName = $request->boolean('use_formatted_name', false);

        abort_unless($includeArchive || $includeOriginal, 422, 'Selecione ao menos um tipo de arquivo para baixar.');

        $documents = GedDocument::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get();

        abort_if($documents->isEmpty(), 404);

        $zip = new ZipArchive();
        $zipPath = tempnam(sys_get_temp_dir(), 'ged-selected-');
        $zipName = 'documentos-ged-'.now()->format('Ymd-His').'.zip';

        abort_unless($zipPath && $zip->open($zipPath, ZipArchive::OVERWRITE) === true, 500, 'Não foi possível gerar o ZIP.');

        foreach ($documents as $document) {
            $disk = Storage::disk($document->storage_disk ?: 'public');

            if ($includeArchive && $document->archive_path && $disk->exists($document->archive_path)) {
                $zip->addFromString(
                    $this->bulkDownloadFilename($document, true, $useFormattedName),
                    $disk->get($document->archive_path),
                );
            }

            if ($includeOriginal && $document->original_path && $disk->exists($document->original_path)) {
                $zip->addFromString(
                    $this->bulkDownloadFilename($document, false, $useFormattedName),
                    $disk->get($document->original_path),
                );
            }
        }

        $zip->close();

        return response()->streamDownload(function () use ($zipPath) {
            readfile($zipPath);
            @unlink($zipPath);
        }, $zipName, [
            'Content-Type' => 'application/zip',
        ]);
    }

    private function bulkDownloadFilename(GedDocument $document, bool $archive, bool $formatted): string
    {
        if (! $formatted) {
            $filename = $archive
                ? ($document->archive_path ? basename($document->archive_path) : "{$document->title}.pdf")
                : ($document->original_filename ?: "{$document->title}.{$document->extension}");
        } else {
            $extension = $archive ? 'pdf' : ($document->extension ?: pathinfo($document->original_filename ?: '', PATHINFO_EXTENSION) ?: 'bin');
            $base = collect([$document->document_number, $document->title])
                ->filter()
                ->implode(' - ');
            $filename = ($base ?: 'documento-'.$document->id).'.'.$extension;
        }

        return Str::of($filename)
            ->replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '-')
            ->squish()
            ->toString();
    }

    public function download(Tenant $tenant, GedDocument $document): StreamedResponse
    {
        abort_unless((int) $document->tenant_id === (int) $tenant->id, 404);
        abort_unless(Storage::disk($document->storage_disk ?: 'public')->exists($document->original_path), 404);

        return Storage::disk($document->storage_disk ?: 'public')
            ->download($document->original_path, $document->original_filename);
    }

    public function preview(Tenant $tenant, GedDocument $document): StreamedResponse
    {
        abort_unless((int) $document->tenant_id === (int) $tenant->id, 404);

        $disk = Storage::disk($document->storage_disk ?: 'public');
        $path = $document->archive_path && $disk->exists($document->archive_path)
            ? $document->archive_path
            : $document->original_path;

        abort_unless($disk->exists($path), 404);

        $usingArchive = $path === $document->archive_path;
        $filename = str_replace('"', '', $usingArchive ? "{$document->title}-ocr.pdf" : ($document->original_filename ?: $document->title));

        return $disk->response($path, $filename, [
            'Content-Type' => $usingArchive ? 'application/pdf' : ($document->mime_type ?: 'application/octet-stream'),
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }
}

