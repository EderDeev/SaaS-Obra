<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessGedDocumentAttachmentOcrJob;
use App\Jobs\ProcessGedDocumentOcrJob;
use App\Models\Contract;
use App\Models\Empresa;
use App\Models\GedCorrespondent;
use App\Models\GedDocument;
use App\Models\GedDocumentAttachment;
use App\Models\GedDocumentEvent;
use App\Models\GedDocumentNote;
use App\Models\GedDocumentType;
use App\Models\GedDocumentVersion;
use App\Models\GedEmailAccount;
use App\Models\GedEmailProcessedMessage;
use App\Models\GedEmailRule;
use App\Models\GedTag;
use App\Models\Tenant;
use App\Models\TenantUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Barryvdh\DomPDF\Facade\Pdf;
use Inertia\Inertia;
use Inertia\Response;
use setasign\Fpdi\Fpdi;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

class GedController extends Controller
{
    public function tourPreview(Tenant $tenant): Response
    {
        return Inertia::render('Tenant/Ged/TourPreview', [
            'tenant' => $tenant,
        ]);
    }

    private const SECTIONS = [
        'details' => 'Detalhes',
        'content' => 'Conteúdo',
        'attachments' => 'Anexos',
        'metadata' => 'Metadados',
        'notes' => 'Notas',
        'history' => 'Histórico',
        'permissions' => 'Permissões',
    ];

    private const GED_ACCEPTED_EXTENSIONS = [
        'pdf',
    ];

    private const GED_ACCEPTED_MIME_PREFIXES = [];

    private const GED_ACCEPTED_MIME_TYPES = [
        'application/pdf',
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
                'trash' => GedDocument::onlyTrashed()->where('tenant_id', $tenant->id)->whereIn('contract_id', $accessibleContractIds)->count(),
            ],
        ]);
    }

    public function trash(Request $request, Tenant $tenant): Response
    {
        $accessibleContracts = $this->accessibleGedContracts($request, $tenant);
        $accessibleContractIds = $accessibleContracts->pluck('id');

        $documents = GedDocument::onlyTrashed()
            ->select('ged_documents.*')
            ->where('tenant_id', $tenant->id)
            ->whereIn('contract_id', $accessibleContractIds)
            ->with(['contract:id,code,name', 'type:id,name'])
            ->orderByDesc('deleted_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (GedDocument $document) => [
                'id' => $document->id,
                'title' => $document->title,
                'document_number' => $document->document_number,
                'original_filename' => $document->original_filename,
                'deleted_at' => $document->deleted_at?->format('Y-m-d H:i:s'),
                'details_url' => route('tenant.ged.details', [$tenant->slug, $document]),
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
            ]);

        return Inertia::render('Tenant/Ged/Trash', [
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
            ],
            'documents' => $documents,
            'trashDelayDays' => 30,
        ]);
    }

    public function trashAction(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:restore,empty'],
            'document_ids' => ['nullable', 'array'],
            'document_ids.*' => ['integer'],
        ]);

        $accessibleContractIds = $this->accessibleGedContracts($request, $tenant)->pluck('id');
        $ids = collect($data['document_ids'] ?? [])->map(fn ($id) => (int) $id)->filter()->unique()->values();

        $documentsQuery = GedDocument::onlyTrashed()
            ->where('tenant_id', $tenant->id)
            ->whereIn('contract_id', $accessibleContractIds)
            ->when($ids->isNotEmpty(), fn ($query) => $query->whereIn('id', $ids));

        $documents = $documentsQuery->get();

        abort_if($documents->isEmpty(), 422, 'Nenhum documento encontrado na lixeira.');

        if ($data['action'] === 'restore') {
            foreach ($documents as $document) {
                $document->restore();

                $this->logGedEvent(
                    $document,
                    'document.restored',
                    'Documento restaurado',
                    'O documento foi restaurado da lixeira.',
                );
            }

            return back()->with('success', $documents->count().' documento(s) restaurado(s).');
        }

        foreach ($documents as $document) {
            $this->deleteGedDocumentFiles($document);
            $document->forceDelete();
        }

        return back()->with('success', $documents->count().' documento(s) excluido(s) definitivamente.');
    }

    public function triage(Request $request, Tenant $tenant): Response
    {
        $accessibleContractIds = $this->accessibleGedContracts($request, $tenant)->pluck('id');

        $messages = GedEmailProcessedMessage::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', 'pending_triage')
            ->whereHas('rule', fn ($query) => $query->whereIn('contract_id', $accessibleContractIds))
            ->with(['account:id,name,email,mailbox,host,port,encryption,username,password,post_action,move_to', 'rule.contract:id,code,name', 'rule:id,account_id,contract_id,name,post_action'])
            ->latest('processed_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (GedEmailProcessedMessage $message) => [
                'id' => $message->id,
                'subject' => $message->subject,
                'from' => $message->from,
                'received_at' => $message->received_at?->format('Y-m-d H:i:s'),
                'processed_at' => $message->processed_at?->format('Y-m-d H:i:s'),
                'error' => $message->error,
                'attachments_count' => $message->attachments_count,
                'metadata' => $message->metadata ?? [],
                'resolve_url' => route('tenant.ged.triage.resolve', [$tenant->slug, $message]),
                'account' => $message->account ? [
                    'id' => $message->account->id,
                    'name' => $message->account->name,
                    'email' => $message->account->email,
                    'mailbox' => $message->account->mailbox,
                ] : null,
                'rule' => $message->rule ? [
                    'id' => $message->rule->id,
                    'name' => $message->rule->name,
                    'contract' => $message->rule->contract ? [
                        'id' => $message->rule->contract->id,
                        'code' => $message->rule->contract->code,
                        'name' => $message->rule->contract->name,
                    ] : null,
                ] : null,
            ]);

        return Inertia::render('Tenant/Ged/Triage', [
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
            ],
            'messages' => $messages,
        ]);
    }

    public function resolveTriage(Request $request, Tenant $tenant, GedEmailProcessedMessage $message): RedirectResponse
    {
        abort_unless((int) $message->tenant_id === (int) $tenant->id, 404);
        abort_unless($message->status === 'pending_triage', 422, 'Esta pendencia ja foi resolvida.');

        $data = $request->validate([
            'main_pdf' => ['required', 'string', 'max:500'],
        ]);

        $message->load(['account', 'rule']);
        abort_unless($message->account && $message->rule, 404);

        $accessibleContractIds = $this->accessibleGedContracts($request, $tenant)->pluck('id')->map(fn ($id) => (int) $id);
        abort_unless($accessibleContractIds->contains((int) $message->rule->contract_id), 403);

        $result = $this->resolveEmailTriageMessage($tenant, $message, $data['main_pdf']);

        if (! ($result['ok'] ?? false)) {
            return back()->with('error', $result['message'] ?? 'Nao foi possivel resolver a triagem.');
        }

        return back()->with('success', $result['message'] ?? 'Triagem resolvida.');
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

    public function email(Request $request, Tenant $tenant): Response
    {
        $accessibleContracts = $this->accessibleGedContracts($request, $tenant);
        $accessibleContractIds = $accessibleContracts->pluck('id');

        $accounts = GedEmailAccount::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('contract_id', $accessibleContractIds)
            ->with(['contract:id,code,name'])
            ->withCount('rules')
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get()
            ->map(fn (GedEmailAccount $account) => [
                'id' => $account->id,
                'contract_id' => $account->contract_id,
                'name' => $account->name,
                'email' => $account->email,
                'host' => $account->host,
                'port' => $account->port,
                'encryption' => $account->encryption,
                'username' => $account->username,
                'mailbox' => $account->mailbox,
                'post_action' => $account->post_action,
                'move_to' => $account->move_to,
                'settings' => $account->settings ?? [],
                'is_active' => $account->is_active,
                'last_checked_at' => $account->last_checked_at?->format('Y-m-d H:i:s'),
                'last_error' => $account->last_error,
                'rules_count' => $account->rules_count,
                'contract' => $account->contract ? [
                    'id' => $account->contract->id,
                    'code' => $account->contract->code,
                    'name' => $account->contract->name,
                ] : null,
            ]);

        $rules = GedEmailRule::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('contract_id', $accessibleContractIds)
            ->with(['account:id,name,email', 'contract:id,code,name', 'type:id,name', 'correspondent:id,name'])
            ->withCount('processedMessages')
            ->orderBy('priority')
            ->orderBy('name')
            ->get()
            ->map(fn (GedEmailRule $rule) => [
                'id' => $rule->id,
                'account_id' => $rule->account_id,
                'contract_id' => $rule->contract_id,
                'document_type_id' => $rule->document_type_id,
                'correspondent_id' => $rule->correspondent_id,
                'name' => $rule->name,
                'mailbox' => $rule->mailbox ?: 'INBOX',
                'max_age_days' => $rule->max_age_days,
                'from_contains' => $rule->from_contains,
                'to_contains' => $rule->to_contains,
                'subject_contains' => $rule->subject_contains,
                'body_contains' => $rule->body_contains,
                'attachment_name_contains' => $rule->attachment_name_contains,
                'include_attachment_patterns' => $rule->include_attachment_patterns,
                'exclude_attachment_patterns' => $rule->exclude_attachment_patterns,
                'consume_scope' => $rule->consume_scope ?: 'attachments',
                'attachment_type' => $rule->attachment_type ?: 'attachments',
                'pdf_layout' => $rule->pdf_layout ?: 'system',
                'post_action' => $rule->post_action ?: 'mark_read',
                'title_source' => $rule->title_source ?: 'subject',
                'assign_owner_from_rule' => $rule->assign_owner_from_rule,
                'tag_ids' => collect($rule->tag_ids ?? [])->map(fn ($id) => (int) $id)->values(),
                'consume_attachments' => $rule->consume_attachments,
                'priority' => $rule->priority,
                'is_active' => $rule->is_active,
                'processed_messages_count' => $rule->processed_messages_count,
                'processed_messages' => $rule->processedMessages()
                    ->latest('processed_at')
                    ->limit(30)
                    ->get()
                    ->map(fn (GedEmailProcessedMessage $message) => [
                        'id' => $message->id,
                        'subject' => $message->subject,
                        'from' => $message->from,
                        'received_at' => $message->received_at?->format('Y-m-d H:i:s'),
                        'processed_at' => $message->processed_at?->format('Y-m-d H:i:s'),
                        'status' => $message->status,
                        'error' => $message->error,
                        'attachments_count' => $message->attachments_count,
                        'imported_count' => $message->imported_count,
                        'duplicate_count' => $message->duplicate_count,
                        'metadata' => $message->metadata,
                    ]),
                'account' => $rule->account ? [
                    'id' => $rule->account->id,
                    'name' => $rule->account->name,
                    'email' => $rule->account->email,
                ] : null,
                'contract' => $rule->contract ? [
                    'id' => $rule->contract->id,
                    'code' => $rule->contract->code,
                    'name' => $rule->contract->name,
                ] : null,
                'type' => $rule->type ? [
                    'id' => $rule->type->id,
                    'name' => $rule->type->name,
                ] : null,
                'correspondent' => $rule->correspondent ? [
                    'id' => $rule->correspondent->id,
                    'name' => $rule->correspondent->name,
                ] : null,
            ]);

        return Inertia::render('Tenant/Ged/Email', [
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
            ],
            'contracts' => $accessibleContracts,
            'accounts' => $accounts,
            'rules' => $rules,
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
            'correspondents' => GedCorrespondent::query()
                ->where('tenant_id', $tenant->id)
                ->whereIn('contract_id', $accessibleContractIds)
                ->orderBy('name')
                ->get(['id', 'contract_id', 'name']),
        ]);
    }

    public function storeEmailAccount(Request $request, Tenant $tenant): RedirectResponse
    {
        $accessibleContractIds = $this->accessibleGedContracts($request, $tenant)->pluck('id')->map(fn ($id) => (int) $id);

        $data = $request->validate([
            'contract_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'string', 'max:180'],
            'host' => ['required', 'string', 'max:180'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'encryption' => ['required', 'in:none,ssl,tls,starttls'],
            'username' => ['required', 'string', 'max:180'],
            'password' => ['nullable', 'string', 'max:500'],
            'mailbox' => ['required', 'string', 'max:120'],
            'post_action' => ['required', 'in:none,mark_read,move,delete'],
            'move_to' => ['nullable', 'string', 'max:120'],
            'charset' => ['nullable', 'string', 'max:40'],
            'password_is_token' => ['boolean'],
            'is_active' => ['boolean'],
        ]);

        abort_unless($accessibleContractIds->contains((int) $data['contract_id']), 403);

        $data['email'] = $data['email'] ?: $data['username'];

        $settings = [
            'charset' => $data['charset'] ?? 'UTF-8',
            'password_is_token' => (bool) ($data['password_is_token'] ?? false),
        ];

        unset($data['charset'], $data['password_is_token']);

        GedEmailAccount::create([
            ...$data,
            'tenant_id' => $tenant->id,
            'settings' => $settings,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);

        return back()->with('success', 'Conta de e-mail cadastrada.');
    }

    public function updateEmailAccount(Request $request, Tenant $tenant, GedEmailAccount $account): RedirectResponse
    {
        $accessibleContractIds = $this->accessibleGedContracts($request, $tenant)->pluck('id')->map(fn ($id) => (int) $id);
        $this->authorizeGedEmailAccount($account, $tenant, $accessibleContractIds);

        $data = $request->validate([
            'contract_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'string', 'max:180'],
            'host' => ['required', 'string', 'max:180'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'encryption' => ['required', 'in:none,ssl,tls,starttls'],
            'username' => ['required', 'string', 'max:180'],
            'password' => ['nullable', 'string', 'max:500'],
            'mailbox' => ['required', 'string', 'max:120'],
            'post_action' => ['required', 'in:none,mark_read,move,delete'],
            'move_to' => ['nullable', 'string', 'max:120'],
            'charset' => ['nullable', 'string', 'max:40'],
            'password_is_token' => ['boolean'],
            'is_active' => ['boolean'],
        ]);

        abort_unless($accessibleContractIds->contains((int) $data['contract_id']), 403);

        $settings = [
            'charset' => $data['charset'] ?? 'UTF-8',
            'password_is_token' => (bool) ($data['password_is_token'] ?? false),
        ];

        $data['email'] = $data['email'] ?: $data['username'];

        if (($data['password'] ?? '') === '') {
            unset($data['password']);
        }

        unset($data['charset'], $data['password_is_token']);

        $account->update([
            ...$data,
            'settings' => $settings,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);

        return back()->with('success', 'Conta de e-mail atualizada.');
    }

    public function destroyEmailAccount(Request $request, Tenant $tenant, GedEmailAccount $account): RedirectResponse
    {
        $accessibleContractIds = $this->accessibleGedContracts($request, $tenant)->pluck('id')->map(fn ($id) => (int) $id);
        $this->authorizeGedEmailAccount($account, $tenant, $accessibleContractIds);

        $account->delete();

        return back()->with('success', 'Conta de e-mail removida.');
    }

    public function processEmailAccount(Request $request, Tenant $tenant, GedEmailAccount $account): RedirectResponse
    {
        $accessibleContractIds = $this->accessibleGedContracts($request, $tenant)->pluck('id')->map(fn ($id) => (int) $id);
        $this->authorizeGedEmailAccount($account, $tenant, $accessibleContractIds);

        $result = $this->processImapMessages($tenant, $account);

        $account->forceFill([
            'last_checked_at' => now(),
            'last_error' => $result['ok'] ? null : trim(($result['message'] ?? '').' '.($result['detail'] ?? '')),
        ])->save();

        if (! $result['ok']) {
            return back()->with('error', $result['message'] ?? 'Não foi possível processar a conta de e-mail.');
        }

        if (! empty($result['message'])) {
            return back()->with('success', $result['message']);
        }

        if (array_key_exists('matched', $result) && (int) ($result['pending_triage'] ?? 0) > 0) {
            return back()->with('success', sprintf(
                'Processamento concluido: %d e-mail(s) lido(s), %d compativel(is) com as regras, %d anexo(s) encontrado(s), %d documento(s) importado(s), %d duplicado(s) ignorado(s), %d aguardando triagem.',
                $result['messages'] ?? 0,
                $result['matched'] ?? 0,
                $result['attachments'] ?? 0,
                $result['imported'] ?? 0,
                $result['duplicates'] ?? 0,
                $result['pending_triage'] ?? 0,
            ));
        }

        if (array_key_exists('matched', $result)) {
            return back()->with('success', sprintf(
                'Processamento concluído: %d e-mail(s) lido(s), %d compatível(is) com as regras, %d anexo(s) encontrado(s), %d anexo(s) importado(s), %d duplicado(s) ignorado(s).',
                $result['messages'] ?? 0,
                $result['matched'] ?? 0,
                $result['attachments'] ?? 0,
                $result['imported'] ?? 0,
                $result['duplicates'] ?? 0,
            ));
        }

        return back()->with('success', sprintf(
            'Processamento concluído: %d e-mail(s) lido(s), %d anexo(s) encontrado(s), %d anexo(s) importado(s), %d duplicado(s) ignorado(s).',
            $result['messages'] ?? 0,
            $result['attachments'] ?? 0,
            $result['imported'] ?? 0,
            $result['duplicates'] ?? 0,
        ));
    }

    public function testEmailAccount(Request $request, Tenant $tenant): JsonResponse
    {
        $accessibleContractIds = $this->accessibleGedContracts($request, $tenant)->pluck('id')->map(fn ($id) => (int) $id);

        $data = $request->validate([
            'contract_id' => ['required', 'integer'],
            'host' => ['required', 'string', 'max:180'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'encryption' => ['required', 'in:none,ssl,tls,starttls'],
            'username' => ['required', 'string', 'max:180'],
            'password' => ['required', 'string', 'max:500'],
        ]);

        abort_unless($accessibleContractIds->contains((int) $data['contract_id']), 403);

        $result = $this->testImapConnection(
            host: $data['host'],
            port: (int) $data['port'],
            encryption: $data['encryption'],
            username: $data['username'],
            password: $data['password'],
        );

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    public function storeEmailRule(Request $request, Tenant $tenant): RedirectResponse
    {
        $accessibleContractIds = $this->accessibleGedContracts($request, $tenant)->pluck('id')->map(fn ($id) => (int) $id);

        $data = $request->validate([
            'account_id' => ['required', 'integer', 'exists:ged_email_accounts,id'],
            'contract_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:120'],
            'mailbox' => ['nullable', 'string', 'max:120'],
            'max_age_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'from_contains' => ['nullable', 'string', 'max:180'],
            'to_contains' => ['nullable', 'string', 'max:180'],
            'subject_contains' => ['nullable', 'string', 'max:180'],
            'body_contains' => ['nullable', 'string', 'max:180'],
            'attachment_name_contains' => ['nullable', 'string', 'max:180'],
            'include_attachment_patterns' => ['nullable', 'string', 'max:500'],
            'exclude_attachment_patterns' => ['nullable', 'string', 'max:500'],
            'consume_scope' => ['nullable', 'in:attachments,everything'],
            'attachment_type' => ['nullable', 'in:attachments,originals'],
            'pdf_layout' => ['nullable', 'in:system,none'],
            'post_action' => ['nullable', 'in:none,mark_read,move,delete'],
            'title_source' => ['nullable', 'in:subject,filename'],
            'assign_owner_from_rule' => ['boolean'],
            'document_type_id' => ['nullable', 'integer', 'exists:ged_document_types,id'],
            'correspondent_id' => ['nullable', 'integer', 'exists:ged_correspondents,id'],
            'tag_ids' => ['array'],
            'tag_ids.*' => ['integer', 'exists:ged_tags,id'],
            'consume_attachments' => ['boolean'],
            'priority' => ['nullable', 'integer', 'min:1', 'max:999'],
            'is_active' => ['boolean'],
        ]);

        abort_unless($accessibleContractIds->contains((int) $data['contract_id']), 403);

        $account = GedEmailAccount::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('contract_id', $accessibleContractIds)
            ->findOrFail($data['account_id']);

        abort_unless((int) $account->contract_id === (int) $data['contract_id'], 422);

        $this->assertGedReferenceBelongsToContract(GedDocumentType::class, $data['document_type_id'] ?? null, $tenant, (int) $data['contract_id']);
        $this->assertGedReferenceBelongsToContract(GedCorrespondent::class, $data['correspondent_id'] ?? null, $tenant, (int) $data['contract_id']);

        $tagIds = collect($data['tag_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($tagIds->isNotEmpty()) {
            $validTagCount = GedTag::query()
                ->where('tenant_id', $tenant->id)
                ->where('contract_id', $data['contract_id'])
                ->whereIn('id', $tagIds)
                ->count();

            abort_unless($validTagCount === $tagIds->count(), 422);
        }

        GedEmailRule::create([
            ...$data,
            'tenant_id' => $tenant->id,
            'account_id' => $account->id,
            'mailbox' => $data['mailbox'] ?: 'INBOX',
            'tag_ids' => $tagIds->all(),
            'consume_attachments' => (bool) ($data['consume_attachments'] ?? true),
            'consume_scope' => $data['consume_scope'] ?? 'attachments',
            'attachment_type' => $data['attachment_type'] ?? 'attachments',
            'pdf_layout' => $data['pdf_layout'] ?? 'system',
            'post_action' => $data['post_action'] ?? 'mark_read',
            'title_source' => $data['title_source'] ?? 'subject',
            'assign_owner_from_rule' => (bool) ($data['assign_owner_from_rule'] ?? false),
            'priority' => (int) ($data['priority'] ?? 10),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);

        return back()->with('success', 'Regra de e-mail cadastrada.');
    }

    public function updateEmailRule(Request $request, Tenant $tenant, GedEmailRule $rule): RedirectResponse
    {
        $accessibleContractIds = $this->accessibleGedContracts($request, $tenant)->pluck('id')->map(fn ($id) => (int) $id);
        $this->authorizeGedEmailRule($rule, $tenant, $accessibleContractIds);

        $data = $this->validateEmailRulePayload($request, $tenant, $accessibleContractIds);

        $rule->update($data);

        return back()->with('success', 'Regra de e-mail atualizada.');
    }

    public function destroyEmailRule(Request $request, Tenant $tenant, GedEmailRule $rule): RedirectResponse
    {
        $accessibleContractIds = $this->accessibleGedContracts($request, $tenant)->pluck('id')->map(fn ($id) => (int) $id);
        $this->authorizeGedEmailRule($rule, $tenant, $accessibleContractIds);

        $rule->delete();

        return back()->with('success', 'Regra de e-mail removida.');
    }

    private function authorizeGedEmailAccount(GedEmailAccount $account, Tenant $tenant, $accessibleContractIds): void
    {
        abort_unless((int) $account->tenant_id === (int) $tenant->id, 404);
        abort_unless($accessibleContractIds->contains((int) $account->contract_id), 403);
    }

    private function authorizeGedEmailRule(GedEmailRule $rule, Tenant $tenant, $accessibleContractIds): void
    {
        abort_unless((int) $rule->tenant_id === (int) $tenant->id, 404);
        abort_unless($accessibleContractIds->contains((int) $rule->contract_id), 403);
    }

    private function validateEmailRulePayload(Request $request, Tenant $tenant, $accessibleContractIds): array
    {
        $data = $request->validate([
            'account_id' => ['required', 'integer', 'exists:ged_email_accounts,id'],
            'contract_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:120'],
            'mailbox' => ['nullable', 'string', 'max:120'],
            'max_age_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'from_contains' => ['nullable', 'string', 'max:180'],
            'to_contains' => ['nullable', 'string', 'max:180'],
            'subject_contains' => ['nullable', 'string', 'max:180'],
            'body_contains' => ['nullable', 'string', 'max:180'],
            'attachment_name_contains' => ['nullable', 'string', 'max:180'],
            'include_attachment_patterns' => ['nullable', 'string', 'max:500'],
            'exclude_attachment_patterns' => ['nullable', 'string', 'max:500'],
            'consume_scope' => ['nullable', 'in:attachments,everything'],
            'attachment_type' => ['nullable', 'in:attachments,originals'],
            'pdf_layout' => ['nullable', 'in:system,none'],
            'post_action' => ['nullable', 'in:none,mark_read,move,delete'],
            'title_source' => ['nullable', 'in:subject,filename'],
            'assign_owner_from_rule' => ['boolean'],
            'document_type_id' => ['nullable', 'integer', 'exists:ged_document_types,id'],
            'correspondent_id' => ['nullable', 'integer', 'exists:ged_correspondents,id'],
            'tag_ids' => ['array'],
            'tag_ids.*' => ['integer', 'exists:ged_tags,id'],
            'consume_attachments' => ['boolean'],
            'priority' => ['nullable', 'integer', 'min:1', 'max:999'],
            'is_active' => ['boolean'],
        ]);

        abort_unless($accessibleContractIds->contains((int) $data['contract_id']), 403);

        $account = GedEmailAccount::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('contract_id', $accessibleContractIds)
            ->findOrFail($data['account_id']);

        abort_unless((int) $account->contract_id === (int) $data['contract_id'], 422);

        $this->assertGedReferenceBelongsToContract(GedDocumentType::class, $data['document_type_id'] ?? null, $tenant, (int) $data['contract_id']);
        $this->assertGedReferenceBelongsToContract(GedCorrespondent::class, $data['correspondent_id'] ?? null, $tenant, (int) $data['contract_id']);

        $tagIds = collect($data['tag_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($tagIds->isNotEmpty()) {
            $validTagCount = GedTag::query()
                ->where('tenant_id', $tenant->id)
                ->where('contract_id', $data['contract_id'])
                ->whereIn('id', $tagIds)
                ->count();

            abort_unless($validTagCount === $tagIds->count(), 422);
        }

        return [
            ...$data,
            'account_id' => $account->id,
            'mailbox' => $data['mailbox'] ?: 'INBOX',
            'tag_ids' => $tagIds->all(),
            'consume_attachments' => (bool) ($data['consume_attachments'] ?? true),
            'consume_scope' => $data['consume_scope'] ?? 'attachments',
            'attachment_type' => $data['attachment_type'] ?? 'attachments',
            'pdf_layout' => $data['pdf_layout'] ?? 'system',
            'post_action' => $data['post_action'] ?? 'mark_read',
            'title_source' => $data['title_source'] ?? 'subject',
            'assign_owner_from_rule' => (bool) ($data['assign_owner_from_rule'] ?? false),
            'priority' => (int) ($data['priority'] ?? 10),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }

    public function details(Tenant $tenant, GedDocument $document): Response
    {
        return $this->renderDocumentWorkspace($tenant, $document, 'details');
    }

    public function content(Tenant $tenant, GedDocument $document): Response
    {
        return $this->renderDocumentWorkspace($tenant, $document, 'content');
    }

    public function attachments(Tenant $tenant, GedDocument $document): Response
    {
        return $this->renderDocumentWorkspace($tenant, $document, 'attachments');
    }

    public function storeAttachment(Request $request, Tenant $tenant, GedDocument $document): RedirectResponse
    {
        abort_unless((int) $document->tenant_id === (int) $tenant->id, 404);

        $data = $request->validate([
            'files' => ['required', 'array', 'min:1'],
            'files.*' => ['file', 'max:102400'],
            'title' => ['nullable', 'string', 'max:180'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $storageDisk = (string) config('ged.document_disk', 'public');
        $directory = 'ged/'.$tenant->id.'/attachments/'.$document->id.'/'.now()->format('Y/m');
        $attachments = collect();

        foreach ($request->file('files', []) as $file) {
            $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());
            $checksum = hash_file('sha256', $file->getRealPath());
            $filename = Str::uuid().($extension ? ".{$extension}" : '');
            $path = $file->storeAs($directory, $filename, $storageDisk);

            $attachment = GedDocumentAttachment::create([
                'document_id' => $document->id,
                'uploaded_by_id' => auth()->id(),
                'title' => $data['title'] ?: null,
                'original_filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'extension' => $extension,
                'size_bytes' => $file->getSize() ?: 0,
                'checksum' => $checksum,
                'storage_disk' => $storageDisk,
                'path' => $path,
                'notes' => $data['notes'] ?: null,
            ]);

            $this->queueAttachmentOcrIfNeeded($attachment, 'Anexo PDF enviado para fila de OCR.');

            $attachments->push($attachment);
        }

        $this->logGedEvent(
            $document,
            'attachment.created',
            $attachments->count() === 1 ? 'Anexo adicionado' : 'Anexos adicionados',
            $attachments->count() === 1
                ? 'Um arquivo foi vinculado ao documento principal.'
                : $attachments->count().' arquivos foram vinculados ao documento principal.',
            [
                'attachment_ids' => $attachments->pluck('id')->all(),
                'original_filenames' => $attachments->pluck('original_filename')->all(),
                'total_size_bytes' => $attachments->sum('size_bytes'),
            ],
        );

        return back()->with('success', $attachments->count().' anexo(s) adicionado(s) ao documento.');
    }

    public function downloadAttachment(Tenant $tenant, GedDocument $document, GedDocumentAttachment $attachment): StreamedResponse
    {
        abort_unless((int) $document->tenant_id === (int) $tenant->id, 404);
        abort_unless((int) $attachment->document_id === (int) $document->id, 404);

        $disk = Storage::disk($attachment->storage_disk ?: 'public');
        abort_unless($disk->exists($attachment->path), 404);

        return $disk->download($attachment->path, $attachment->original_filename);
    }

    public function previewAttachment(Tenant $tenant, GedDocument $document, GedDocumentAttachment $attachment): StreamedResponse
    {
        abort_unless((int) $document->tenant_id === (int) $tenant->id, 404);
        abort_unless((int) $attachment->document_id === (int) $document->id, 404);
        abort_unless($attachment->isPdf(), 404);

        $disk = Storage::disk($attachment->storage_disk ?: 'public');
        $path = $attachment->archive_path && $disk->exists($attachment->archive_path)
            ? $attachment->archive_path
            : $attachment->path;

        abort_unless($disk->exists($path), 404);

        $filename = str_replace('"', '', ($attachment->title ?: pathinfo($attachment->original_filename, PATHINFO_FILENAME)).'-ocr.pdf');

        return $disk->response($path, $filename, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }

    public function queueAttachmentOcr(Tenant $tenant, GedDocument $document, GedDocumentAttachment $attachment): RedirectResponse
    {
        abort_unless((int) $document->tenant_id === (int) $tenant->id, 404);
        abort_unless((int) $attachment->document_id === (int) $document->id, 404);
        abort_unless($attachment->isPdf(), 404);

        $this->queueAttachmentOcrIfNeeded($attachment, 'Anexo PDF reenviado para fila de OCR.', force: true);

        $this->logGedEvent(
            $document,
            'attachment.ocr.queued',
            'OCR do anexo reenviado',
            'Anexo PDF reenviado manualmente para a fila de OCR.',
            [
                'attachment_id' => $attachment->id,
                'original_filename' => $attachment->original_filename,
                'engine' => 'ocrmypdf',
            ],
        );

        return back()->with('success', 'Anexo reenviado para a fila de OCR.');
    }

    public function updateAttachment(Request $request, Tenant $tenant, GedDocument $document, GedDocumentAttachment $attachment): RedirectResponse
    {
        abort_unless((int) $document->tenant_id === (int) $tenant->id, 404);
        abort_unless((int) $attachment->document_id === (int) $document->id, 404);

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:180'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $before = $attachment->only(['title', 'notes']);

        $attachment->update([
            'title' => $data['title'] ?: null,
            'notes' => $data['notes'] ?: null,
        ]);

        $this->logGedEvent(
            $document,
            'attachment.updated',
            'Anexo atualizado',
            'Titulo ou observacao de um anexo foi atualizado.',
            [
                'attachment_id' => $attachment->id,
                'original_filename' => $attachment->original_filename,
                'before' => $before,
                'after' => $attachment->only(['title', 'notes']),
            ],
        );

        return back()->with('success', 'Anexo atualizado.');
    }

    public function destroyAttachment(Tenant $tenant, GedDocument $document, GedDocumentAttachment $attachment): RedirectResponse
    {
        abort_unless((int) $document->tenant_id === (int) $tenant->id, 404);
        abort_unless((int) $attachment->document_id === (int) $document->id, 404);

        Storage::disk($attachment->storage_disk ?: 'public')->delete(array_filter([
            $attachment->path,
            $attachment->archive_path,
        ]));

        $this->logGedEvent(
            $document,
            'attachment.deleted',
            'Anexo excluido',
            'Um arquivo vinculado ao documento principal foi excluido.',
            [
                'attachment_id' => $attachment->id,
                'original_filename' => $attachment->original_filename,
            ],
        );

        $attachment->delete();

        return back()->with('success', 'Anexo excluido.');
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
            'queue' => (string) config('ged.ocr.queue', 'ged'),
            'timeout_seconds' => (int) config('ged.ocr.timeout', 300),
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

        $this->dispatchOcrJob($document);

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

    public function destroy(Tenant $tenant, GedDocument $document): RedirectResponse
    {
        abort_unless((int) $document->tenant_id === (int) $tenant->id, 404);

        $title = $document->title;

        $this->logGedEvent(
            $document,
            'document.trashed',
            'Documento movido para a lixeira',
            'O documento foi movido para a lixeira.',
        );

        $document->delete();

        return redirect()
            ->route('tenant.ged.index', $tenant->slug)
            ->with('success', "Documento {$title} movido para a lixeira.");
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
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());

        if (! $this->isAcceptedGedDocumentType($file->getClientOriginalName(), $file->getMimeType(), $extension)) {
            throw ValidationException::withMessages([
                'file' => 'Tipo de arquivo nao aceito. Envie apenas PDF.',
            ]);
        }

        $checksum = hash_file('sha256', $file->getRealPath());
        $originalMd5 = hash_file('md5', $file->getRealPath());
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
                        'queue' => (string) config('ged.ocr.queue', 'ged'),
                        'timeout_seconds' => (int) config('ged.ocr.timeout', 300),
                        'message' => config('ged.ocr.enabled', true)
                            ? 'Documento enviado para fila de OCR.'
                            : 'OCR automático desativado.',
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
                'notes' => 'Versão inicial enviada pelo GED.',
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
            $this->dispatchOcrJob($document);
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

    public function updateType(Request $request, Tenant $tenant, GedDocumentType $type): RedirectResponse
    {
        abort_unless((int) $type->tenant_id === (int) $tenant->id, 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'contract_id' => ['required', 'integer'],
        ]);

        abort_unless(Contract::where('tenant_id', $tenant->id)->whereKey($data['contract_id'])->exists(), 422);

        $type->update([
            'name' => $data['name'],
            'contract_id' => $data['contract_id'],
        ]);

        return back()->with('success', 'Tipo documental atualizado.');
    }

    public function destroyType(Tenant $tenant, GedDocumentType $type): RedirectResponse
    {
        abort_unless((int) $type->tenant_id === (int) $tenant->id, 404);

        if ($type->documents()->exists()) {
            return back()->withErrors([
                'type' => 'Nao e possivel excluir um tipo documental com documentos cadastrados.',
            ]);
        }

        $type->delete();

        return back()->with('success', 'Tipo documental excluido.');
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

    public function updateTag(Request $request, Tenant $tenant, GedTag $tag): RedirectResponse
    {
        abort_unless((int) $tag->tenant_id === (int) $tenant->id, 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'color' => ['nullable', 'string', 'max:7'],
            'contract_id' => ['required', 'integer'],
        ]);

        abort_unless(Contract::where('tenant_id', $tenant->id)->whereKey($data['contract_id'])->exists(), 422);

        $tag->update([
            'name' => $data['name'],
            'contract_id' => $data['contract_id'],
            'color' => $data['color'] ?: '#2563eb',
        ]);

        return back()->with('success', 'Etiqueta atualizada.');
    }

    public function destroyTag(Tenant $tenant, GedTag $tag): RedirectResponse
    {
        abort_unless((int) $tag->tenant_id === (int) $tenant->id, 404);

        if ($tag->documents()->exists()) {
            return back()->withErrors([
                'tag' => 'Nao e possivel excluir uma etiqueta com documentos cadastrados.',
            ]);
        }

        $tag->delete();

        return back()->with('success', 'Etiqueta excluida.');
    }

    private function nextGedDocumentSequence(Tenant $tenant, ?string $documentDate = null): array
    {
        Tenant::query()
            ->whereKey($tenant->id)
            ->lockForUpdate()
            ->first();

        $year = CarbonImmutable::parse($documentDate ?: now())->year;

        $lastDocument = GedDocument::withTrashed()
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

    private function markStalledOcrIfNeeded(GedDocument $document): void
    {
        $metadata = $document->metadata ?: [];
        $ocr = $metadata['ocr'] ?? [];

        if (($ocr['status'] ?? null) !== 'processing' || filled($document->extracted_text)) {
            return;
        }

        $reference = $ocr['started_at'] ?? $ocr['queued_at'] ?? null;

        if (! $reference) {
            return;
        }

        $timeout = max(60, (int) ($ocr['timeout_seconds'] ?? config('ged.ocr.timeout', 300)));
        $referenceAt = CarbonImmutable::parse($reference);

        if ($referenceAt->addSeconds($timeout)->isFuture()) {
            return;
        }

        $metadata['ocr'] = array_merge($ocr, [
            'status' => 'failed',
            'finished_at' => now()->toDateTimeString(),
            'message' => 'O OCR excedeu o tempo limite de processamento. Reenvie o documento para a fila de OCR.',
            'stalled_detected_at' => now()->toDateTimeString(),
            'elapsed_seconds' => $referenceAt->diffInSeconds(now()),
        ]);

        $document->forceFill([
            'status' => 'failed',
            'processed_at' => now(),
            'metadata' => $metadata,
        ])->save();

        $this->logGedEvent(
            $document,
            'ocr.timeout',
            'OCR excedeu o tempo limite',
            'O processamento OCR ficou em aberto além do timeout configurado e foi marcado como falho automaticamente.',
            [
                'engine' => $ocr['engine'] ?? 'ocrmypdf',
                'timeout_seconds' => $timeout,
            ],
        );

        $document->refresh();
    }

    private function dispatchOcrJob(GedDocument $document): void
    {
        ProcessGedDocumentOcrJob::dispatch($document->id)
            ->onConnection((string) config('ged.ocr.connection', 'database'))
            ->onQueue((string) config('ged.ocr.queue', 'ged'));
    }

    private function dispatchAttachmentOcrJob(GedDocumentAttachment $attachment): void
    {
        ProcessGedDocumentAttachmentOcrJob::dispatch($attachment->id)
            ->onConnection((string) config('ged.ocr.connection', 'database'))
            ->onQueue((string) config('ged.ocr.queue', 'ged'));
    }

    private function queueAttachmentOcrIfNeeded(GedDocumentAttachment $attachment, string $message, bool $force = false): void
    {
        if (! $attachment->isPdf()) {
            return;
        }

        if (! (bool) config('ged.ocr.enabled', true)) {
            $attachment->forceFill([
                'ocr_status' => 'disabled',
                'ocr_metadata' => array_merge($attachment->ocr_metadata ?: [], [
                    'status' => 'disabled',
                    'message' => 'OCR desabilitado no ambiente.',
                ]),
            ])->save();

            return;
        }

        if (! $force && in_array($attachment->ocr_status, ['queued', 'processing', 'indexed'], true)) {
            return;
        }

        $attachment->forceFill([
            'ocr_status' => 'queued',
            'ocr_metadata' => array_merge($attachment->ocr_metadata ?: [], [
                'status' => 'queued',
                'queued_at' => now()->toDateTimeString(),
                'started_at' => null,
                'finished_at' => null,
                'engine' => 'ocrmypdf',
                'queue' => (string) config('ged.ocr.queue', 'ged'),
                'timeout_seconds' => (int) config('ged.ocr.timeout', 300),
                'message' => $message,
            ]),
        ])->save();

        $this->dispatchAttachmentOcrJob($attachment);
    }

    private function renderDocumentWorkspace(Tenant $tenant, GedDocument $document, string $section): Response
    {
        abort_unless((int) $document->tenant_id === (int) $tenant->id, 404);
        abort_unless(array_key_exists($section, self::SECTIONS), 404);

        $this->markStalledOcrIfNeeded($document);

        $document->load([
            'contract:id,code,name,cliente_empresa_id,construtora_empresa_id,fiscalizadora_empresa_id',
            'type:id,name',
            'correspondent:id,name',
            'tags:id,name,color',
            'uploader:id,name,email',
            'versions.uploader:id,name,email',
            'attachments.uploader:id,name,email',
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
                'attachment_store_url' => route('tenant.ged.attachments.store', [$tenant->slug, $document]),
                'permissions_update_url' => route('tenant.ged.permissions.update', [$tenant->slug, $document]),
                'update_url' => route('tenant.ged.update', [$tenant->slug, $document]),
                'delete_url' => route('tenant.ged.destroy', [$tenant->slug, $document]),
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
                'attachments' => $document->attachments->map(fn (GedDocumentAttachment $attachment) => [
                    'id' => $attachment->id,
                    'title' => $attachment->title,
                    'original_filename' => $attachment->original_filename,
                    'mime_type' => $attachment->mime_type,
                    'extension' => $attachment->extension,
                    'size_bytes' => $attachment->size_bytes,
                    'checksum' => $attachment->checksum,
                    'notes' => $attachment->notes,
                    'is_pdf' => $attachment->isPdf(),
                    'ocr_status' => $attachment->ocr_status,
                    'ocr_metadata' => $attachment->ocr_metadata ?: [],
                    'extracted_text' => $attachment->extracted_text,
                    'archive_path' => $attachment->archive_path,
                    'page_count' => $attachment->page_count,
                    'processed_at' => $attachment->processed_at?->format('Y-m-d H:i:s'),
                    'created_at' => $attachment->created_at?->format('Y-m-d H:i:s'),
                    'download_url' => route('tenant.ged.attachments.download', [$tenant->slug, $document, $attachment]),
                    'preview_url' => $attachment->isPdf() ? route('tenant.ged.attachments.preview', [$tenant->slug, $document, $attachment]) : null,
                    'ocr_url' => $attachment->isPdf() ? route('tenant.ged.attachments.ocr', [$tenant->slug, $document, $attachment]) : null,
                    'update_url' => route('tenant.ged.attachments.update', [$tenant->slug, $document, $attachment]),
                    'delete_url' => route('tenant.ged.attachments.destroy', [$tenant->slug, $document, $attachment]),
                    'uploader' => $attachment->uploader ? [
                        'id' => $attachment->uploader->id,
                        'name' => $attachment->uploader->name,
                        'email' => $attachment->uploader->email,
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
            'action' => ['required', 'in:reprocess,rotate,trash'],
            'degrees' => ['nullable', 'integer', 'in:-90,90,180'],
            'document_ids' => ['required', 'array', 'min:1'],
            'document_ids.*' => ['integer'],
        ]);

        $documents = GedDocument::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('id', collect($data['document_ids'])->map(fn ($id) => (int) $id)->unique())
            ->get();

        abort_if($documents->isEmpty(), 422, 'Nenhum documento válido selecionado.');

        if ($data['action'] === 'trash') {
            foreach ($documents as $document) {
                $this->logGedEvent(
                    $document,
                    'document.trashed',
                    'Documento movido para a lixeira',
                    'O documento foi movido para a lixeira por acao em massa.',
                );

                $document->delete();
            }

            return back()->with('success', $documents->count().' documento(s) movido(s) para a lixeira.');
        }

        if ($data['action'] === 'reprocess') {
            foreach ($documents as $document) {
                $metadata = $document->metadata ?: [];
                $metadata['ocr'] = array_merge($metadata['ocr'] ?? [], [
                    'status' => 'queued',
                    'queued_at' => now()->toDateTimeString(),
                    'started_at' => null,
                    'finished_at' => null,
                    'engine' => 'ocrmypdf',
                    'queue' => (string) config('ged.ocr.queue', 'ged'),
                    'timeout_seconds' => (int) config('ged.ocr.timeout', 300),
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

                $this->dispatchOcrJob($document);
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

    private function deleteGedDocumentFiles(GedDocument $document): void
    {
        $pathsByDisk = [];

        foreach ([$document->original_path, $document->archive_path, $document->thumbnail_path] as $path) {
            if ($path) {
                $pathsByDisk[$document->storage_disk ?: 'public'][] = $path;
            }
        }

        foreach ($document->versions as $version) {
            if ($version->path) {
                $pathsByDisk[$version->storage_disk ?: $document->storage_disk ?: 'public'][] = $version->path;
            }
        }

        foreach ($document->attachments as $attachment) {
            if ($attachment->path) {
                $pathsByDisk[$attachment->storage_disk ?: $document->storage_disk ?: 'public'][] = $attachment->path;
            }
        }

        foreach ($pathsByDisk as $diskName => $paths) {
            Storage::disk($diskName)->delete(array_values(array_unique($paths)));
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

    private function accessibleGedContracts(Request $request, Tenant $tenant)
    {
        $user = $request->user();
        $tenantRole = $user?->tenantRole($tenant);
        $canSeeAllContracts = $user?->is_platform_admin || in_array($tenantRole, ['tenant_owner', 'tenant_admin'], true);

        return Contract::query()
            ->where('tenant_id', $tenant->id)
            ->when(! $canSeeAllContracts, function ($query) use ($user): void {
                $query->whereHas('participants', function ($query) use ($user): void {
                    $query->where('user_id', $user->id)->where('status', 'active');
                });
            })
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }

    private function assertGedReferenceBelongsToContract(string $modelClass, mixed $id, Tenant $tenant, int $contractId): void
    {
        if (! $id) {
            return;
        }

        abort_unless(
            $modelClass::query()
                ->where('tenant_id', $tenant->id)
                ->where('contract_id', $contractId)
                ->whereKey($id)
                ->exists(),
            422
        );
    }

    private function processImapMessages(Tenant $tenant, GedEmailAccount $account): array
    {
        $rules = GedEmailRule::query()
            ->where('tenant_id', $tenant->id)
            ->where('account_id', $account->id)
            ->where('contract_id', $account->contract_id)
            ->where('is_active', true)
            ->orderBy('priority')
            ->orderBy('name')
            ->get();

        if ($rules->isEmpty()) {
            return [
                'ok' => true,
                'messages' => 0,
                'imported' => 0,
                'duplicates' => 0,
                'message' => 'Nenhuma regra ativa para esta conta.',
            ];
        }

        $connection = $this->openImapConnection($account);
        if (! ($connection['ok'] ?? false)) {
            return $connection;
        }

        $stream = $connection['stream'];
        $stats = [
            'ok' => true,
            'messages' => 0,
            'matched' => 0,
            'attachments' => 0,
            'imported' => 0,
            'duplicates' => 0,
            'errors' => 0,
            'pending_triage' => 0,
        ];

        try {
            $select = $this->imapCommand($stream, 'SELECT '.$this->imapQuoted($account->mailbox ?: 'INBOX'));
            if (! $select['ok']) {
                throw new \RuntimeException('Não foi possível abrir a pasta IMAP. '.$select['response']);
            }

            $ids = $rules
                ->flatMap(fn (GedEmailRule $rule) => $this->imapSearchIds($stream, $this->imapSearchCriteriaForRule($rule)))
                ->unique()
                ->sort()
                ->values()
                ->all();

            if ($ids === []) {
                $ids = array_slice($this->imapSearchIds($stream, 'UNSEEN'), -100);
            }

            $ids = array_slice(array_reverse($ids), 0, 100);

            foreach ($ids as $messageId) {
                $raw = $this->imapFetchRawMessage($stream, $messageId);
                if (! $raw) {
                    $stats['errors']++;
                    continue;
                }

                $stats['messages']++;
                $parsed = $this->parseRawEmailMessage($raw);
                $attachmentNames = collect($parsed['attachments'])->pluck('filename')->filter()->values()->all();
                $stats['attachments'] += count($parsed['attachments']);
                $matchedRules = $rules->filter(fn (GedEmailRule $rule) => $this->emailRuleMatches($rule, $parsed, $attachmentNames));

                if ($matchedRules->isEmpty()) {
                    continue;
                }

                $stats['matched']++;
                $messageHasPendingTriage = false;

                foreach ($matchedRules as $rule) {
                    $ruleImported = 0;
                    $ruleDuplicates = 0;
                    $ruleErrors = 0;
                    $lastDocumentId = null;

                    if ($this->emailRuleAlreadyImportedMessage($tenant, $account, $rule, $messageId, $parsed)) {
                        $stats['duplicates']++;
                        $ruleDuplicates++;
                        continue;
                    }

                    $matchingAttachments = collect($parsed['attachments'])
                        ->filter(fn (array $attachment) => $this->emailAttachmentMatchesRule($rule, $attachment['filename'] ?? ''))
                        ->values();
                    $shouldCreateEmailDocument = ($rule->consume_scope ?: 'attachments') === 'everything';

                    if ($shouldCreateEmailDocument) {
                        $created = $this->createGedDocumentFromEmailMessage($tenant, $account, $rule, $parsed);

                        if ($created === 'duplicate') {
                            $stats['duplicates']++;
                            $ruleDuplicates++;
                        } elseif ($created instanceof GedDocument) {
                            $stats['imported']++;
                            $ruleImported++;
                            $lastDocumentId = $created->id;

                            if ($rule->consume_attachments) {
                                foreach ($matchingAttachments as $supportAttachment) {
                                    $this->createGedDocumentAttachmentFromEmailAttachment($created, $supportAttachment, $account, $rule, $parsed);
                                }
                            }
                        } else {
                            $stats['errors']++;
                            $ruleErrors++;
                        }

                        $this->recordProcessedEmail($tenant, $account, $rule, $messageId, $parsed, [
                            'document_id' => $lastDocumentId,
                            'status' => $ruleErrors > 0 ? 'error' : 'success',
                            'attachments_count' => $matchingAttachments->count(),
                            'imported_count' => $ruleImported,
                            'duplicate_count' => $ruleDuplicates,
                            'error' => $ruleErrors > 0
                                ? 'Nao foi possivel converter o e-mail em PDF.'
                                : ($ruleImported === 0 && $ruleDuplicates === 0 ? 'E-mail processado, mas nenhum documento foi importado.' : null),
                            'metadata' => [
                                'consume_scope' => 'everything',
                                'email_pdf' => true,
                                'support_attachment_names' => $rule->consume_attachments
                                    ? $matchingAttachments->map(fn (array $attachment) => $attachment['filename'] ?? 'anexo')->values()->all()
                                    : [],
                            ],
                        ]);

                        continue;
                    }

                    if (! $rule->consume_attachments) {
                        $this->recordProcessedEmail($tenant, $account, $rule, $messageId, $parsed, [
                            'status' => 'success',
                            'attachments_count' => count($parsed['attachments']),
                            'imported_count' => 0,
                            'duplicate_count' => 0,
                            'error' => 'Regra processada sem importar anexos.',
                        ]);

                        continue;
                    }

                    $pdfAttachments = $matchingAttachments
                        ->filter(fn (array $attachment) => $this->isEmailAttachmentPdf($attachment))
                        ->values();
                    $supportAttachments = $matchingAttachments
                        ->reject(fn (array $attachment) => $this->isEmailAttachmentPdf($attachment))
                        ->values();

                    if ($pdfAttachments->count() > 1) {
                        $stats['pending_triage']++;
                        $messageHasPendingTriage = true;

                        $this->recordProcessedEmail($tenant, $account, $rule, $messageId, $parsed, [
                            'status' => 'pending_triage',
                            'attachments_count' => $matchingAttachments->count(),
                            'imported_count' => 0,
                            'duplicate_count' => 0,
                            'error' => 'E-mail recebido com '.$pdfAttachments->count().' PDFs. Escolha o documento principal.',
                            'metadata' => [
                                'pdf_candidates' => $pdfAttachments->map(fn (array $attachment) => $attachment['filename'] ?? 'anexo.pdf')->values()->all(),
                                'support_attachment_names' => $supportAttachments->map(fn (array $attachment) => $attachment['filename'] ?? 'anexo')->values()->all(),
                            ],
                        ]);

                        continue;
                    }

                    if ($pdfAttachments->count() === 0) {
                        $stats['errors']++;
                        $ruleErrors++;

                        $this->recordProcessedEmail($tenant, $account, $rule, $messageId, $parsed, [
                            'status' => 'error',
                            'attachments_count' => $matchingAttachments->count(),
                            'imported_count' => 0,
                            'duplicate_count' => 0,
                            'error' => 'E-mail processado, mas nenhum PDF principal foi encontrado.',
                            'metadata' => [
                                'support_attachment_names' => $supportAttachments->map(fn (array $attachment) => $attachment['filename'] ?? 'anexo')->values()->all(),
                            ],
                        ]);

                        continue;
                    }

                    $created = $this->createGedDocumentFromEmailAttachment($tenant, $account, $rule, $parsed, $pdfAttachments->first());

                    if ($created === 'duplicate') {
                        $stats['duplicates']++;
                        $ruleDuplicates++;
                    } elseif ($created instanceof GedDocument) {
                        $stats['imported']++;
                        $ruleImported++;
                        $lastDocumentId = $created->id;

                        foreach ($supportAttachments as $supportAttachment) {
                            $this->createGedDocumentAttachmentFromEmailAttachment($created, $supportAttachment, $account, $rule, $parsed);
                        }
                    } else {
                        $stats['errors']++;
                        $ruleErrors++;
                    }

                    $this->recordProcessedEmail($tenant, $account, $rule, $messageId, $parsed, [
                        'document_id' => $lastDocumentId,
                        'status' => $ruleErrors > 0 ? 'error' : 'success',
                        'attachments_count' => $matchingAttachments->count(),
                        'imported_count' => $ruleImported,
                        'duplicate_count' => $ruleDuplicates,
                        'error' => $ruleErrors > 0
                            ? 'Alguns anexos não puderam ser importados.'
                            : ($ruleImported === 0 && $ruleDuplicates === 0 ? 'E-mail processado, mas nenhum anexo foi importado.' : null),
                        'metadata' => [
                            'main_pdf' => $pdfAttachments->first()['filename'] ?? null,
                            'support_attachment_names' => $supportAttachments->map(fn (array $attachment) => $attachment['filename'] ?? 'anexo')->values()->all(),
                        ],
                    ]);
                }

                if (! $messageHasPendingTriage) {
                    $this->applyEmailPostAction($stream, $messageId, $account, $matchedRules->first());
                }
            }

            $this->imapCommand($stream, 'LOGOUT');
        } catch (\Throwable $exception) {
            if (is_resource($stream)) {
                @fwrite($stream, $this->imapTag()." LOGOUT\r\n");
                @fclose($stream);
            }

            return [
                'ok' => false,
                'message' => 'Falha ao processar e-mails: '.$exception->getMessage(),
            ];
        }

        if (is_resource($stream)) {
            @fclose($stream);
        }

        return $stats;
    }

    private function resolveEmailTriageMessage(Tenant $tenant, GedEmailProcessedMessage $message, string $mainPdfFilename): array
    {
        /** @var GedEmailAccount|null $account */
        $account = $message->account;
        /** @var GedEmailRule|null $rule */
        $rule = $message->rule;

        if (! $account || ! $rule) {
            return ['ok' => false, 'message' => 'Conta ou regra da triagem nao encontrada.'];
        }

        $connection = $this->openImapConnection($account);
        if (! ($connection['ok'] ?? false)) {
            return $connection;
        }

        $stream = $connection['stream'];

        try {
            $select = $this->imapCommand($stream, 'SELECT '.$this->imapQuoted($account->mailbox ?: 'INBOX'));
            if (! $select['ok']) {
                throw new \RuntimeException('Nao foi possivel abrir a pasta IMAP. '.$select['response']);
            }

            $messageSequenceId = $this->findTriageImapMessageSequence($stream, $message);
            if (! $messageSequenceId) {
                throw new \RuntimeException('O e-mail original nao foi encontrado na caixa configurada.');
            }

            $raw = $this->imapFetchRawMessage($stream, $messageSequenceId);
            if (! $raw) {
                throw new \RuntimeException('Nao foi possivel ler o e-mail original.');
            }

            $parsed = $this->parseRawEmailMessage($raw);
            $matchingAttachments = collect($parsed['attachments'])
                ->filter(fn (array $attachment) => $this->emailAttachmentMatchesRule($rule, $attachment['filename'] ?? ''))
                ->values();
            $mainPdf = $matchingAttachments->first(fn (array $attachment) => ($attachment['filename'] ?? '') === $mainPdfFilename && $this->isEmailAttachmentPdf($attachment));

            if (! $mainPdf) {
                throw new \RuntimeException('O PDF principal selecionado nao foi encontrado neste e-mail.');
            }

            $supportAttachments = $matchingAttachments
                ->reject(fn (array $attachment) => ($attachment['filename'] ?? '') === $mainPdfFilename)
                ->values();

            $created = $this->createGedDocumentFromEmailAttachment($tenant, $account, $rule, $parsed, $mainPdf);
            $importedCount = 0;
            $duplicateCount = 0;
            $documentId = null;

            if ($created === 'duplicate') {
                $duplicateCount = 1;
            } elseif ($created instanceof GedDocument) {
                $importedCount = 1;
                $documentId = $created->id;

                foreach ($supportAttachments as $supportAttachment) {
                    $this->createGedDocumentAttachmentFromEmailAttachment($created, $supportAttachment, $account, $rule, $parsed);
                }
            } else {
                throw new \RuntimeException('Nao foi possivel importar o PDF principal selecionado.');
            }

            $message->update([
                'document_id' => $documentId,
                'status' => 'success',
                'error' => $created === 'duplicate' ? 'PDF principal ja existia no GED e foi tratado como duplicado.' : null,
                'imported_count' => $importedCount,
                'duplicate_count' => $duplicateCount,
                'processed_at' => now(),
                'metadata' => array_merge($message->metadata ?? [], [
                    'resolved_at' => now()->toDateTimeString(),
                    'resolved_main_pdf' => $mainPdfFilename,
                    'support_attachment_names' => $supportAttachments->map(fn (array $attachment) => $attachment['filename'] ?? 'anexo')->values()->all(),
                ]),
            ]);

            $this->applyEmailPostAction($stream, $messageSequenceId, $account, $rule);
            $this->imapCommand($stream, 'LOGOUT');

            return [
                'ok' => true,
                'message' => $created === 'duplicate'
                    ? 'Triagem resolvida: o PDF principal ja existia no GED.'
                    : 'Triagem resolvida e documento importado.',
            ];
        } catch (\Throwable $exception) {
            $message->update([
                'error' => $exception->getMessage(),
                'processed_at' => now(),
            ]);

            if (is_resource($stream)) {
                @fwrite($stream, $this->imapTag()." LOGOUT\r\n");
                @fclose($stream);
            }

            return ['ok' => false, 'message' => $exception->getMessage()];
        } finally {
            if (is_resource($stream)) {
                @fclose($stream);
            }
        }
    }

    private function findTriageImapMessageSequence($stream, GedEmailProcessedMessage $message): ?int
    {
        if ($message->message_id) {
            $ids = $this->imapSearchIds($stream, 'HEADER Message-ID '.$this->imapQuoted($message->message_id));
            if ($ids !== []) {
                return (int) end($ids);
            }
        }

        if ($message->message_uid && ctype_digit((string) $message->message_uid)) {
            return (int) $message->message_uid;
        }

        return null;
    }

    private function imapSearchCriteriaForRule(GedEmailRule $rule): string
    {
        if ($rule->max_age_days) {
            return 'SINCE '.CarbonImmutable::now()->subDays((int) $rule->max_age_days)->format('d-M-Y');
        }

        return 'UNSEEN';
    }

    private function openImapConnection(GedEmailAccount $account): array
    {
        $timeout = 15;
        $scheme = match ($account->encryption) {
            'ssl' => 'ssl',
            'tls' => 'tls',
            default => 'tcp',
        };

        $target = "{$scheme}://{$account->host}:{$account->port}";
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
                'SNI_enabled' => true,
                'peer_name' => $account->host,
            ],
        ]);

        $errno = 0;
        $errstr = '';
        $stream = @stream_socket_client($target, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);

        if (! is_resource($stream)) {
            return [
                'ok' => false,
                'message' => "Não foi possível conectar em {$account->host}:{$account->port}. {$errstr}",
            ];
        }

        stream_set_timeout($stream, $timeout);

        $greeting = $this->readImapResponse($stream, null);
        if ($greeting === '' || ! str_contains($greeting, '* OK')) {
            fclose($stream);

            return [
                'ok' => false,
                'message' => 'O servidor respondeu, mas não retornou saudação IMAP válida.',
                'detail' => trim($greeting),
            ];
        }

        if ($account->encryption === 'starttls') {
            $startTls = $this->imapCommand($stream, 'STARTTLS');
            if (! $startTls['ok']) {
                fclose($stream);

                return [
                    'ok' => false,
                    'message' => 'O servidor não aceitou STARTTLS.',
                    'detail' => trim($startTls['response']),
                ];
            }

            if (@stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
                fclose($stream);

                return [
                    'ok' => false,
                    'message' => 'Não foi possível iniciar criptografia STARTTLS.',
                ];
            }
        }

        $login = $this->imapCommand($stream, 'LOGIN '.$this->imapQuoted($account->username).' '.$this->imapQuoted($account->password ?? ''));
        if (! $login['ok']) {
            fclose($stream);

            return [
                'ok' => false,
                'message' => 'Conectou no servidor, mas o login IMAP não foi aceito.',
                'detail' => trim($login['response']),
            ];
        }

        return ['ok' => true, 'stream' => $stream];
    }

    private function imapCommand($stream, string $command): array
    {
        $tag = $this->imapTag();
        fwrite($stream, "{$tag} {$command}\r\n");
        $response = $this->readImapResponse($stream, $tag);

        return [
            'ok' => str_contains($response, "{$tag} OK"),
            'response' => $response,
            'tag' => $tag,
        ];
    }

    private function imapSearchIds($stream, string $criteria): array
    {
        $result = $this->imapCommand($stream, 'SEARCH '.$criteria);
        if (! $result['ok']) {
            return [];
        }

        if (! preg_match('/^\* SEARCH\s*(.*)$/m', $result['response'], $matches)) {
            return [];
        }

        return collect(preg_split('/\s+/', trim($matches[1])) ?: [])
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->values()
            ->all();
    }

    private function imapFetchRawMessage($stream, int $messageId): ?string
    {
        $tag = $this->imapTag();
        fwrite($stream, "{$tag} FETCH {$messageId} RFC822\r\n");

        $payload = '';
        $startedAt = microtime(true);

        while (! feof($stream) && microtime(true) - $startedAt < 30) {
            $line = fgets($stream, 4096);
            if ($line === false) {
                break;
            }

            if (preg_match('/\{(\d+)\}\r?\n$/', $line, $matches)) {
                $remaining = (int) $matches[1];
                while ($remaining > 0 && ! feof($stream)) {
                    $chunk = fread($stream, min(8192, $remaining));
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    $payload .= $chunk;
                    $remaining -= strlen($chunk);
                }
                continue;
            }

            if (str_starts_with($line, $tag.' ')) {
                break;
            }
        }

        return $payload !== '' ? $payload : null;
    }

    private function imapQuoted(string $value): string
    {
        return '"'.$this->quoteImapString($value).'"';
    }

    private function applyEmailPostAction($stream, int $messageId, GedEmailAccount $account, ?GedEmailRule $rule = null): void
    {
        $action = $rule?->post_action ?: $account->post_action;

        match ($action) {
            'mark_read' => $this->imapCommand($stream, "STORE {$messageId} +FLAGS (\\Seen)"),
            'delete' => $this->imapCommand($stream, "STORE {$messageId} +FLAGS (\\Deleted)"),
            'move' => $account->move_to
                ? $this->imapCommand($stream, "COPY {$messageId} ".$this->imapQuoted($account->move_to))
                : null,
            default => null,
        };
    }

    private function parseRawEmailMessage(string $raw): array
    {
        [$headerText, $body] = preg_split("/\r\n\r\n|\n\n/", $raw, 2) + ['', ''];
        $headers = $this->parseMimeHeaders($headerText);
        $parsed = $this->parseMimePart($headers, $body);

        return [
            'subject' => $this->decodeMimeHeader($headers['subject'][0] ?? ''),
            'from' => $this->decodeMimeHeader($headers['from'][0] ?? ''),
            'to' => $this->decodeMimeHeader($headers['to'][0] ?? ''),
            'message_id' => trim($headers['message-id'][0] ?? ''),
            'date' => $headers['date'][0] ?? null,
            'text' => trim($parsed['text'] ?? ''),
            'html' => trim($parsed['html'] ?? ''),
            'attachments' => $parsed['attachments'] ?? [],
        ];
    }

    private function parseMimePart(array $headers, string $body): array
    {
        [$contentType, $typeParams] = $this->parseHeaderValueWithParams($headers['content-type'][0] ?? 'text/plain');
        [$disposition, $dispositionParams] = $this->parseHeaderValueWithParams($headers['content-disposition'][0] ?? '');
        $encoding = strtolower(trim($headers['content-transfer-encoding'][0] ?? ''));
        $result = ['text' => '', 'html' => '', 'attachments' => []];

        if (str_starts_with($contentType, 'multipart/') && ! empty($typeParams['boundary'])) {
            foreach ($this->splitMimeMultipartBody($body, $typeParams['boundary']) as $part) {
                [$partHeadersText, $partBody] = preg_split("/\r\n\r\n|\n\n/", $part, 2) + ['', ''];
                $child = $this->parseMimePart($this->parseMimeHeaders($partHeadersText), $partBody);
                $result['text'] .= ($child['text'] ?? '');
                $result['html'] .= ($child['html'] ?? '');
                $result['attachments'] = array_merge($result['attachments'], $child['attachments'] ?? []);
            }

            return $result;
        }

        $decodedBody = $this->decodeMimeBody($body, $encoding);
        $filename = $this->decodeMimeHeader($dispositionParams['filename'] ?? $typeParams['name'] ?? $dispositionParams['filename*'] ?? $typeParams['name*'] ?? '');
        $isAttachment = $disposition === 'attachment' || $filename !== '';

        if ($isAttachment) {
            $result['attachments'][] = [
                'filename' => $filename ?: 'anexo-'.Str::random(8),
                'content_type' => $contentType ?: 'application/octet-stream',
                'content' => $decodedBody,
            ];

            return $result;
        }

        if ($contentType === 'text/html') {
            $result['html'] = $decodedBody;
        } else {
            $result['text'] = $decodedBody;
        }

        return $result;
    }

    private function parseMimeHeaders(string $headerText): array
    {
        $headers = [];
        $current = null;

        foreach (preg_split('/\r\n|\n|\r/', $headerText) ?: [] as $line) {
            if ($line === '') {
                continue;
            }

            if (preg_match('/^\s+/', $line) && $current) {
                $headers[$current][array_key_last($headers[$current])] .= ' '.trim($line);
                continue;
            }

            if (! str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $current = strtolower(trim($name));
            $headers[$current] ??= [];
            $headers[$current][] = trim($value);
        }

        return $headers;
    }

    private function parseHeaderValueWithParams(string $value): array
    {
        $parts = str_getcsv($value, ';', '"', '\\');
        $main = strtolower(trim(array_shift($parts) ?: ''));
        $params = [];
        $continuations = [];

        foreach ($parts as $part) {
            if (! str_contains($part, '=')) {
                continue;
            }

            [$key, $paramValue] = explode('=', $part, 2);
            $key = strtolower(trim($key));
            $paramValue = trim($paramValue, " \t\n\r\0\x0B\"");

            if (preg_match('/^(.+)\*(\d+)(\*)?$/', $key, $matches)) {
                $continuations[$matches[1]][(int) $matches[2]] = [
                    'value' => $paramValue,
                    'encoded' => ($matches[3] ?? '') === '*',
                ];
                continue;
            }

            $params[$key] = $this->decodeMimeParameterValue($paramValue, str_ends_with($key, '*'));
        }

        foreach ($continuations as $key => $chunks) {
            ksort($chunks);
            $combined = '';
            $encoded = false;

            foreach ($chunks as $chunk) {
                $combined .= $chunk['value'];
                $encoded = $encoded || $chunk['encoded'];
            }

            $params[$key] = $this->decodeMimeParameterValue($combined, $encoded);
        }

        return [$main, $params];
    }

    private function decodeMimeParameterValue(string $value, bool $encoded): string
    {
        if ($encoded && str_contains($value, "''")) {
            [, $value] = explode("''", $value, 2);
        }

        return $encoded ? rawurldecode($value) : $value;
    }

    private function splitMimeMultipartBody(string $body, string $boundary): array
    {
        $delimiter = '--'.$boundary;
        $parts = [];

        foreach (explode($delimiter, $body) as $part) {
            $part = trim($part, "\r\n");
            if ($part === '' || $part === '--') {
                continue;
            }

            $part = preg_replace('/\r?\n--$/', '', $part) ?? $part;
            $parts[] = $part;
        }

        return $parts;
    }

    private function decodeMimeBody(string $body, string $encoding): string
    {
        return match ($encoding) {
            'base64' => base64_decode(preg_replace('/\s+/', '', $body) ?? '', true) ?: '',
            'quoted-printable' => quoted_printable_decode($body),
            default => $body,
        };
    }

    private function decodeMimeHeader(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

        return trim($decoded ?: $value);
    }

    private function emailRuleMatches(GedEmailRule $rule, array $email, array $attachmentNames): bool
    {
        $checks = [
            [$rule->from_contains, $email['from'] ?? ''],
            [$rule->to_contains, $email['to'] ?? ''],
            [$rule->subject_contains, $email['subject'] ?? ''],
            [$rule->body_contains, trim(($email['text'] ?? '').' '.strip_tags($email['html'] ?? ''))],
        ];

        foreach ($checks as [$needle, $haystack]) {
            if ($needle && ! Str::contains(Str::lower($haystack), Str::lower($needle))) {
                return false;
            }
        }

        if ($rule->max_age_days && ! empty($email['date'])) {
            try {
                if (CarbonImmutable::parse($email['date'])->lt(now()->subDays((int) $rule->max_age_days))) {
                    return false;
                }
            } catch (\Throwable) {
                //
            }
        }

        if ($rule->attachment_name_contains) {
            return collect($attachmentNames)->contains(fn (string $filename) => Str::contains(Str::lower($filename), Str::lower($rule->attachment_name_contains)));
        }

        return true;
    }

    private function emailAttachmentMatchesRule(GedEmailRule $rule, string $filename): bool
    {
        if ($rule->attachment_name_contains && ! Str::contains(Str::lower($filename), Str::lower($rule->attachment_name_contains))) {
            return false;
        }

        if ($rule->include_attachment_patterns && ! $this->emailFilenameMatchesAnyPattern($filename, $rule->include_attachment_patterns)) {
            return false;
        }

        if ($rule->exclude_attachment_patterns && $this->emailFilenameMatchesAnyPattern($filename, $rule->exclude_attachment_patterns)) {
            return false;
        }

        return true;
    }

    private function emailFilenameMatchesAnyPattern(string $filename, string $patterns): bool
    {
        $filename = Str::lower($filename);

        return collect(explode(',', $patterns))
            ->map(fn (string $pattern) => trim($pattern))
            ->filter()
            ->contains(function (string $pattern) use ($filename): bool {
                $pattern = Str::lower($pattern);

                if (str_contains($pattern, '*')) {
                    $regex = '/^'.str_replace('\*', '.*', preg_quote($pattern, '/')).'$/i';

                    return (bool) preg_match($regex, $filename);
                }

                return Str::contains($filename, $pattern);
            });
    }

    private function recordProcessedEmail(Tenant $tenant, GedEmailAccount $account, GedEmailRule $rule, int $messageUid, array $email, array $data = []): void
    {
        $receivedAt = null;

        if (! empty($email['date'])) {
            try {
                $receivedAt = CarbonImmutable::parse($email['date']);
            } catch (\Throwable) {
                $receivedAt = null;
            }
        }

        $payload = [
            'tenant_id' => $tenant->id,
            'account_id' => $account->id,
            'rule_id' => $rule->id,
            'document_id' => $data['document_id'] ?? null,
            'message_uid' => (string) $messageUid,
            'message_id' => $email['message_id'] ?? null,
            'subject' => Str::limit((string) ($email['subject'] ?? 'Sem assunto'), 250, ''),
            'from' => Str::limit((string) ($email['from'] ?? ''), 250, ''),
            'received_at' => $receivedAt,
            'processed_at' => now(),
            'status' => $data['status'] ?? 'success',
            'error' => $data['error'] ?? null,
            'attachments_count' => (int) ($data['attachments_count'] ?? 0),
            'imported_count' => (int) ($data['imported_count'] ?? 0),
            'duplicate_count' => (int) ($data['duplicate_count'] ?? 0),
            'metadata' => array_merge([
                'to' => $email['to'] ?? null,
                'attachment_names' => collect($email['attachments'] ?? [])->pluck('filename')->filter()->values()->all(),
            ], $data['metadata'] ?? []),
        ];

        if (($data['status'] ?? null) === 'pending_triage') {
            GedEmailProcessedMessage::updateOrCreate([
                'tenant_id' => $tenant->id,
                'account_id' => $account->id,
                'rule_id' => $rule->id,
                'message_uid' => (string) $messageUid,
            ], $payload);

            return;
        }

        GedEmailProcessedMessage::create($payload);
    }

    private function isAcceptedGedDocumentType(?string $filename, ?string $mimeType, ?string $extension = null): bool
    {
        $extension = Str::lower($extension ?: pathinfo((string) $filename, PATHINFO_EXTENSION));
        $mimeType = Str::lower((string) $mimeType);

        if ($extension && in_array($extension, self::GED_ACCEPTED_EXTENSIONS, true)) {
            return true;
        }

        if ($mimeType && in_array($mimeType, self::GED_ACCEPTED_MIME_TYPES, true)) {
            return true;
        }

        return $mimeType !== '' && collect(self::GED_ACCEPTED_MIME_PREFIXES)
            ->contains(fn (string $prefix) => Str::startsWith($mimeType, $prefix));
    }

    private function isEmailAttachmentPdf(array $attachment): bool
    {
        $filename = $this->safeGedFilename($attachment['filename'] ?? 'anexo');
        $extension = Str::lower(pathinfo($filename, PATHINFO_EXTENSION));
        $mimeType = Str::lower((string) ($attachment['content_type'] ?? ''));

        return $extension === 'pdf' || $mimeType === 'application/pdf';
    }

    private function emailRuleAlreadyImportedMessage(Tenant $tenant, GedEmailAccount $account, GedEmailRule $rule, int $messageUid, array $email): bool
    {
        return GedEmailProcessedMessage::query()
            ->where('tenant_id', $tenant->id)
            ->where('account_id', $account->id)
            ->where('rule_id', $rule->id)
            ->where(function ($query) use ($messageUid, $email): void {
                $query->where('message_uid', (string) $messageUid);

                if (! empty($email['message_id'])) {
                    $query->orWhere('message_id', $email['message_id']);
                }
            })
            ->whereIn('status', ['success', 'pending_triage'])
            ->exists();
    }

    private function createGedDocumentFromEmailMessage(Tenant $tenant, GedEmailAccount $account, GedEmailRule $rule, array $email): GedDocument|string|null
    {
        $content = $this->renderEmailMessagePdfContent($email, $account);

        if ($content === '') {
            return null;
        }

        return $this->createGedDocumentFromEmailAttachment($tenant, $account, $rule, $email, [
            'filename' => $this->emailMessagePdfFilename($email),
            'content_type' => 'application/pdf',
            'content' => $content,
        ]);
    }

    private function renderEmailMessagePdfContent(array $email, GedEmailAccount $account): string
    {
        $plainBody = trim((string) ($email['text'] ?? ''));
        $htmlBody = trim((string) ($email['html'] ?? ''));

        if ($plainBody === '' && $htmlBody !== '') {
            $plainBody = trim(html_entity_decode(strip_tags($htmlBody), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        if ($plainBody === '') {
            $plainBody = 'E-mail sem corpo de mensagem.';
        }

        $messageBody = $htmlBody !== ''
            ? $this->sanitizeEmailHtmlForPdf($htmlBody)
            : '<div class="plain-body">'.nl2br(e($plainBody), false).'</div>';

        $receivedAt = '--';
        if (! empty($email['date'])) {
            try {
                $receivedAt = CarbonImmutable::parse($email['date'])->format('d/m/Y H:i');
            } catch (\Throwable) {
                $receivedAt = (string) $email['date'];
            }
        }

        $subject = (string) (($email['subject'] ?? '') ?: 'E-mail recebido');
        $from = (string) (($email['from'] ?? '') ?: '--');
        $to = (string) (($email['to'] ?? '') ?: '--');
        $messageId = (string) (($email['message_id'] ?? '') ?: '--');
        $fromInitial = Str::upper(Str::substr(trim($from) ?: 'E', 0, 1));

        $rows = [
            'Conta' => $account->email,
            'De' => $from,
            'Para' => $to,
            'Recebido em' => $receivedAt,
            'Message-ID' => $messageId,
        ];

        $metadataRows = collect($rows)->map(function ($value, $label) {
            return '<tr><th>'.e($label).'</th><td>'.e((string) $value).'</td></tr>';
        })->implode('');

        $attachments = collect($email['attachments'] ?? [])->pluck('filename')->filter()->values();
        $attachmentChips = $attachments
            ->map(fn ($filename) => '<span class="attachment-chip">'.e((string) $filename).'</span>')
            ->implode('');
        $attachmentsLabel = $attachments->count().' anexo'.($attachments->count() === 1 ? '' : 's');

        $html = '<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 24px 30px; }
        body { background: #f3f4f6; color: #111827; font-family: DejaVu Sans, sans-serif; font-size: 12px; line-height: 1.45; }
        .email-shell { background: #ffffff; border: 1px solid #d8dee8; border-radius: 10px; overflow: hidden; }
        .email-topbar { background: #0f172a; color: #ffffff; padding: 14px 18px; }
        .email-topbar .label { color: #cbd5e1; font-size: 10px; letter-spacing: .16em; text-transform: uppercase; }
        .email-topbar .title { font-size: 20px; font-weight: 700; margin-top: 4px; }
        .email-header { border-bottom: 1px solid #e5e7eb; padding: 16px 18px; }
        .sender-row { width: 100%; }
        .avatar-cell { width: 46px; vertical-align: top; }
        .avatar { background: #e0f2fe; border-radius: 999px; color: #0369a1; font-size: 18px; font-weight: 700; height: 38px; line-height: 38px; text-align: center; width: 38px; }
        .sender-name { font-size: 14px; font-weight: 700; margin-bottom: 2px; word-break: break-word; }
        .sender-sub { color: #64748b; font-size: 11px; word-break: break-word; }
        .date { color: #475569; font-size: 11px; text-align: right; white-space: nowrap; }
        .metadata { border-collapse: collapse; margin-top: 14px; width: 100%; }
        .metadata th { color: #64748b; font-size: 9px; letter-spacing: .12em; padding: 5px 10px 5px 0; text-align: left; text-transform: uppercase; width: 92px; }
        .metadata td { border-left: 2px solid #e5e7eb; padding: 5px 0 5px 10px; word-break: break-word; }
        .attachments { background: #f8fafc; border-bottom: 1px solid #e5e7eb; padding: 12px 18px; }
        .attachments-title { color: #475569; font-size: 10px; font-weight: 700; letter-spacing: .12em; margin-bottom: 8px; text-transform: uppercase; }
        .attachment-chip { background: #ffffff; border: 1px solid #cbd5e1; border-radius: 999px; display: inline-block; font-size: 10px; margin: 0 6px 6px 0; padding: 5px 9px; word-break: break-word; }
        .message { padding: 20px 18px 24px; }
        .message-card { border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px; }
        .message-card, .message-card p, .message-card div, .message-card span, .plain-body { color: #111827; font-family: DejaVu Sans, sans-serif; font-size: 12px; line-height: 1.55; }
        .plain-body { white-space: pre-wrap; word-break: break-word; }
        .message-card img { max-width: 100%; }
        .message-card blockquote { border-left: 3px solid #cbd5e1; color: #475569; margin: 10px 0; padding-left: 10px; }
        .footer { border-top: 1px solid #e5e7eb; color: #64748b; font-size: 10px; padding: 10px 18px; }
    </style>
</head>
<body>
    <div class="email-shell">
        <div class="email-topbar">
            <div class="label">E-mail recebido</div>
            <div class="title">'.e($subject).'</div>
        </div>
        <div class="email-header">
            <table class="sender-row">
                <tr>
                    <td class="avatar-cell"><div class="avatar">'.e($fromInitial).'</div></td>
                    <td>
                        <div class="sender-name">'.e($from).'</div>
                        <div class="sender-sub">para '.e($to).'</div>
                    </td>
                    <td class="date">'.e($receivedAt).'</td>
                </tr>
            </table>
            <table class="metadata">'.$metadataRows.'</table>
        </div>'.
        ($attachments->isNotEmpty() ? '<div class="attachments"><div class="attachments-title">'.$attachmentsLabel.'</div>'.$attachmentChips.'</div>' : '').
        '<div class="message">
            <div class="message-card">'.$messageBody.'</div>
        </div>
        <div class="footer">Documento gerado automaticamente a partir do e-mail recebido pela conta '.e($account->email).'.</div>
    </div>
</body>
</html>';

        return Pdf::loadHTML($html)->setPaper('a4')->output();
    }

    private function sanitizeEmailHtmlForPdf(string $html): string
    {
        $html = preg_replace('/<(script|style|iframe|object|embed|form|input|button|meta|link)\b[^>]*>.*?<\/\1>/is', '', $html) ?? $html;
        $html = preg_replace('/<(script|style|iframe|object|embed|form|input|button|meta|link)\b[^>]*\/?>/is', '', $html) ?? $html;
        $html = preg_replace('/\s+on[a-z]+\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/is', '', $html) ?? $html;
        $html = preg_replace('/\s+(href|src)\s*=\s*("|\')\s*javascript:.*?\2/is', '', $html) ?? $html;
        $html = preg_replace('/<img\b([^>]*)src=("|\')cid:[^"\']+\2([^>]*)>/is', '<span class="attachment-chip">Imagem embutida no e-mail</span>', $html) ?? $html;

        return trim($html) !== '' ? $html : '<div class="plain-body">E-mail sem corpo de mensagem.</div>';
    }

    private function emailMessagePdfFilename(array $email): string
    {
        $subject = Str::of((string) (($email['subject'] ?? '') ?: 'email-recebido'))
            ->ascii()
            ->replaceMatches('/[^A-Za-z0-9\-_ ]+/', '-')
            ->squish()
            ->limit(120, '')
            ->toString();

        return $this->safeGedFilename('Email - '.($subject ?: 'mensagem').'.pdf');
    }

    private function createGedDocumentAttachmentFromEmailAttachment(GedDocument $document, array $attachment, GedEmailAccount $account, GedEmailRule $rule, array $email): ?GedDocumentAttachment
    {
        $content = $attachment['content'] ?? '';
        if ($content === '' || strlen($content) > 100 * 1024 * 1024) {
            return null;
        }

        $checksum = hash('sha256', $content);

        if ($document->attachments()->where('checksum', $checksum)->exists()) {
            return null;
        }

        $originalFilename = $this->safeGedFilename($attachment['filename'] ?? 'anexo');
        $extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
        $mimeType = $attachment['content_type'] ?? null;

        if (! $mimeType && function_exists('finfo_buffer')) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->buffer($content) ?: null;
        }

        $storageDisk = (string) config('ged.document_disk', 'public');
        $directory = 'ged/'.$document->tenant_id.'/attachments/'.$document->id.'/'.now()->format('Y/m');
        $filename = Str::uuid().($extension ? ".{$extension}" : '');
        $path = $directory.'/'.$filename;

        Storage::disk($storageDisk)->put($path, $content);

        $created = GedDocumentAttachment::create([
            'document_id' => $document->id,
            'uploaded_by_id' => auth()->id(),
            'title' => pathinfo($originalFilename, PATHINFO_FILENAME) ?: $originalFilename,
            'original_filename' => $originalFilename,
            'mime_type' => $mimeType,
            'extension' => $extension,
            'size_bytes' => strlen($content),
            'checksum' => $checksum,
            'storage_disk' => $storageDisk,
            'path' => $path,
            'notes' => trim('Anexo recebido no mesmo e-mail do documento principal. Assunto: '.($email['subject'] ?? '')),
        ]);

        $this->logGedEvent(
            $document,
            'attachment.created',
            'Anexo importado por e-mail',
            'Arquivo extra do e-mail foi vinculado ao documento principal.',
            [
                'attachment_id' => $created->id,
                'account_id' => $account->id,
                'rule_id' => $rule->id,
                'original_filename' => $originalFilename,
                'size_bytes' => strlen($content),
            ],
        );

        $this->queueAttachmentOcrIfNeeded($created, 'Anexo PDF recebido por e-mail enviado para fila de OCR.');

        return $created;
    }

    private function createGedDocumentFromEmailAttachment(Tenant $tenant, GedEmailAccount $account, GedEmailRule $rule, array $email, array $attachment): GedDocument|string|null
    {
        $content = $attachment['content'] ?? '';
        if ($content === '' || strlen($content) > 50 * 1024 * 1024) {
            return null;
        }

        $checksum = hash('sha256', $content);
        $duplicate = GedDocument::withTrashed()
            ->where('tenant_id', $tenant->id)
            ->where('checksum', $checksum)
            ->first();

        $originalFilename = $this->safeGedFilename($attachment['filename'] ?? 'anexo');
        $extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
        $mimeType = $attachment['content_type'] ?? null;

        if (! $mimeType && function_exists('finfo_buffer')) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->buffer($content) ?: null;
        }

        if (! $this->isAcceptedGedDocumentType($originalFilename, $mimeType, $extension)) {
            return null;
        }

        if ($duplicate) {
            return 'duplicate';
        }

        $directory = 'ged/'.$tenant->id.'/'.now()->format('Y/m');
        $filename = Str::uuid().($extension ? ".{$extension}" : '');
        $storageDisk = (string) config('ged.document_disk', 'public');
        $path = $directory.'/'.$filename;
        Storage::disk($storageDisk)->put($path, $content);

        $tempPath = tempnam(sys_get_temp_dir(), 'ged-email-');
        if ($tempPath) {
            file_put_contents($tempPath, $content);
        }

        try {
            $sequence = $this->nextGedDocumentSequence($tenant, now()->toDateString());
            $tagIds = collect($rule->tag_ids ?? [])->map(fn ($id) => (int) $id)->filter()->unique()->values();
            $documentTitle = $rule->title_source === 'filename'
                ? (pathinfo($originalFilename, PATHINFO_FILENAME) ?: ($email['subject'] ?: 'Documento recebido por e-mail'))
                : (($email['subject'] ?? '') ?: pathinfo($originalFilename, PATHINFO_FILENAME) ?: 'Documento recebido por e-mail');

            $document = DB::transaction(function () use ($tenant, $account, $rule, $email, $content, $checksum, $duplicate, $originalFilename, $extension, $mimeType, $storageDisk, $path, $sequence, $tagIds, $tempPath, $documentTitle) {
                $document = GedDocument::create([
                    'tenant_id' => $tenant->id,
                    'contract_id' => $account->contract_id,
                    'obra_id' => null,
                    'document_type_id' => $rule->document_type_id,
                    'correspondent_id' => $rule->correspondent_id,
                    'uploaded_by_id' => auth()->id(),
                    'title' => Str::limit($documentTitle, 180, ''),
                    'document_number' => $sequence['document_number'],
                    'sequence_year' => $sequence['sequence_year'],
                    'sequence_number' => $sequence['sequence_number'],
                    'document_date' => now()->toDateString(),
                    'status' => config('ged.ocr.enabled', true) ? 'processing' : 'uploaded',
                    'description' => trim('Importado do e-mail '.$account->email.'. Assunto: '.($email['subject'] ?? '')),
                    'original_filename' => $originalFilename,
                    'mime_type' => $mimeType,
                    'extension' => $extension,
                    'size_bytes' => strlen($content),
                    'checksum' => $checksum,
                    'storage_disk' => $storageDisk,
                    'original_path' => $path,
                    'metadata' => [
                        'source' => 'email_import',
                        'original_md5' => md5($content),
                        'original_file_metadata' => $tempPath ? $this->extractOriginalDocumentMetadata($tempPath, $mimeType, $extension) : [],
                        'duplicate_of_id' => $duplicate?->id,
                        'email' => [
                            'account_id' => $account->id,
                            'rule_id' => $rule->id,
                            'from' => $email['from'] ?? null,
                            'subject' => $email['subject'] ?? null,
                            'message_id' => $email['message_id'] ?? null,
                        ],
                        'ocr' => [
                            'status' => config('ged.ocr.enabled', true) ? 'queued' : 'disabled',
                            'queued_at' => config('ged.ocr.enabled', true) ? now()->toDateTimeString() : null,
                            'engine' => 'ocrmypdf',
                            'queue' => (string) config('ged.ocr.queue', 'ged'),
                            'timeout_seconds' => (int) config('ged.ocr.timeout', 300),
                            'message' => config('ged.ocr.enabled', true)
                                ? 'Documento importado por e-mail e enviado para fila de OCR.'
                                : 'OCR automático desativado.',
                        ],
                    ],
                ]);

                GedDocumentVersion::create([
                    'document_id' => $document->id,
                    'uploaded_by_id' => auth()->id(),
                    'version_number' => 1,
                    'original_filename' => $originalFilename,
                    'mime_type' => $mimeType,
                    'size_bytes' => strlen($content),
                    'checksum' => $checksum,
                    'storage_disk' => $storageDisk,
                    'path' => $path,
                    'notes' => 'Versão inicial importada por e-mail.',
                ]);

                if ($tagIds->isNotEmpty()) {
                    $document->tags()->sync($tagIds);
                }

                $this->logGedEvent(
                    $document,
                    'document.created',
                    'Documento importado por e-mail',
                    'Anexo recebido por e-mail e importado para o GED.',
                    [
                        'account_id' => $account->id,
                        'rule_id' => $rule->id,
                        'original_filename' => $originalFilename,
                        'size_bytes' => strlen($content),
                        'checksum' => $checksum,
                        'duplicate_of_id' => $duplicate?->id,
                        'tag_ids' => $tagIds->all(),
                    ],
                );

                if (config('ged.ocr.enabled', true)) {
                    $this->logGedEvent(
                        $document,
                        'ocr.queued',
                        'OCR colocado na fila',
                        'Documento importado por e-mail e enviado automaticamente para processamento OCR.',
                        ['engine' => 'ocrmypdf'],
                    );
                }

                return $document;
            });
        } finally {
            if ($tempPath && file_exists($tempPath)) {
                @unlink($tempPath);
            }
        }

        if (config('ged.ocr.enabled', true)) {
            $this->dispatchOcrJob($document);
        }

        return $document;
    }

    private function safeGedFilename(string $filename): string
    {
        $filename = trim($this->decodeMimeHeader($filename));
        $filename = basename(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $filename));
        $filename = Str::of($filename ?: 'anexo')
            ->replace([':', '*', '?', '"', '<', '>', '|'], '-')
            ->squish()
            ->toString();

        return $filename ?: 'anexo';
    }

    private function testImapConnection(string $host, int $port, string $encryption, string $username, string $password): array
    {
        $timeout = 12;
        $scheme = match ($encryption) {
            'ssl' => 'ssl',
            'tls' => 'tls',
            default => 'tcp',
        };

        $target = "{$scheme}://{$host}:{$port}";
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
                'SNI_enabled' => true,
                'peer_name' => $host,
            ],
        ]);

        $errno = 0;
        $errstr = '';
        $stream = @stream_socket_client($target, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);

        if (! is_resource($stream)) {
            return [
                'ok' => false,
                'message' => "Não foi possível conectar em {$host}:{$port}. {$errstr}",
            ];
        }

        stream_set_timeout($stream, $timeout);

        $greeting = $this->readImapResponse($stream, null);
        if ($greeting === '' || ! str_contains($greeting, '* OK')) {
            fclose($stream);

            return [
                'ok' => false,
                'message' => 'O servidor respondeu, mas não retornou saudação IMAP válida.',
                'detail' => trim($greeting),
            ];
        }

        if ($encryption === 'starttls') {
            $tag = $this->imapTag();
            fwrite($stream, "{$tag} STARTTLS\r\n");
            $startTlsResponse = $this->readImapResponse($stream, $tag);

            if (! str_contains($startTlsResponse, "{$tag} OK")) {
                fclose($stream);

                return [
                    'ok' => false,
                    'message' => 'O servidor não aceitou STARTTLS.',
                    'detail' => trim($startTlsResponse),
                ];
            }

            $cryptoEnabled = @stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($cryptoEnabled !== true) {
                fclose($stream);

                return [
                    'ok' => false,
                    'message' => 'Não foi possível iniciar criptografia STARTTLS.',
                ];
            }
        }

        $tag = $this->imapTag();
        $login = sprintf(
            "%s LOGIN \"%s\" \"%s\"\r\n",
            $tag,
            $this->quoteImapString($username),
            $this->quoteImapString($password),
        );

        fwrite($stream, $login);
        $loginResponse = $this->readImapResponse($stream, $tag);

        fwrite($stream, $this->imapTag()." LOGOUT\r\n");
        fclose($stream);

        if (str_contains($loginResponse, "{$tag} OK")) {
            return [
                'ok' => true,
                'message' => 'Conexão IMAP testada com sucesso.',
            ];
        }

        $message = str_contains(Str::lower($loginResponse), 'authentication')
            || str_contains(Str::lower($loginResponse), 'invalid')
            || str_contains(Str::lower($loginResponse), 'failure')
            ? 'Conectou no servidor, mas usuário ou senha foram recusados.'
            : 'Conectou no servidor, mas o login IMAP não foi aceito.';

        return [
            'ok' => false,
            'message' => $message,
            'detail' => trim($loginResponse),
        ];
    }

    private function readImapResponse($stream, ?string $tag): string
    {
        $buffer = '';
        $startedAt = microtime(true);

        while (! feof($stream) && microtime(true) - $startedAt < 12) {
            $line = fgets($stream, 4096);

            if ($line === false) {
                break;
            }

            $buffer .= $line;

            if ($tag === null && str_starts_with($line, '* ')) {
                break;
            }

            if ($tag !== null && str_starts_with($line, $tag.' ')) {
                break;
            }
        }

        return $buffer;
    }

    private function quoteImapString(string $value): string
    {
        $value = str_replace(["\r", "\n"], '', $value);

        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }

    private function imapTag(): string
    {
        return 'A'.Str::upper(Str::random(6));
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

