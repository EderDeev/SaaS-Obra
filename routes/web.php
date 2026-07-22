<?php

use App\Http\Controllers\Platform\ApsUsageController as PlatformApsUsageController;
use App\Http\Controllers\Platform\DashboardController as PlatformDashboardController;
use App\Http\Controllers\Platform\TenantController as PlatformTenantController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Tenant\ActivityController;
use App\Http\Controllers\Tenant\BoletimMedicaoController;
use App\Http\Controllers\Tenant\ContractController;
use App\Http\Controllers\Tenant\ContractAdditiveController;
use App\Http\Controllers\Tenant\ContractParticipantController;
use App\Http\Controllers\Tenant\DashboardController as TenantDashboardController;
use App\Http\Controllers\Tenant\FolhaRostoController;
use App\Http\Controllers\Tenant\GedController;
use App\Http\Controllers\Tenant\MedicaoBiController;
use App\Http\Controllers\Tenant\MedicaoController;
use App\Http\Controllers\Tenant\MedicaoRelatorioController;
use App\Http\Controllers\Tenant\OrcamentoController;
use App\Http\Controllers\Tenant\OrdemServicoController;
use App\Http\Controllers\Tenant\Parametrizacao\DisciplinaController as ParametrizacaoDisciplinaController;
use App\Http\Controllers\Tenant\Parametrizacao\EmpresaController as ParametrizacaoEmpresaController;
use App\Http\Controllers\Tenant\Parametrizacao\ObraController as ParametrizacaoObraController;
use App\Http\Controllers\Tenant\PermissionController as TenantPermissionController;
use App\Http\Controllers\Tenant\ProjectController;
use App\Http\Controllers\Tenant\ProjectResponsavelController;
use App\Http\Controllers\Tenant\ProjectReviewController;
use App\Http\Controllers\Tenant\ProjectReviewWorkspaceController;
use App\Http\Controllers\Tenant\ProjectViewerController;
use App\Http\Controllers\Tenant\RdoController;
use App\Http\Controllers\Tenant\RdoCadastroController;
use App\Http\Controllers\Tenant\RdoResponsavelController;
use App\Http\Controllers\Tenant\RdoSignatureController;
use App\Http\Controllers\Tenant\RdaController;
use App\Http\Controllers\Tenant\RdaResponsavelController;
use App\Http\Controllers\Tenant\Qualidade\RelatorioNaoConformidadeController;
use App\Http\Controllers\Tenant\Qualidade\RncAcaoCorretivaController;
use App\Http\Controllers\Tenant\Qualidade\RncEvidenciaController;
use App\Http\Controllers\Tenant\Qualidade\RncResponsavelController;
use App\Http\Controllers\Tenant\TutorialController;
use App\Http\Controllers\Tenant\UserController as TenantUserController;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
});

Route::get('/dashboard', function () {
    $user = auth()->user();

    if ($user->is_platform_admin) {
        return redirect()->route('platform.dashboard');
    }

    $tenant = $user->tenants()->wherePivot('status', 'active')->first()
        ?? $user->contractParticipations()->with('tenant')->where('status', 'active')->first()?->tenant;

    abort_if(! $tenant, 403, 'Seu usuário ainda não possui vínculo ativo.');

    return redirect()->route('tenant.dashboard', $tenant);
})->middleware(['auth', 'verified', 'password.changed'])->name('dashboard');

if (app()->environment('local')) {
    Route::get('/dev-login/{email}', function (string $email) {
        $user = User::where('email', $email)->firstOrFail();

        auth()->login($user);

        return redirect()->route('dashboard');
    })->name('dev.login');

    Route::get('/dev-php-limits', fn () => response()->json([
        'upload_max_filesize' => ini_get('upload_max_filesize'),
        'post_max_size' => ini_get('post_max_size'),
        'memory_limit' => ini_get('memory_limit'),
        'upload_tmp_dir' => ini_get('upload_tmp_dir') ?: sys_get_temp_dir(),
    ]));
}

Route::middleware(['auth', 'password.changed'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::middleware(['auth', 'verified', 'password.changed', 'platform.admin'])
    ->prefix('admin')
    ->name('platform.')
    ->group(function () {
        Route::get('/', PlatformDashboardController::class)->name('dashboard');
        Route::get('/tenants', [PlatformTenantController::class, 'index'])->name('tenants.index');
        Route::post('/tenants', [PlatformTenantController::class, 'store'])->name('tenants.store');
        Route::patch('/tenants/{tenant}', [PlatformTenantController::class, 'update'])->name('tenants.update');
        Route::get('/aps', [PlatformApsUsageController::class, 'index'])->name('aps.index');
        Route::delete('/aps/versions/{version}', [PlatformApsUsageController::class, 'destroyVersion'])->name('aps.versions.destroy');
    });

Route::middleware(['auth', 'verified', 'password.changed', 'tenant.resolve', 'tenant.access'])
    ->prefix('t/{tenant:slug}')
    ->name('tenant.')
    ->group(function () {
        Route::get('/', TenantDashboardController::class)->name('dashboard');
        Route::get('/documentacao', [GedController::class, 'index'])->name('ged.index');
        Route::get('/documentacao/tour-preview', [GedController::class, 'tourPreview'])->name('ged.tour-preview');
        Route::post('/documentacao', [GedController::class, 'store'])->name('ged.store');
        Route::get('/documentacao/lixeira', [GedController::class, 'trash'])->name('ged.trash');
        Route::post('/documentacao/lixeira', [GedController::class, 'trashAction'])->name('ged.trash.action');
        Route::get('/documentacao/triagem', [GedController::class, 'triage'])->name('ged.triage');
        Route::post('/documentacao/triagem/{message}/resolver', [GedController::class, 'resolveTriage'])->name('ged.triage.resolve');
        Route::get('/documentacao/parametrizacao', [GedController::class, 'settings'])->name('ged.settings');
        Route::get('/documentacao/email', [GedController::class, 'email'])->name('ged.email');
        Route::post('/documentacao/email/contas/testar', [GedController::class, 'testEmailAccount'])->name('ged.email.accounts.test');
        Route::post('/documentacao/email/contas', [GedController::class, 'storeEmailAccount'])->name('ged.email.accounts.store');
        Route::patch('/documentacao/email/contas/{account}', [GedController::class, 'updateEmailAccount'])->name('ged.email.accounts.update');
        Route::delete('/documentacao/email/contas/{account}', [GedController::class, 'destroyEmailAccount'])->name('ged.email.accounts.destroy');
        Route::post('/documentacao/email/contas/{account}/processar', [GedController::class, 'processEmailAccount'])->name('ged.email.accounts.process');
        Route::post('/documentacao/email/regras', [GedController::class, 'storeEmailRule'])->name('ged.email.rules.store');
        Route::patch('/documentacao/email/regras/{rule}', [GedController::class, 'updateEmailRule'])->name('ged.email.rules.update');
        Route::delete('/documentacao/email/regras/{rule}', [GedController::class, 'destroyEmailRule'])->name('ged.email.rules.destroy');
        Route::post('/documentacao/correspondentes', [GedController::class, 'storeCorrespondent'])->name('ged.correspondents.store');
        Route::post('/documentacao/tipos', [GedController::class, 'storeType'])->name('ged.types.store');
        Route::patch('/documentacao/tipos/{type}', [GedController::class, 'updateType'])->name('ged.types.update');
        Route::delete('/documentacao/tipos/{type}', [GedController::class, 'destroyType'])->name('ged.types.destroy');
        Route::post('/documentacao/tags', [GedController::class, 'storeTag'])->name('ged.tags.store');
        Route::patch('/documentacao/tags/{tag}', [GedController::class, 'updateTag'])->name('ged.tags.update');
        Route::delete('/documentacao/tags/{tag}', [GedController::class, 'destroyTag'])->name('ged.tags.destroy');
        Route::post('/documentacao/selecionados/acoes', [GedController::class, 'bulkAction'])->name('ged.bulk-action');
        Route::get('/documentacao/selecionados/download', [GedController::class, 'bulkDownload'])->name('ged.bulk-download');
        Route::get('/documentacao/{document}/details', [GedController::class, 'details'])->name('ged.details');
        Route::put('/documentacao/{document}', [GedController::class, 'update'])->name('ged.update');
        Route::delete('/documentacao/{document}', [GedController::class, 'destroy'])->name('ged.destroy');
        Route::get('/documentacao/{document}/content', [GedController::class, 'content'])->name('ged.content');
        Route::get('/documentacao/{document}/attachments', [GedController::class, 'attachments'])->name('ged.attachments');
        Route::post('/documentacao/{document}/attachments', [GedController::class, 'storeAttachment'])->name('ged.attachments.store');
        Route::get('/documentacao/{document}/attachments/{attachment}/download', [GedController::class, 'downloadAttachment'])->name('ged.attachments.download');
        Route::get('/documentacao/{document}/attachments/{attachment}/preview', [GedController::class, 'previewAttachment'])->name('ged.attachments.preview');
        Route::post('/documentacao/{document}/attachments/{attachment}/ocr', [GedController::class, 'queueAttachmentOcr'])->name('ged.attachments.ocr');
        Route::patch('/documentacao/{document}/attachments/{attachment}', [GedController::class, 'updateAttachment'])->name('ged.attachments.update');
        Route::delete('/documentacao/{document}/attachments/{attachment}', [GedController::class, 'destroyAttachment'])->name('ged.attachments.destroy');
        Route::get('/documentacao/{document}/metadata', [GedController::class, 'metadata'])->name('ged.metadata');
        Route::get('/documentacao/{document}/notes', [GedController::class, 'notes'])->name('ged.notes');
        Route::post('/documentacao/{document}/notes', [GedController::class, 'storeNote'])->name('ged.notes.store');
        Route::get('/documentacao/{document}/history', [GedController::class, 'history'])->name('ged.history');
        Route::get('/documentacao/{document}/permissions', [GedController::class, 'permissions'])->name('ged.permissions');
        Route::patch('/documentacao/{document}/permissions', [GedController::class, 'updatePermissions'])->name('ged.permissions.update');
        Route::post('/documentacao/{document}/ocr', [GedController::class, 'queueOcr'])->name('ged.ocr');
        Route::get('/documentacao/{document}/preview', [GedController::class, 'preview'])->name('ged.preview');
        Route::get('/documentacao/{document}/download', [GedController::class, 'download'])->name('ged.download');
        Route::get('/tutoriais', TutorialController::class)->name('tutorials.index');
        Route::get('/users', [TenantUserController::class, 'index'])->name('users.index');
        Route::post('/users', [TenantUserController::class, 'store'])->name('users.store');
        Route::patch('/users/{membership}', [TenantUserController::class, 'update'])->name('users.update');
        Route::patch('/users/{membership}/reset-password', [TenantUserController::class, 'resetPassword'])->name('users.reset-password');
        Route::patch('/users/{membership}/deactivate', [TenantUserController::class, 'deactivate'])->name('users.deactivate');
        Route::get('/permissoes', [TenantPermissionController::class, 'index'])->name('permissions.index');
        Route::patch('/permissoes', [TenantPermissionController::class, 'update'])->name('permissions.update');
        Route::get('/contracts', [ContractController::class, 'index'])->name('contracts.index');
        Route::get('/contracts/tour-preview', [ContractController::class, 'tourPreview'])->name('contracts.tour-preview');
        Route::post('/contracts', [ContractController::class, 'store'])->name('contracts.store');
        Route::get('/contracts/{contract}/documento-base/download', [ContractController::class, 'downloadBaseDocument'])->name('contracts.base-document.download');
        Route::get('/contracts/{contract}', [ContractController::class, 'show'])->name('contracts.show');
        Route::patch('/contracts/{contract}/parametrizacao', [ContractController::class, 'parametrize'])->name('contracts.parametrizacao.update');
        Route::post('/contracts/{contract}/aditivos', [ContractAdditiveController::class, 'store'])->name('contracts.additives.store');
        Route::get('/contracts/{contract}/aditivos/{additive}/download', [ContractAdditiveController::class, 'download'])->name('contracts.additives.download');
        Route::post('/contracts/{contract}/participants', [ContractParticipantController::class, 'store'])->name('contracts.participants.store');
        Route::get('/atividades', [ActivityController::class, 'index'])->name('activities.index');
        Route::post('/atividades', [ActivityController::class, 'store'])->name('activities.store');
        Route::patch('/atividades/{activity}', [ActivityController::class, 'update'])->name('activities.update');
        Route::delete('/atividades/{activity}', [ActivityController::class, 'destroy'])->name('activities.destroy');
        Route::post('/atividades/{activity}/comentarios', [ActivityController::class, 'storeComment'])->name('activities.comments.store');
        Route::post('/atividades/{activity}/arquivos', [ActivityController::class, 'storeFile'])->name('activities.files.store');
        Route::get('/diario-obra/rda', [RdaController::class, 'index'])->name('diario-obra.rda.index');
        Route::post('/diario-obra/rda', [RdaController::class, 'store'])->name('diario-obra.rda.store');
        Route::get('/diario-obra/rda/responsaveis', [RdaResponsavelController::class, 'index'])->name('diario-obra.rda.responsaveis.index');
        Route::post('/diario-obra/rda/responsaveis', [RdaResponsavelController::class, 'store'])->name('diario-obra.rda.responsaveis.store');
        Route::delete('/diario-obra/rda/responsaveis/{responsavel}', [RdaResponsavelController::class, 'destroy'])->name('diario-obra.rda.responsaveis.destroy');
        Route::get('/diario-obra/rda/{rda}', [RdaController::class, 'show'])->name('diario-obra.rda.show');
        Route::patch('/diario-obra/rda/{rda}', [RdaController::class, 'update'])->name('diario-obra.rda.update');
        Route::post('/diario-obra/rda/{rda}/publicar', [RdaController::class, 'publish'])->name('diario-obra.rda.publish');
        Route::get('/diario-obra/rdo/dashboard', [RdoController::class, 'dashboard'])->name('diario-obra.rdo.dashboard');
        Route::get('/diario-obra/rdo', [RdoController::class, 'calendar'])->name('diario-obra.rdo.calendar');
        Route::get('/diario-obra/rdo/parametrizacao', [RdoController::class, 'settings'])->name('diario-obra.rdo.settings');
        Route::post('/diario-obra/rdo/parametrizacao', [RdoController::class, 'saveSettings'])->name('diario-obra.rdo.settings.store');
        Route::post('/diario-obra/rdo/gerar', [RdoController::class, 'generate'])->name('diario-obra.rdo.generate');
        Route::get('/diario-obra/rdo/cadastros', [RdoCadastroController::class, 'index'])->name('diario-obra.rdo.cadastros.index');
        Route::post('/diario-obra/rdo/cadastros/mao-obra', [RdoCadastroController::class, 'storeMaoObra'])->name('diario-obra.rdo.cadastros.mao-obra.store');
        Route::patch('/diario-obra/rdo/cadastros/mao-obra/{maoObra}', [RdoCadastroController::class, 'updateMaoObra'])->name('diario-obra.rdo.cadastros.mao-obra.update');
        Route::delete('/diario-obra/rdo/cadastros/mao-obra/{maoObra}', [RdoCadastroController::class, 'destroyMaoObra'])->name('diario-obra.rdo.cadastros.mao-obra.destroy');
        Route::post('/diario-obra/rdo/cadastros/equipamentos', [RdoCadastroController::class, 'storeEquipamento'])->name('diario-obra.rdo.cadastros.equipamentos.store');
        Route::patch('/diario-obra/rdo/cadastros/equipamentos/{equipamento}', [RdoCadastroController::class, 'updateEquipamento'])->name('diario-obra.rdo.cadastros.equipamentos.update');
        Route::delete('/diario-obra/rdo/cadastros/equipamentos/{equipamento}', [RdoCadastroController::class, 'destroyEquipamento'])->name('diario-obra.rdo.cadastros.equipamentos.destroy');
        Route::post('/diario-obra/rdo/cadastros/subcontratadas', [RdoCadastroController::class, 'storeSubcontratada'])->name('diario-obra.rdo.cadastros.subcontratadas.store');
        Route::patch('/diario-obra/rdo/cadastros/subcontratadas/{subcontratada}', [RdoCadastroController::class, 'updateSubcontratada'])->name('diario-obra.rdo.cadastros.subcontratadas.update');
        Route::delete('/diario-obra/rdo/cadastros/subcontratadas/{subcontratada}', [RdoCadastroController::class, 'destroySubcontratada'])->name('diario-obra.rdo.cadastros.subcontratadas.destroy');
        Route::get('/diario-obra/rdo/responsaveis', [RdoResponsavelController::class, 'index'])->name('diario-obra.rdo.responsaveis.index');
        Route::post('/diario-obra/rdo/responsaveis', [RdoResponsavelController::class, 'store'])->name('diario-obra.rdo.responsaveis.store');
        Route::delete('/diario-obra/rdo/responsaveis/{responsavel}', [RdoResponsavelController::class, 'destroy'])->name('diario-obra.rdo.responsaveis.destroy');
        Route::get('/diario-obra/rdo/lote/pdf', [RdoController::class, 'batchPdf'])->name('diario-obra.rdo.batch-pdf');
        Route::post('/diario-obra/rdo/{rdo}/secoes', [RdoController::class, 'saveAllSections'])->name('diario-obra.rdo.sections.store-all');
        Route::post('/diario-obra/rdo/{rdo}/secoes/{secao}', [RdoController::class, 'saveSection'])->name('diario-obra.rdo.sections.store');
        Route::post('/diario-obra/rdo/{rdo}/importar-rda/{rda}', [RdoController::class, 'importRda'])->name('diario-obra.rdo.import-rda');
        Route::post('/diario-obra/rdo/{rdo}/reabrir', [RdoController::class, 'reopen'])->name('diario-obra.rdo.reopen');
        Route::post('/diario-obra/rdo/{rdo}/fluxo', [RdoController::class, 'changeFlow'])->name('diario-obra.rdo.flow');
        Route::post('/diario-obra/rdo/{rdo}/assinaturas', [RdoSignatureController::class, 'store'])->name('diario-obra.rdo.signatures.store');
        Route::post('/diario-obra/rdo/{rdo}/assinaturas/manual', [RdoSignatureController::class, 'uploadManual'])->name('diario-obra.rdo.signatures.manual');
        Route::post('/diario-obra/rdo/{rdo}/assinaturas/{signature}/atualizar', [RdoSignatureController::class, 'refresh'])->name('diario-obra.rdo.signatures.refresh');
        Route::get('/diario-obra/rdo/{rdo}/assinaturas/{signature}/pdf-original', [RdoSignatureController::class, 'downloadUnsigned'])->name('diario-obra.rdo.signatures.unsigned');
        Route::get('/diario-obra/rdo/{rdo}/assinaturas/{signature}/pdf-assinado', [RdoSignatureController::class, 'downloadSigned'])->name('diario-obra.rdo.signatures.signed');
        Route::get('/diario-obra/rdo/{rdo}/historico', [RdoController::class, 'history'])->name('diario-obra.rdo.history');
        Route::get('/diario-obra/rdo/{rdo}/pdf', [RdoController::class, 'pdf'])->name('diario-obra.rdo.pdf');
        Route::get('/diario-obra/rdo/{rdo}', [RdoController::class, 'show'])->name('diario-obra.rdo.show');
        Route::get('/medicao/item', [MedicaoController::class, 'item'])->name('medicao.item.index');
        Route::post('/medicao/item', [MedicaoController::class, 'storeManual'])->name('medicao.item.store');
        Route::post('/medicao/item/orcamento', [MedicaoController::class, 'storeFromOrcamento'])->name('medicao.item.orcamento.store');
        Route::post('/medicao/item/importar', [MedicaoController::class, 'importItems'])->name('medicao.item.import');
        Route::post('/medicao/item/aditivo/orcamento', [MedicaoController::class, 'storeAdditiveFromOrcamento'])->name('medicao.item.additive.orcamento.store');
        Route::post('/medicao/item/aditivo/importar', [MedicaoController::class, 'importAdditiveItems'])->name('medicao.item.additive.import');
        Route::post('/medicao/item/aditivo/manual', [MedicaoController::class, 'storeAdditiveManual'])->name('medicao.item.additive.manual');
        Route::get('/medicao/indice-reajuste', [MedicaoController::class, 'indiceReajuste'])->name('medicao.indice-reajuste.index');
        Route::post('/medicao/indice-reajuste', [MedicaoController::class, 'storeIndiceReajuste'])->name('medicao.indice-reajuste.store');
        Route::delete('/medicao/indice-reajuste/{indice}', [MedicaoController::class, 'destroyIndiceReajuste'])->name('medicao.indice-reajuste.destroy');
        Route::post('/medicao/indice-reajuste/{indice}/competencias', [MedicaoController::class, 'storeIndiceReajusteCompetencia'])->name('medicao.indice-reajuste.competencias.store');
        Route::delete('/medicao/indice-reajuste/{indice}/competencias/{competencia}', [MedicaoController::class, 'destroyIndiceReajusteCompetencia'])->name('medicao.indice-reajuste.competencias.destroy');
        Route::post('/medicao/indice-reajuste/vinculos', [MedicaoController::class, 'storeItemIndiceReajusteVinculos'])->name('medicao.indice-reajuste.vinculos.store');
        Route::post('/medicao/indice-reajuste/vinculos/importar', [MedicaoController::class, 'importItemIndiceReajusteVinculos'])->name('medicao.indice-reajuste.vinculos.import');
        Route::get('/medicao/boletim-medicao', [BoletimMedicaoController::class, 'index'])->name('medicao.boletim-medicao.index');
        Route::post('/medicao/boletim-medicao', [BoletimMedicaoController::class, 'store'])->name('medicao.boletim-medicao.store');
        Route::patch('/medicao/boletim-medicao/{boletim}/congelar', [BoletimMedicaoController::class, 'freeze'])->name('medicao.boletim-medicao.freeze');
        Route::patch('/medicao/boletim-medicao/{boletim}/finalizar', [BoletimMedicaoController::class, 'finish'])->name('medicao.boletim-medicao.finish');
        Route::patch('/medicao/boletim-medicao/{boletim}/reabrir', [BoletimMedicaoController::class, 'reopen'])->name('medicao.boletim-medicao.reopen');
        Route::get('/medicao/relatorios/pleito-preliminar/excel', [MedicaoRelatorioController::class, 'exportPleitoPreliminarExcel'])->name('medicao.relatorios.pleito-preliminar.excel');
        Route::get('/medicao/relatorios/pleito-preliminar/pdf', [MedicaoRelatorioController::class, 'exportPleitoPreliminarPdf'])->name('medicao.relatorios.pleito-preliminar.pdf');
        Route::get('/medicao/relatorios/analise-pleito/excel', [MedicaoRelatorioController::class, 'exportAnalisePleitoExcel'])->name('medicao.relatorios.analise-pleito.excel');
        Route::get('/medicao/relatorios/analise-pleito/pdf', [MedicaoRelatorioController::class, 'exportAnalisePleitoPdf'])->name('medicao.relatorios.analise-pleito.pdf');
        Route::get('/medicao/relatorios/sintetico/excel', [MedicaoRelatorioController::class, 'exportSinteticoExcel'])->name('medicao.relatorios.sintetico.excel');
        Route::get('/medicao/relatorios/sintetico/pdf', [MedicaoRelatorioController::class, 'exportSinteticoPdf'])->name('medicao.relatorios.sintetico.pdf');
        Route::get('/medicao/relatorios/por-fr/excel', [MedicaoRelatorioController::class, 'exportPorFrExcel'])->name('medicao.relatorios.por-fr.excel');
        Route::get('/medicao/relatorios/por-fr/pdf', [MedicaoRelatorioController::class, 'exportPorFrPdf'])->name('medicao.relatorios.por-fr.pdf');
        Route::get('/medicao/relatorios/resumo/excel', [MedicaoRelatorioController::class, 'exportResumoExcel'])->name('medicao.relatorios.resumo.excel');
        Route::get('/medicao/relatorios/resumo/pdf', [MedicaoRelatorioController::class, 'exportResumoPdf'])->name('medicao.relatorios.resumo.pdf');
        Route::get('/medicao/relatorios', [MedicaoRelatorioController::class, 'index'])->name('medicao.relatorios.index');
        Route::get('/medicao/bi', MedicaoBiController::class)->name('medicao.bi.index');
        Route::get('/medicao/folha-rosto', [FolhaRostoController::class, 'index'])->name('medicao.folha-rosto.index');
        Route::get('/medicao/folha-rosto/os/{ordem}', [FolhaRostoController::class, 'show'])->name('medicao.folha-rosto.show');
        Route::post('/medicao/folha-rosto/os/{ordem}', [FolhaRostoController::class, 'store'])->name('medicao.folha-rosto.store');
        Route::patch('/medicao/folha-rosto/{folha}', [FolhaRostoController::class, 'update'])->name('medicao.folha-rosto.update');
        Route::get('/medicao/analisar-pleito', [FolhaRostoController::class, 'analisarPleito'])->name('medicao.analisar-pleito.index');
        Route::get('/medicao/analisar-pleito/grupo', [FolhaRostoController::class, 'analisarPleitoGrupo'])->name('medicao.analisar-pleito.grupo');
        Route::get('/medicao/analisar-pleito/{folha}/detalhes', [FolhaRostoController::class, 'analisarPleitoFolha'])->name('medicao.analisar-pleito.folha');
        Route::get('/medicao/analisar-pleito/responsaveis', [FolhaRostoController::class, 'responsaveisAnalise'])->name('medicao.analisar-pleito.responsaveis.index');
        Route::post('/medicao/analisar-pleito/responsaveis', [FolhaRostoController::class, 'storeResponsavelAnalise'])->name('medicao.analisar-pleito.responsaveis.store');
        Route::delete('/medicao/analisar-pleito/responsaveis/{responsavel}', [FolhaRostoController::class, 'destroyResponsavelAnalise'])->name('medicao.analisar-pleito.responsaveis.destroy');
        Route::post('/medicao/analisar-pleito/{folha}/analise', [FolhaRostoController::class, 'storeAnalise'])->name('medicao.analisar-pleito.analise.store');
        Route::patch('/medicao/analisar-pleito/{folha}/fluxo', [FolhaRostoController::class, 'moveAnalysisFlow'])->name('medicao.analisar-pleito.fluxo');
        Route::patch('/medicao/folha-rosto/{folha}/enviar-analise', [FolhaRostoController::class, 'submitAnalysis'])->name('medicao.folha-rosto.submit-analysis');
        Route::get('/medicao/folha-rosto/{folha}/memoria-calculo', [FolhaRostoController::class, 'downloadMemoria'])->name('medicao.folha-rosto.memoria.download');
        Route::get('/ordem-servico/os', [OrdemServicoController::class, 'index'])->name('ordem-servico.os.index');
        Route::post('/ordem-servico/os', [OrdemServicoController::class, 'store'])->name('ordem-servico.os.store');
        Route::patch('/ordem-servico/os/{ordem}/enviar-analise', [OrdemServicoController::class, 'submitForAnalysis'])->name('ordem-servico.os.submit-analysis');
        Route::patch('/ordem-servico/os/{ordem}/analise', [OrdemServicoController::class, 'analyze'])->name('ordem-servico.os.analyze');
        Route::patch('/ordem-servico/os/{ordem}/aprovacao', [OrdemServicoController::class, 'approve'])->name('ordem-servico.os.approve');
        Route::get('/ordem-servico/analise', [OrdemServicoController::class, 'analise'])->name('ordem-servico.analise.index');
        Route::get('/ordem-servico/analise/{ordem}/detalhes', [OrdemServicoController::class, 'analiseDetalhes'])->name('ordem-servico.analise.detalhes');
        Route::get('/ordem-servico/responsaveis', [OrdemServicoController::class, 'responsaveis'])->name('ordem-servico.responsaveis.index');
        Route::post('/ordem-servico/responsaveis', [OrdemServicoController::class, 'storeResponsavel'])->name('ordem-servico.responsaveis.store');
        Route::delete('/ordem-servico/responsaveis/{responsavel}', [OrdemServicoController::class, 'destroyResponsavel'])->name('ordem-servico.responsaveis.destroy');
        Route::get('/orcamentos', [OrcamentoController::class, 'index'])->name('orcamentos.index');
        Route::get('/orcamentos/novo', [OrcamentoController::class, 'create'])->name('orcamentos.create');
        Route::get('/orcamentos/importar', [OrcamentoController::class, 'createImport'])->name('orcamentos.import.create');
        Route::post('/orcamentos/importar', [OrcamentoController::class, 'storeImport'])->name('orcamentos.import.store');
        Route::post('/orcamentos', [OrcamentoController::class, 'store'])->name('orcamentos.store');
        Route::get('/orcamentos/composicoes', [OrcamentoController::class, 'composicoes'])->name('orcamentos.composicoes.index');
        Route::get('/orcamentos/composicoes/nova', [OrcamentoController::class, 'createComposicao'])->name('orcamentos.composicoes.create');
        Route::post('/orcamentos/composicoes', [OrcamentoController::class, 'storeComposicao'])->name('orcamentos.composicoes.store');
        Route::post('/orcamentos/composicoes/importar', [OrcamentoController::class, 'importComposicoes'])->name('orcamentos.composicoes.import');
        Route::post('/orcamentos/composicoes/importar-analitico', [OrcamentoController::class, 'importComposicoesAnalitico'])->name('orcamentos.composicoes.import-analitico');
        Route::get('/orcamentos/composicoes/{composicao}/opcoes-itens', [OrcamentoController::class, 'composicaoItemOptions'])->name('orcamentos.composicoes.items.options');
        Route::post('/orcamentos/composicoes/{composicao}/itens', [OrcamentoController::class, 'storeComposicaoItem'])->name('orcamentos.composicoes.items.store');
        Route::post('/orcamentos/composicoes/{composicao}/insumos', [OrcamentoController::class, 'storeComposicaoCreatedInsumo'])->name('orcamentos.composicoes.insumos.store');
        Route::patch('/orcamentos/composicoes/{composicao}/itens/{item}', [OrcamentoController::class, 'updateComposicaoItem'])->name('orcamentos.composicoes.items.update');
        Route::delete('/orcamentos/composicoes/{composicao}/itens/{item}', [OrcamentoController::class, 'destroyComposicaoItem'])->name('orcamentos.composicoes.items.destroy');
        Route::get('/orcamentos/composicoes/{composicao}', [OrcamentoController::class, 'showComposicao'])->name('orcamentos.composicoes.show');
        Route::get('/orcamentos/insumos', [OrcamentoController::class, 'insumos'])->name('orcamentos.insumos.index');
        Route::post('/orcamentos/insumos', [OrcamentoController::class, 'storeInsumo'])->name('orcamentos.insumos.store');
        Route::post('/orcamentos/insumos/importar', [OrcamentoController::class, 'importInsumos'])->name('orcamentos.insumos.import');
        Route::post('/orcamentos/insumos/grupos', [OrcamentoController::class, 'storeInsumoGrupo'])->name('orcamentos.insumos.grupos.store');
        Route::patch('/orcamentos/insumos/grupos/{grupo}', [OrcamentoController::class, 'updateInsumoGrupo'])->name('orcamentos.insumos.grupos.update');
        Route::delete('/orcamentos/insumos/grupos/{grupo}', [OrcamentoController::class, 'destroyInsumoGrupo'])->name('orcamentos.insumos.grupos.destroy');
        Route::get('/orcamentos/{orcamento}/composicoes-opcoes', [OrcamentoController::class, 'orcamentoComposicaoOptions'])->name('orcamentos.composicoes.options');
        Route::get('/orcamentos/{orcamento}/insumos-opcoes', [OrcamentoController::class, 'orcamentoInsumoOptions'])->name('orcamentos.insumos.options');
        Route::get('/orcamentos/{orcamento}/relatorios/zip', [OrcamentoController::class, 'downloadRelatoriosZip'])->name('orcamentos.relatorios.zip');
        Route::get('/orcamentos/{orcamento}/relatorios/sintetico', [OrcamentoController::class, 'downloadRelatorioSintetico'])->name('orcamentos.relatorios.sintetico');
        Route::get('/orcamentos/{orcamento}/relatorios/resumo', [OrcamentoController::class, 'downloadRelatorioResumo'])->name('orcamentos.relatorios.resumo');
        Route::get('/orcamentos/{orcamento}/copiar/{sourceOrcamento}', [OrcamentoController::class, 'copyPreview'])->name('orcamentos.copy.preview');
        Route::post('/orcamentos/{orcamento}/copiar', [OrcamentoController::class, 'copyFromOrcamento'])->name('orcamentos.copy.store');
        Route::patch('/orcamentos/{orcamento}/finalizar', [OrcamentoController::class, 'close'])->name('orcamentos.close');
        Route::post('/orcamentos/{orcamento}/etapas/{etapa}/composicoes', [OrcamentoController::class, 'storeOrcamentoComposicaoItem'])->name('orcamentos.etapas.composicoes.store');
        Route::post('/orcamentos/{orcamento}/etapas/{etapa}/insumos', [OrcamentoController::class, 'storeOrcamentoInsumoItem'])->name('orcamentos.etapas.insumos.store');
        Route::patch('/orcamentos/{orcamento}/itens/{item}', [OrcamentoController::class, 'updateOrcamentoItem'])->name('orcamentos.itens.update');
        Route::patch('/orcamentos/{orcamento}/itens/{item}/bdi', [OrcamentoController::class, 'toggleOrcamentoItemBdi'])->name('orcamentos.itens.toggle-bdi');
        Route::delete('/orcamentos/{orcamento}/itens/{item}', [OrcamentoController::class, 'destroyOrcamentoItem'])->name('orcamentos.itens.destroy');
        Route::post('/orcamentos/{orcamento}/etapas', [OrcamentoController::class, 'storeEtapa'])->name('orcamentos.etapas.store');
        Route::patch('/orcamentos/{orcamento}/etapas/{etapa}', [OrcamentoController::class, 'updateEtapa'])->name('orcamentos.etapas.update');
        Route::patch('/orcamentos/{orcamento}/etapas/{etapa}/ocultar', [OrcamentoController::class, 'toggleEtapaVisibility'])->name('orcamentos.etapas.toggle-hidden');
        Route::delete('/orcamentos/{orcamento}/etapas/{etapa}', [OrcamentoController::class, 'destroyEtapa'])->name('orcamentos.etapas.destroy');
        Route::get('/orcamentos/{orcamento}', [OrcamentoController::class, 'show'])->name('orcamentos.show');
        Route::get('/projetos/visualizar', [ProjectController::class, 'tree'])->name('projects.visualizar.index');
        Route::get('/projetos/tour-preview', [ProjectController::class, 'tourPreview'])->name('projects.tour-preview');
        Route::get('/projetos/lista-mestra', [ProjectController::class, 'masterList'])->name('projects.master-list.index');
        Route::get('/projetos/lista-mestra/pdf', [ProjectController::class, 'masterListPdf'])->name('projects.master-list.pdf');
        Route::get('/projetos/lista-mestra/excel', [ProjectController::class, 'masterListExcel'])->name('projects.master-list.excel');
        Route::get('/projetos/revisados', [ProjectController::class, 'revisions'])->name('projects.revisions.index');
        Route::get('/projetos', [ProjectController::class, 'index'])->name('projects.index');
        Route::post('/projetos', [ProjectController::class, 'store'])->name('projects.store');
        Route::get('/projetos/responsaveis', [ProjectResponsavelController::class, 'index'])->name('projects.responsaveis.index');
        Route::post('/projetos/responsaveis', [ProjectResponsavelController::class, 'store'])->name('projects.responsaveis.store');
        Route::delete('/projetos/responsaveis/{responsavel}', [ProjectResponsavelController::class, 'destroy'])->name('projects.responsaveis.destroy');
        Route::get('/projetos/analisar', [ProjectReviewController::class, 'index'])->name('projects.review.index');
        Route::patch('/projetos/{document}/analise', [ProjectReviewController::class, 'update'])->name('projects.review.update');
        Route::get('/projetos/aps/viewer-token', [ProjectViewerController::class, 'token'])->name('projects.viewer-token');
        Route::get('/projetos/versoes/{version}/visualizar', [ProjectViewerController::class, 'show'])->name('projects.viewer');
        Route::post('/projetos/versoes/{version}/processar-aps', [ProjectViewerController::class, 'process'])->name('projects.process-aps');
        Route::get('/projetos/versoes/{version}/status-aps', [ProjectViewerController::class, 'status'])->name('projects.aps-status');
        Route::post('/projetos/versoes/{version}/marcacoes', [ProjectReviewWorkspaceController::class, 'storeMarkup'])->name('projects.markups.store');
        Route::patch('/projetos/marcacoes/{markup}', [ProjectReviewWorkspaceController::class, 'updateMarkup'])->name('projects.markups.update');
        Route::delete('/projetos/marcacoes/{markup}', [ProjectReviewWorkspaceController::class, 'destroyMarkup'])->name('projects.markups.destroy');
        Route::patch('/projetos/checklist-itens/{item}', [ProjectReviewWorkspaceController::class, 'updateChecklistItem'])->name('projects.checklist-items.update');
        Route::patch('/projetos/{document}/inativar', [ProjectController::class, 'inactivate'])->name('projects.inactivate');
        Route::delete('/projetos/{document}', [ProjectController::class, 'destroy'])->name('projects.destroy');
        Route::get('/qualidade/rnc', [RelatorioNaoConformidadeController::class, 'index'])->name('qualidade.rnc.index');
        Route::get('/qualidade/rnc/dashboard', [RelatorioNaoConformidadeController::class, 'dashboard'])->name('qualidade.rnc.dashboard');
        Route::get('/qualidade/rnc/nova', [RelatorioNaoConformidadeController::class, 'create'])->name('qualidade.rnc.create');
        Route::post('/qualidade/rnc', [RelatorioNaoConformidadeController::class, 'store'])->name('qualidade.rnc.store');
        Route::get('/qualidade/rnc/alertas', [RncResponsavelController::class, 'index'])->name('qualidade.rnc.responsaveis.index');
        Route::post('/qualidade/rnc/alertas', [RncResponsavelController::class, 'store'])->name('qualidade.rnc.responsaveis.store');
        Route::delete('/qualidade/rnc/alertas/{responsavel}', [RncResponsavelController::class, 'destroy'])->name('qualidade.rnc.responsaveis.destroy');
        Route::get('/qualidade/rnc/{rnc}/editar', [RelatorioNaoConformidadeController::class, 'edit'])->name('qualidade.rnc.edit');
        Route::patch('/qualidade/rnc/{rnc}', [RelatorioNaoConformidadeController::class, 'update'])->name('qualidade.rnc.update');
        Route::get('/qualidade/rnc/{rnc}', [RelatorioNaoConformidadeController::class, 'show'])->name('qualidade.rnc.show');
        Route::post('/qualidade/rnc/{rnc}/notificar', [RelatorioNaoConformidadeController::class, 'notifyResponsibleUsers'])->name('qualidade.rnc.notify');
        Route::get('/qualidade/rnc/{rnc}/acao-corretiva', [RncAcaoCorretivaController::class, 'create'])->name('qualidade.rnc.acao-corretiva.create');
        Route::post('/qualidade/rnc/{rnc}/acao-corretiva', [RncAcaoCorretivaController::class, 'store'])->name('qualidade.rnc.acao-corretiva.store');
        Route::get('/qualidade/rnc/{rnc}/acao-corretiva/{acaoCorretiva}/download', [RncAcaoCorretivaController::class, 'download'])->name('qualidade.rnc.acao-corretiva.download');
        Route::get('/qualidade/rnc/{rnc}/analisar-proposta', [RncAcaoCorretivaController::class, 'review'])->name('qualidade.rnc.analisar-proposta.create');
        Route::post('/qualidade/rnc/{rnc}/analisar-proposta', [RncAcaoCorretivaController::class, 'submitReview'])->name('qualidade.rnc.analisar-proposta.store');
        Route::get('/qualidade/rnc/{rnc}/evidenciar', [RncEvidenciaController::class, 'create'])->name('qualidade.rnc.evidencias.create');
        Route::post('/qualidade/rnc/{rnc}/evidenciar', [RncEvidenciaController::class, 'store'])->name('qualidade.rnc.evidencias.store');
        Route::get('/qualidade/rnc/{rnc}/evidencias/{evidencia}/download', [RncEvidenciaController::class, 'download'])->name('qualidade.rnc.evidencias.download');
        Route::get('/qualidade/rnc/{rnc}/pdf', [RelatorioNaoConformidadeController::class, 'pdf'])->name('qualidade.rnc.pdf');
        Route::delete('/qualidade/rnc/{rnc}', [RelatorioNaoConformidadeController::class, 'destroy'])->name('qualidade.rnc.destroy');

        Route::middleware('parametrizacao.permission:view_parametrizacao')
            ->prefix('parametrizacao')
            ->name('parametrizacao.')
            ->group(function () {
                Route::middleware('parametrizacao.permission:view_parametrizacao_empresas')->group(function () {
                    Route::get('/empresas', [ParametrizacaoEmpresaController::class, 'index'])->name('empresas.index');
                    Route::post('/empresas', [ParametrizacaoEmpresaController::class, 'store'])->name('empresas.store');
                    Route::patch('/empresas/{empresa}', [ParametrizacaoEmpresaController::class, 'update'])->name('empresas.update');
                    Route::delete('/empresas/{empresa}', [ParametrizacaoEmpresaController::class, 'destroy'])->name('empresas.destroy');
                });

                Route::middleware('parametrizacao.permission:view_parametrizacao_obras')->group(function () {
                    Route::get('/obras', [ParametrizacaoObraController::class, 'index'])->name('obras.index');
                    Route::post('/obras', [ParametrizacaoObraController::class, 'store'])->name('obras.store');
                    Route::patch('/obras/{obra}', [ParametrizacaoObraController::class, 'update'])->name('obras.update');
                    Route::delete('/obras/{obra}', [ParametrizacaoObraController::class, 'destroy'])->name('obras.destroy');
                });

                Route::middleware('parametrizacao.permission:view_parametrizacao_disciplinas')->group(function () {
                    Route::get('/disciplinas', [ParametrizacaoDisciplinaController::class, 'index'])->name('disciplinas.index');
                    Route::post('/disciplinas', [ParametrizacaoDisciplinaController::class, 'store'])->name('disciplinas.store');
                    Route::patch('/disciplinas/{disciplina}', [ParametrizacaoDisciplinaController::class, 'update'])->name('disciplinas.update');
                    Route::delete('/disciplinas/{disciplina}', [ParametrizacaoDisciplinaController::class, 'destroy'])->name('disciplinas.destroy');
                });
            });
    });

require __DIR__.'/auth.php';
