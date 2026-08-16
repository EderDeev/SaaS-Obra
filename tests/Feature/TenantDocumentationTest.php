<?php

namespace Tests\Feature;

use App\Http\Controllers\Tenant\GedController;
use App\Jobs\ProcessGedDocumentAttachmentOcrJob;
use App\Jobs\ProcessGedDocumentOcrJob;
use App\Models\Contract;
use App\Models\ContractParticipant;
use App\Models\Empresa;
use App\Models\GedDocument;
use App\Models\GedDocumentAttachment;
use App\Models\GedEmailAccount;
use App\Models\GedEmailRule;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\TipoEmpresa;
use App\Models\User;
use App\Services\GedOcrService;
use App\Support\DocumentationPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class TenantDocumentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_macro_permissions_are_split_and_scoped_by_contract(): void
    {
        [$tenant, $user, $contractA, $contractB] = $this->documentationContext([
            DocumentationPermissions::VIEW,
            DocumentationPermissions::UPLOAD,
        ]);

        ContractParticipant::query()
            ->where('contract_id', $contractB->id)
            ->where('user_id', $user->id)
            ->update(['documentation_permissions' => [DocumentationPermissions::VIEW]]);

        $this->actingAs($user)
            ->get(route('tenant.ged.index', $tenant))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('tenant.ged.settings', $tenant))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('tenant.ged.email', $tenant))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('tenant.ged.trash', $tenant))
            ->assertForbidden();

        Storage::fake('public');
        Queue::fake();

        $this->actingAs($user)
            ->post(route('tenant.ged.store', $tenant), [
                'contract_id' => $contractA->id,
                'file' => $this->pdfUpload('permitido.pdf', 'permitido'),
            ])
            ->assertRedirect();

        $this->actingAs($user)
            ->post(route('tenant.ged.store', $tenant), [
                'contract_id' => $contractB->id,
                'file' => $this->pdfUpload('bloqueado.pdf', 'bloqueado'),
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('ged_documents', 1);
        Queue::assertPushed(ProcessGedDocumentOcrJob::class, 1);
    }

    public function test_bulk_download_honors_archive_original_and_formatted_filename_options(): void
    {
        Storage::fake('public');
        [$tenant, $user, $contract] = $this->documentationContext(DocumentationPermissions::all(), oneContract: true);
        $document = $this->document($tenant, $contract, $user);
        $archivePath = "ged/{$tenant->id}/archive/documento-1.pdf";

        $document->update(['archive_path' => $archivePath]);
        Storage::disk('public')->put($document->original_path, 'conteudo-original');
        Storage::disk('public')->put($archivePath, 'conteudo-arquivado');

        $archiveOnly = $this->actingAs($user)->get(route('tenant.ged.bulk-download', $tenant).'?'.http_build_query([
            'ids' => (string) $document->id,
            'include_archive' => 1,
            'include_original' => 0,
            'use_formatted_name' => 0,
        ]));
        $archiveOnly->assertOk()->assertHeader('content-type', 'application/zip');
        $this->assertSame(['documento-1.pdf' => 'conteudo-arquivado'], $this->zipEntries($archiveOnly));

        $originalOnly = $this->actingAs($user)->get(route('tenant.ged.bulk-download', $tenant).'?'.http_build_query([
            'ids' => (string) $document->id,
            'include_archive' => 0,
            'include_original' => 1,
            'use_formatted_name' => 0,
        ]));
        $originalOnly->assertOk();
        $this->assertSame(['documento-1.pdf' => 'conteudo-original'], $this->zipEntries($originalOnly));

        $bothFormatted = $this->actingAs($user)->get(route('tenant.ged.bulk-download', $tenant).'?'.http_build_query([
            'ids' => (string) $document->id,
            'include_archive' => 1,
            'include_original' => 1,
            'use_formatted_name' => 1,
        ]));
        $bothFormatted->assertOk();
        $this->assertSame([
            '001-2026 - Documento 1 - arquivado.pdf' => 'conteudo-arquivado',
            '001-2026 - Documento 1 - original.pdf' => 'conteudo-original',
        ], $this->zipEntries($bothFormatted));

        $this->actingAs($user)->get(route('tenant.ged.bulk-download', $tenant).'?'.http_build_query([
            'ids' => (string) $document->id,
            'include_archive' => 0,
            'include_original' => 0,
        ]))->assertUnprocessable();
    }

    public function test_document_acl_controls_direct_read_and_edit_after_macro_permission(): void
    {
        [$tenant, $owner, $contract] = $this->documentationContext(DocumentationPermissions::all(), oneContract: true);
        $viewer = $this->addUser($tenant, $contract, [DocumentationPermissions::VIEW, DocumentationPermissions::EDIT]);
        $outsider = $this->addUser($tenant, $contract, [DocumentationPermissions::VIEW, DocumentationPermissions::EDIT]);
        $document = $this->document($tenant, $contract, $owner, 1, [
            'permissions' => [
                'owner_user_id' => $owner->id,
                'view' => ['user_ids' => [$viewer->id], 'empresa_ids' => []],
                'edit' => ['user_ids' => [], 'empresa_ids' => []],
            ],
        ]);

        $this->actingAs($viewer)
            ->get(route('tenant.ged.details', [$tenant, $document]))
            ->assertOk();

        $this->actingAs($viewer)
            ->put(route('tenant.ged.update', [$tenant, $document]), [
                'title' => 'Alteracao negada',
                'contract_id' => $contract->id,
            ])
            ->assertForbidden();

        $this->actingAs($outsider)
            ->get(route('tenant.ged.details', [$tenant, $document]))
            ->assertForbidden();

        $this->assertSame(
            [$document->id],
            DocumentationPermissions::scopeReadableDocuments(GedDocument::query(), $viewer, $tenant)
                ->pluck('id')
                ->all(),
        );
        $this->assertSame(
            [],
            DocumentationPermissions::scopeReadableDocuments(GedDocument::query(), $outsider, $tenant)
                ->pluck('id')
                ->all(),
        );

        $document->update([
            'metadata' => [
                'permissions' => [
                    'owner_user_id' => $owner->id,
                    'view' => ['user_ids' => [$viewer->id], 'empresa_ids' => []],
                    'edit' => ['user_ids' => [$viewer->id], 'empresa_ids' => []],
                ],
            ],
        ]);

        $this->actingAs($viewer)
            ->put(route('tenant.ged.update', [$tenant, $document]), [
                'title' => 'Alteracao permitida',
                'contract_id' => $contract->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('ged_documents', [
            'id' => $document->id,
            'title' => 'Alteracao permitida',
        ]);
    }

    public function test_document_company_groups_control_view_and_edit_access(): void
    {
        [$tenant, $owner, $contract] = $this->documentationContext(DocumentationPermissions::all(), oneContract: true);
        $companies = collect(['cliente', 'construtora', 'gerenciadora'])
            ->mapWithKeys(function (string $type) use ($tenant, $contract): array {
                $company = Empresa::create([
                    'tenant_id' => $tenant->id,
                    'contract_id' => $contract->id,
                    'tipo_empresa_id' => TipoEmpresa::query()->where('nome', $type)->firstOrFail()->id,
                    'nome' => ucfirst($type).' Documentacao',
                    'sigla' => strtoupper(substr($type, 0, 3)),
                    'cnpj' => fake()->unique()->numerify('##.###.###/####-##'),
                ]);

                return [$type => $company];
            });

        $clientViewer = $this->addUser($tenant, $contract, [DocumentationPermissions::VIEW, DocumentationPermissions::EDIT]);
        $constructorEditor = $this->addUser($tenant, $contract, [DocumentationPermissions::VIEW, DocumentationPermissions::EDIT]);
        $managerOutsider = $this->addUser($tenant, $contract, [DocumentationPermissions::VIEW, DocumentationPermissions::EDIT]);
        $clientWithoutMacro = $this->addUser($tenant, $contract, []);

        foreach ([
            $clientViewer->id => $companies['cliente']->id,
            $constructorEditor->id => $companies['construtora']->id,
            $managerOutsider->id => $companies['gerenciadora']->id,
            $clientWithoutMacro->id => $companies['cliente']->id,
        ] as $userId => $companyId) {
            TenantUser::query()
                ->where('tenant_id', $tenant->id)
                ->where('user_id', $userId)
                ->update(['empresa_id' => $companyId]);
        }

        $document = $this->document($tenant, $contract, $owner);

        $this->actingAs($owner)
            ->patch(route('tenant.ged.permissions.update', [$tenant, $document]), [
                'owner_user_id' => $owner->id,
                'view_user_ids' => [],
                'view_empresa_ids' => [$companies['cliente']->id],
                'edit_user_ids' => [],
                'edit_empresa_ids' => [$companies['construtora']->id],
            ])
            ->assertRedirect();

        $this->assertSame([$companies['cliente']->id], data_get($document->refresh()->metadata, 'permissions.view.empresa_ids'));
        $this->assertSame([$companies['construtora']->id], data_get($document->metadata, 'permissions.edit.empresa_ids'));

        $this->actingAs($clientViewer)
            ->get(route('tenant.ged.details', [$tenant, $document]))
            ->assertOk();
        $this->actingAs($clientViewer)
            ->put(route('tenant.ged.update', [$tenant, $document]), [
                'title' => 'Cliente nao pode editar',
                'contract_id' => $contract->id,
            ])
            ->assertForbidden();

        $this->actingAs($constructorEditor)
            ->get(route('tenant.ged.details', [$tenant, $document]))
            ->assertOk();
        $this->actingAs($constructorEditor)
            ->put(route('tenant.ged.update', [$tenant, $document]), [
                'title' => 'Construtora pode editar',
                'contract_id' => $contract->id,
            ])
            ->assertRedirect();

        $this->actingAs($managerOutsider)
            ->get(route('tenant.ged.details', [$tenant, $document]))
            ->assertForbidden();
        $this->actingAs($clientWithoutMacro)
            ->get(route('tenant.ged.details', [$tenant, $document]))
            ->assertForbidden();

        $this->assertSame(
            [$document->id],
            DocumentationPermissions::scopeReadableDocuments(GedDocument::query(), $clientViewer, $tenant)
                ->pluck('id')
                ->all(),
        );
        $this->assertSame(
            [$document->id],
            DocumentationPermissions::scopeReadableDocuments(GedDocument::query(), $constructorEditor, $tenant)
                ->pluck('id')
                ->all(),
        );
        $this->assertSame(
            [],
            DocumentationPermissions::scopeReadableDocuments(GedDocument::query(), $managerOutsider, $tenant)
                ->pluck('id')
                ->all(),
        );
    }

    public function test_unrestricted_document_is_visible_to_every_user_with_macro_view_permission(): void
    {
        [$tenant, $owner, $contract] = $this->documentationContext(DocumentationPermissions::all(), oneContract: true);
        $viewer = $this->addUser($tenant, $contract, [DocumentationPermissions::VIEW]);
        $withoutMacro = $this->addUser($tenant, $contract, []);
        $document = $this->document($tenant, $contract, $owner);

        $this->actingAs($viewer)
            ->get(route('tenant.ged.details', [$tenant, $document]))
            ->assertOk();
        $this->actingAs($withoutMacro)
            ->get(route('tenant.ged.details', [$tenant, $document]))
            ->assertForbidden();
    }

    public function test_mass_upload_assigns_unique_sequences_and_queues_every_pdf(): void
    {
        [$tenant, $user, $contract] = $this->documentationContext([
            DocumentationPermissions::VIEW,
            DocumentationPermissions::UPLOAD,
        ], oneContract: true);

        Storage::fake('public');
        Queue::fake();

        foreach (range(1, 20) as $index) {
            $this->actingAs($user)
                ->post(route('tenant.ged.store', $tenant), [
                    'contract_id' => $contract->id,
                    'title' => "Documento {$index}",
                    'file' => $this->pdfUpload("documento-{$index}.pdf", "conteudo-{$index}"),
                ])
                ->assertRedirect()
                ->assertSessionHasNoErrors();
        }

        $this->assertDatabaseCount('ged_documents', 20);
        $this->assertSame(
            range(1, 20),
            GedDocument::query()->orderBy('sequence_number')->pluck('sequence_number')->all(),
        );
        $this->assertSame(
            20,
            GedDocument::query()->pluck('document_number')->unique()->count(),
        );
        Queue::assertPushed(ProcessGedDocumentOcrJob::class, 20);

        $this->actingAs($user)
            ->post(route('tenant.ged.store', $tenant), [
                'contract_id' => $contract->id,
                'file' => UploadedFile::fake()->createWithContent('nao-permitido.docx', 'docx'),
            ])
            ->assertSessionHasErrors('file');

        $this->assertDatabaseCount('ged_documents', 20);
    }

    public function test_multiple_mixed_attachments_queue_ocr_only_for_pdfs(): void
    {
        [$tenant, $user, $contract] = $this->documentationContext([
            DocumentationPermissions::VIEW,
            DocumentationPermissions::EDIT,
        ], oneContract: true);
        $document = $this->document($tenant, $contract, $user);

        Storage::fake('public');
        Queue::fake();

        $this->actingAs($user)
            ->post(route('tenant.ged.attachments.store', [$tenant, $document]), [
                'files' => [
                    $this->pdfUpload('apoio-1.pdf', 'pdf-1'),
                    $this->pdfUpload('apoio-2.pdf', 'pdf-2'),
                    UploadedFile::fake()->createWithContent('planilha.xlsx', 'planilha'),
                    UploadedFile::fake()->createWithContent('evidencia.zip', 'arquivo-zip'),
                    UploadedFile::fake()->createWithContent('video.mp4', 'arquivo-video'),
                ],
                'notes' => 'Lote de anexos do teste',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(5, $document->attachments()->count());
        $this->assertSame(2, $document->attachments()->where('ocr_status', 'queued')->count());
        Queue::assertPushed(ProcessGedDocumentAttachmentOcrJob::class, 2);
    }

    public function test_ocr_permission_can_queue_processing_without_edit_permission(): void
    {
        [$tenant, $user, $contract] = $this->documentationContext([
            DocumentationPermissions::VIEW,
            DocumentationPermissions::OCR,
        ], oneContract: true);
        $document = $this->document($tenant, $contract, $user);

        Queue::fake();

        $this->actingAs($user)
            ->post(route('tenant.ged.ocr', [$tenant, $document]))
            ->assertRedirect();

        $this->actingAs($user)
            ->put(route('tenant.ged.update', [$tenant, $document]), ['title' => 'Nao pode editar'])
            ->assertForbidden();

        $this->assertSame('queued', data_get($document->fresh()->metadata, 'ocr.status'));
        Queue::assertPushed(ProcessGedDocumentOcrJob::class, 1);
    }

    public function test_ocr_job_persists_text_archive_page_count_and_events(): void
    {
        [$tenant, $user, $contract] = $this->documentationContext(DocumentationPermissions::all(), oneContract: true);
        $document = $this->document($tenant, $contract, $user);
        $ocr = Mockery::mock(GedOcrService::class);
        $ocr->shouldReceive('process')->once()->andReturn([
            'text' => 'Texto reconhecido em massa pelo OCR.',
            'archive_path' => 'ged/archive/documento-ocr.pdf',
            'page_count' => 7,
            'engine' => 'ocrmypdf',
            'message' => 'OCR concluido no teste.',
        ]);

        (new ProcessGedDocumentOcrJob($document->id))->handle($ocr);

        $document->refresh();
        $this->assertSame('indexed', $document->status);
        $this->assertSame('Texto reconhecido em massa pelo OCR.', $document->extracted_text);
        $this->assertSame(7, $document->page_count);
        $this->assertSame('done', data_get($document->metadata, 'ocr.status'));
        $this->assertDatabaseHas('ged_document_events', [
            'document_id' => $document->id,
            'event_type' => 'ocr.completed',
        ]);
    }

    public function test_email_import_creates_main_pdf_support_attachments_and_blocks_duplicates(): void
    {
        [$tenant, $user, $contract] = $this->documentationContext([
            DocumentationPermissions::VIEW,
            DocumentationPermissions::EMAIL,
        ], oneContract: true);
        $account = GedEmailAccount::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'name' => 'Conta automatica',
            'email' => 'documentos@example.com',
            'host' => 'imap.example.com',
            'port' => 993,
            'encryption' => 'ssl',
            'username' => 'documentos@example.com',
            'password' => 'segredo-do-teste',
            'mailbox' => 'INBOX',
            'post_action' => 'mark_read',
            'is_active' => true,
        ]);
        $rule = GedEmailRule::create([
            'tenant_id' => $tenant->id,
            'account_id' => $account->id,
            'contract_id' => $contract->id,
            'name' => 'Importacao automatica',
            'consume_scope' => 'attachments',
            'consume_attachments' => true,
            'title_source' => 'subject',
            'priority' => 10,
            'is_active' => true,
        ]);
        $email = [
            'subject' => 'Carta de encaminhamento 001',
            'from' => 'origem@example.com',
            'to' => 'documentos@example.com',
            'message_id' => '<massa-001@example.com>',
        ];

        Storage::fake('public');
        Queue::fake();
        $this->actingAs($user);
        $controller = app(GedController::class);
        $createDocument = new ReflectionMethod($controller, 'createGedDocumentFromEmailAttachment');
        $createAttachment = new ReflectionMethod($controller, 'createGedDocumentAttachmentFromEmailAttachment');
        $mainPdf = [
            'filename' => 'carta-principal.pdf',
            'content_type' => 'application/pdf',
            'content' => $this->pdfContent('principal-email'),
        ];

        $document = $createDocument->invoke($controller, $tenant, $account, $rule, $email, $mainPdf);
        $this->assertInstanceOf(GedDocument::class, $document);

        foreach ([
            ['filename' => 'memoria.pdf', 'content_type' => 'application/pdf', 'content' => $this->pdfContent('memoria-email')],
            ['filename' => 'planilha.xlsx', 'content_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'content' => 'planilha-email'],
            ['filename' => 'evidencias.zip', 'content_type' => 'application/zip', 'content' => 'zip-email'],
        ] as $attachment) {
            $createAttachment->invoke($controller, $document, $attachment, $account, $rule, $email);
        }

        $duplicate = $createDocument->invoke($controller, $tenant, $account, $rule, $email, $mainPdf);

        $this->assertSame('duplicate', $duplicate);
        $this->assertSame('email_import', data_get($document->metadata, 'source'));
        $this->assertSame(3, $document->attachments()->count());
        $this->assertSame(1, $document->attachments()->where('ocr_status', 'queued')->count());
        Queue::assertPushed(ProcessGedDocumentOcrJob::class, 1);
        Queue::assertPushed(ProcessGedDocumentAttachmentOcrJob::class, 1);
    }

    public function test_email_body_is_rendered_as_pdf_and_imported_as_the_main_document(): void
    {
        [$tenant, $user, $contract] = $this->documentationContext([
            DocumentationPermissions::VIEW,
            DocumentationPermissions::EMAIL,
        ], oneContract: true);
        $account = GedEmailAccount::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'name' => 'Caixa do contrato',
            'email' => 'contrato@example.com',
            'host' => 'imap.example.com',
            'port' => 993,
            'encryption' => 'ssl',
            'username' => 'contrato@example.com',
            'password' => 'segredo-do-teste',
            'mailbox' => 'INBOX',
            'post_action' => 'mark_read',
            'is_active' => true,
        ]);
        $rule = GedEmailRule::create([
            'tenant_id' => $tenant->id,
            'account_id' => $account->id,
            'contract_id' => $contract->id,
            'name' => 'Email completo em PDF',
            'consume_scope' => 'everything',
            'consume_attachments' => true,
            'title_source' => 'subject',
            'priority' => 10,
            'is_active' => true,
        ]);
        $email = [
            'subject' => 'Comunicado de obra',
            'from' => 'fiscal@example.com',
            'to' => 'contrato@example.com',
            'date' => '2026-08-05 10:30:00',
            'message_id' => '<email-pdf-001@example.com>',
            'text' => 'Segue comunicado e os arquivos complementares.',
            'html' => '<p>Segue <strong>comunicado</strong> e os arquivos complementares.</p>',
            'attachments' => [
                ['filename' => 'apoio.pdf'],
                ['filename' => 'planilha.xlsx'],
            ],
        ];

        Storage::fake('public');
        Queue::fake();
        $this->actingAs($user);
        $controller = app(GedController::class);
        $method = new ReflectionMethod($controller, 'createGedDocumentFromEmailMessage');
        $document = $method->invoke($controller, $tenant, $account, $rule, $email);

        $this->assertInstanceOf(GedDocument::class, $document);
        $this->assertSame('Comunicado de obra', $document->title);
        $this->assertSame('pdf', $document->extension);
        $this->assertSame('application/pdf', $document->mime_type);
        $this->assertStringStartsWith(
            '%PDF-',
            Storage::disk($document->storage_disk)->get($document->original_path),
        );
        Queue::assertPushed(ProcessGedDocumentOcrJob::class, 1);
    }

    public function test_trash_permission_is_independent_from_delete_permission(): void
    {
        [$tenant, $user, $contract] = $this->documentationContext([
            DocumentationPermissions::VIEW,
            DocumentationPermissions::DELETE,
        ], oneContract: true);
        $document = $this->document($tenant, $contract, $user);

        $this->actingAs($user)
            ->delete(route('tenant.ged.destroy', [$tenant, $document]))
            ->assertRedirect();

        $this->assertSoftDeleted('ged_documents', ['id' => $document->id]);

        $this->actingAs($user)
            ->get(route('tenant.ged.trash', $tenant))
            ->assertForbidden();

        ContractParticipant::query()
            ->where('contract_id', $contract->id)
            ->where('user_id', $user->id)
            ->update(['documentation_permissions' => [
                DocumentationPermissions::VIEW,
                DocumentationPermissions::TRASH,
            ]]);

        $this->actingAs($user)
            ->post(route('tenant.ged.trash.action', $tenant), [
                'action' => 'restore',
                'document_ids' => [$document->id],
            ])
            ->assertRedirect();

        $this->assertNotSoftDeleted('ged_documents', ['id' => $document->id]);
    }

    public function test_tenant_admin_can_assign_documentation_macros_to_a_contract_participant(): void
    {
        [$tenant, $member, $contract] = $this->documentationContext([
            DocumentationPermissions::VIEW,
        ], oneContract: true);
        $admin = User::factory()->create();
        $tenant->memberships()->create([
            'user_id' => $admin->id,
            'role' => 'tenant_admin',
            'status' => 'active',
            'documentation_permissions' => DocumentationPermissions::all(),
        ]);

        $this->actingAs($admin)
            ->patch(route('tenant.permissions.update', $tenant), [
                'user_id' => $member->id,
                'contract_id' => $contract->id,
                'documentation_permissions' => [
                    DocumentationPermissions::UPLOAD,
                    DocumentationPermissions::OCR,
                    DocumentationPermissions::EMAIL,
                ],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $participant = ContractParticipant::query()
            ->where('contract_id', $contract->id)
            ->where('user_id', $member->id)
            ->firstOrFail();

        $this->assertSame([
            DocumentationPermissions::VIEW,
            DocumentationPermissions::UPLOAD,
            DocumentationPermissions::OCR,
            DocumentationPermissions::EMAIL,
        ], $participant->documentation_permissions);
    }

    private function documentationContext(array $permissions, bool $oneContract = false): array
    {
        $tenant = Tenant::create([
            'slug' => 'documentacao-'.str()->lower(str()->random(8)),
            'name' => 'Tenant Documentacao',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        $user = User::factory()->create();
        $tenant->memberships()->create([
            'user_id' => $user->id,
            'role' => 'engineer',
            'status' => 'active',
            'documentation_permissions' => $permissions,
        ]);
        $contractA = $this->contract($tenant, 'CT-DOC-A');
        $this->participant($tenant, $contractA, $user, $permissions);

        if ($oneContract) {
            return [$tenant, $user, $contractA];
        }

        $contractB = $this->contract($tenant, 'CT-DOC-B');
        $this->participant($tenant, $contractB, $user, $permissions);

        return [$tenant, $user, $contractA, $contractB];
    }

    private function addUser(Tenant $tenant, Contract $contract, array $permissions): User
    {
        $user = User::factory()->create();
        $tenant->memberships()->create([
            'user_id' => $user->id,
            'role' => 'engineer',
            'status' => 'active',
            'documentation_permissions' => $permissions,
        ]);
        $this->participant($tenant, $contract, $user, $permissions);

        return $user;
    }

    private function participant(Tenant $tenant, Contract $contract, User $user, array $permissions): ContractParticipant
    {
        return ContractParticipant::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'user_id' => $user->id,
            'side' => 'manager',
            'role' => 'team_member',
            'status' => 'active',
            'documentation_permissions' => $permissions,
        ]);
    }

    private function contract(Tenant $tenant, string $code): Contract
    {
        return Contract::create([
            'tenant_id' => $tenant->id,
            'code' => $code,
            'name' => "Contrato {$code}",
            'status' => 'active',
        ]);
    }

    private function document(Tenant $tenant, Contract $contract, User $uploader, int $sequence = 1, array $metadata = []): GedDocument
    {
        return GedDocument::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'uploaded_by_id' => $uploader->id,
            'title' => "Documento {$sequence}",
            'document_number' => str_pad((string) $sequence, 3, '0', STR_PAD_LEFT).'/2026',
            'sequence_year' => 2026,
            'sequence_number' => $sequence,
            'status' => 'uploaded',
            'original_filename' => "documento-{$sequence}.pdf",
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => 100,
            'checksum' => hash('sha256', $tenant->id.'-'.$contract->id.'-'.$sequence.'-'.str()->random(8)),
            'storage_disk' => 'public',
            'original_path' => "ged/{$tenant->id}/documento-{$sequence}.pdf",
            'metadata' => $metadata,
        ]);
    }

    private function pdfUpload(string $name, string $marker): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $this->pdfContent($marker));
    }

    private function zipEntries($response): array
    {
        $path = tempnam(sys_get_temp_dir(), 'ged-test-zip-');
        file_put_contents($path, $response->streamedContent());
        $zip = new \ZipArchive();

        try {
            $this->assertTrue($zip->open($path));
            $entries = [];

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);
                $entries[$name] = $zip->getFromIndex($index);
            }

            return $entries;
        } finally {
            $zip->close();
            @unlink($path);
        }
    }

    private function pdfContent(string $marker): string
    {
        return "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n% {$marker}\n%%EOF";
    }
}
