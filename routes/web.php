<?php

use App\Http\Controllers\Platform\ApsUsageController as PlatformApsUsageController;
use App\Http\Controllers\Platform\DashboardController as PlatformDashboardController;
use App\Http\Controllers\Platform\TenantController as PlatformTenantController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Tenant\ActivityController;
use App\Http\Controllers\Tenant\ContractController;
use App\Http\Controllers\Tenant\ContractParticipantController;
use App\Http\Controllers\Tenant\DashboardController as TenantDashboardController;
use App\Http\Controllers\Tenant\Parametrizacao\ContratoController as ParametrizacaoContratoController;
use App\Http\Controllers\Tenant\Parametrizacao\DisciplinaController as ParametrizacaoDisciplinaController;
use App\Http\Controllers\Tenant\Parametrizacao\EmpresaController as ParametrizacaoEmpresaController;
use App\Http\Controllers\Tenant\Parametrizacao\ObraController as ParametrizacaoObraController;
use App\Http\Controllers\Tenant\Parametrizacao\UsuarioContratoController as ParametrizacaoUsuarioContratoController;
use App\Http\Controllers\Tenant\PermissionController as TenantPermissionController;
use App\Http\Controllers\Tenant\ProjectController;
use App\Http\Controllers\Tenant\ProjectResponsavelController;
use App\Http\Controllers\Tenant\ProjectReviewController;
use App\Http\Controllers\Tenant\ProjectReviewWorkspaceController;
use App\Http\Controllers\Tenant\ProjectViewerController;
use App\Http\Controllers\Tenant\Qualidade\RelatorioNaoConformidadeController;
use App\Http\Controllers\Tenant\Qualidade\RncAcaoCorretivaController;
use App\Http\Controllers\Tenant\Qualidade\RncEvidenciaController;
use App\Http\Controllers\Tenant\Qualidade\RncResponsavelController;
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
        Route::get('/users', [TenantUserController::class, 'index'])->name('users.index');
        Route::post('/users', [TenantUserController::class, 'store'])->name('users.store');
        Route::patch('/users/{membership}', [TenantUserController::class, 'update'])->name('users.update');
        Route::patch('/users/{membership}/deactivate', [TenantUserController::class, 'deactivate'])->name('users.deactivate');
        Route::get('/permissoes', [TenantPermissionController::class, 'index'])->name('permissions.index');
        Route::patch('/permissoes', [TenantPermissionController::class, 'update'])->name('permissions.update');
        Route::get('/contracts', [ContractController::class, 'index'])->name('contracts.index');
        Route::post('/contracts', [ContractController::class, 'store'])->name('contracts.store');
        Route::get('/contracts/{contract}', [ContractController::class, 'show'])->name('contracts.show');
        Route::post('/contracts/{contract}/participants', [ContractParticipantController::class, 'store'])->name('contracts.participants.store');
        Route::get('/atividades', [ActivityController::class, 'index'])->name('activities.index');
        Route::post('/atividades', [ActivityController::class, 'store'])->name('activities.store');
        Route::patch('/atividades/{activity}', [ActivityController::class, 'update'])->name('activities.update');
        Route::delete('/atividades/{activity}', [ActivityController::class, 'destroy'])->name('activities.destroy');
        Route::post('/atividades/{activity}/comentarios', [ActivityController::class, 'storeComment'])->name('activities.comments.store');
        Route::post('/atividades/{activity}/arquivos', [ActivityController::class, 'storeFile'])->name('activities.files.store');
        Route::get('/projetos/visualizar', [ProjectController::class, 'tree'])->name('projects.visualizar.index');
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

                Route::middleware('parametrizacao.permission:view_parametrizacao_contrato')->group(function () {
                    Route::get('/contrato', [ParametrizacaoContratoController::class, 'index'])->name('contrato.index');
                    Route::post('/contrato', [ParametrizacaoContratoController::class, 'store'])->name('contrato.store');
                });

                Route::middleware('parametrizacao.permission:view_parametrizacao_disciplinas')->group(function () {
                    Route::get('/disciplinas', [ParametrizacaoDisciplinaController::class, 'index'])->name('disciplinas.index');
                    Route::post('/disciplinas', [ParametrizacaoDisciplinaController::class, 'store'])->name('disciplinas.store');
                    Route::patch('/disciplinas/{disciplina}', [ParametrizacaoDisciplinaController::class, 'update'])->name('disciplinas.update');
                    Route::delete('/disciplinas/{disciplina}', [ParametrizacaoDisciplinaController::class, 'destroy'])->name('disciplinas.destroy');
                });

                Route::middleware('parametrizacao.permission:view_parametrizacao_usuarios_contratos')->group(function () {
                    Route::get('/usuarios-contratos', [ParametrizacaoUsuarioContratoController::class, 'index'])->name('usuarios-contratos.index');
                    Route::post('/usuarios-contratos', [ParametrizacaoUsuarioContratoController::class, 'store'])->name('usuarios-contratos.store');
                    Route::delete('/usuarios-contratos/{participant}', [ParametrizacaoUsuarioContratoController::class, 'destroy'])->name('usuarios-contratos.destroy');
                });
            });
    });

require __DIR__.'/auth.php';
