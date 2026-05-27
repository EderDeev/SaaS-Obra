import ConfirmActionButton from '@/Components/ConfirmActionButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowLeft,
    Calendar,
    CheckCircle2,
    CheckSquare,
    Download,
    Eye,
    Loader2,
    MapPin,
    MessageSquarePlus,
    Play,
    RefreshCw,
    Trash2,
    TriangleAlert,
    UserRound,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

const statusLabels = {
    not_submitted: 'Aguardando envio para APS',
    queued: 'Na fila APS',
    processing: 'Processando na APS',
    ready: 'Pronto para visualizacao',
    failed: 'Erro no processamento APS',
};

const markupStatusLabels = {
    open: 'Aberta',
    in_progress: 'Em andamento',
    resolved: 'Resolvida',
};

const priorityLabels = {
    baixa: 'Baixa',
    normal: 'Normal',
    alta: 'Alta',
    critica: 'Critica',
};

const priorityClasses = {
    baixa: 'sig-pill-green',
    normal: 'sig-pill-blue',
    alta: 'sig-pill-amber',
    critica: 'sig-pill-red',
};

function fileDisplayName(version) {
    return version?.stored_name || version?.original_name || '';
}

function userLabel(user) {
    return user ? `${user.name} (${user.email})` : 'Sem responsavel';
}

function formatDate(value) {
    if (!value) {
        return 'Sem prazo';
    }

    return new Date(value).toLocaleDateString('pt-BR');
}

function initials(name) {
    return (name || '?')
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0])
        .join('')
        .toUpperCase();
}

function loadViewerAssets() {
    return new Promise((resolve, reject) => {
        if (window.Autodesk?.Viewing) {
            resolve();
            return;
        }

        if (!document.querySelector('link[data-aps-viewer]')) {
            const link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = 'https://developer.api.autodesk.com/modelderivative/v2/viewers/7.*/style.css';
            link.dataset.apsViewer = 'true';
            document.head.appendChild(link);
        }

        const existingScript = document.querySelector('script[data-aps-viewer]');

        if (existingScript) {
            existingScript.addEventListener('load', resolve, { once: true });
            existingScript.addEventListener('error', reject, { once: true });
            return;
        }

        const script = document.createElement('script');
        script.src = 'https://developer.api.autodesk.com/modelderivative/v2/viewers/7.*/viewer3D.js';
        script.dataset.apsViewer = 'true';
        script.onload = resolve;
        script.onerror = reject;
        document.body.appendChild(script);
    });
}

export default function Viewer({
    tenant,
    version,
    apsConfigured,
    canReviewProjects = false,
    contractUsers = [],
    reviewMarkups = [],
    reviewChecklist = null,
}) {
    const containerRef = useRef(null);
    const viewerRef = useRef(null);
    const [currentStatus, setCurrentStatus] = useState(version.derivative_status);
    const [progress, setProgress] = useState(null);
    const [error, setError] = useState(null);

    const isReady = currentStatus === 'ready' && version.aps_urn;
    const displayName = fileDisplayName(version);

    const processAps = () => {
        router.post(route('tenant.projects.process-aps', [tenant.slug, version.id]));
    };

    const captureViewerState = () => {
        if (!viewerRef.current?.getState) {
            return null;
        }

        try {
            return viewerRef.current.getState();
        } catch {
            return null;
        }
    };

    const restoreViewerState = (state) => {
        if (!state || !viewerRef.current?.restoreState) {
            return;
        }

        try {
            viewerRef.current.restoreState(state, null, true);
            window.setTimeout(() => viewerRef.current?.resize(), 80);
        } catch {
            setError('Nao foi possivel restaurar a posicao da marcacao.');
        }
    };

    useEffect(() => {
        if (!version.aps_urn || currentStatus === 'ready' || currentStatus === 'failed') {
            return undefined;
        }

        const interval = window.setInterval(async () => {
            try {
                const response = await fetch(route('tenant.projects.aps-status', [tenant.slug, version.id]), {
                    headers: { Accept: 'application/json' },
                });
                const payload = await response.json();

                if (!response.ok) {
                    throw new Error(payload.message || 'Nao foi possivel consultar o status APS.');
                }

                setCurrentStatus(payload.status);
                setProgress(payload.progress || null);

                if (payload.status === 'ready' || payload.status === 'failed') {
                    window.clearInterval(interval);
                }
            } catch (exception) {
                setError(exception.message);
                window.clearInterval(interval);
            }
        }, 8000);

        return () => window.clearInterval(interval);
    }, [currentStatus, tenant.slug, version.aps_urn, version.id]);

    useEffect(() => {
        if (!isReady || !containerRef.current) {
            return undefined;
        }

        let cancelled = false;
        let resizeObserver = null;
        const resizeViewer = () => {
            window.requestAnimationFrame(() => {
                if (!viewerRef.current) {
                    return;
                }

                viewerRef.current.resize();
            });
        };

        loadViewerAssets()
            .then(() => {
                if (cancelled || !containerRef.current) {
                    return;
                }

                const options = {
                    env: 'AutodeskProduction',
                    api: 'derivativeV2',
                    getAccessToken: async (callback) => {
                        const response = await fetch(route('tenant.projects.viewer-token', tenant.slug), {
                            headers: { Accept: 'application/json' },
                        });
                        const token = await response.json();

                        callback(token.access_token, token.expires_in);
                    },
                };

                window.Autodesk.Viewing.Initializer(options, () => {
                    if (cancelled || !containerRef.current) {
                        return;
                    }

                    const viewer = new window.Autodesk.Viewing.GuiViewer3D(containerRef.current);
                    viewer.start();
                    viewerRef.current = viewer;

                    if (window.ResizeObserver) {
                        resizeObserver = new window.ResizeObserver(resizeViewer);
                        resizeObserver.observe(containerRef.current);
                    }

                    window.addEventListener('resize', resizeViewer);
                    window.Autodesk.Viewing.Document.load(
                        `urn:${version.aps_urn}`,
                        (doc) => {
                            const viewable = doc.getRoot().getDefaultGeometry();
                            Promise.resolve(viewer.loadDocumentNode(doc, viewable)).then(() => {
                                resizeViewer();
                                window.setTimeout(() => {
                                    viewer.resize();
                                    viewer.fitToView();
                                }, 150);
                            });
                        },
                        () => setError('Nao foi possivel carregar o modelo no Autodesk Viewer.'),
                    );
                });
            })
            .catch(() => setError('Nao foi possivel carregar a biblioteca do Autodesk Viewer.'));

        return () => {
            cancelled = true;
            window.removeEventListener('resize', resizeViewer);
            resizeObserver?.disconnect();

            if (viewerRef.current) {
                viewerRef.current.finish();
                viewerRef.current = null;
            }
        };
    }, [isReady, tenant.slug, version.aps_urn]);

    return (
        <AuthenticatedLayout>
            <Head title={`Visualizar ${version.document.title}`} />

            <section className="sig-content sig-viewer-content">
                <header className="sig-viewer-header flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <div className="flex items-center gap-2 text-[var(--ink-500)]">
                            <Eye size={15} />
                            <span className="eyebrow">Projetos</span>
                        </div>
                        <h1 className="mt-1 text-xl font-semibold text-[var(--ink-900)]">{version.document.title}</h1>
                        <p className="mt-1 text-sm text-[var(--ink-500)]">
                            {version.document.code} - {displayName} - {statusLabels[currentStatus] || currentStatus}
                        </p>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <Link href={route('tenant.projects.visualizar.index', tenant.slug)} className="sig-btn sig-btn-secondary">
                            <ArrowLeft size={15} />
                            Visualizar projetos
                        </Link>
                        <a href={version.url} download={displayName} className="sig-btn sig-btn-secondary">
                            <Download size={15} />
                            Baixar
                        </a>
                        {currentStatus === 'not_submitted' && (
                            <button type="button" onClick={processAps} disabled={!apsConfigured} className="sig-btn sig-btn-primary">
                                <Play size={15} />
                                Processar APS
                            </button>
                        )}
                    </div>
                </header>

                {error && (
                    <div className="mx-4 mt-4 rounded-lg border border-[var(--red)] bg-[var(--red-50)] px-4 py-3 text-sm text-[var(--red)]">
                        {error}
                    </div>
                )}

                {isReady ? (
                    <div className="sig-viewer-workspace">
                        <div className="sig-viewer-stage">
                            <div ref={containerRef} className="sig-viewer-canvas" />
                        </div>
                        <ProjectReviewPanel
                            tenant={tenant}
                            version={version}
                            canReviewProjects={canReviewProjects}
                            contractUsers={contractUsers}
                            markups={reviewMarkups}
                            checklist={reviewChecklist}
                            captureViewerState={captureViewerState}
                            restoreViewerState={restoreViewerState}
                        />
                    </div>
                ) : (
                    <div className="sig-card sig-viewer-empty flex flex-col items-center justify-center p-8 text-center">
                        {currentStatus === 'failed' ? (
                            <TriangleAlert size={34} className="text-[var(--red)]" />
                        ) : (
                            <Loader2 size={34} className="animate-spin text-[var(--primary)]" />
                        )}
                        <h2 className="mt-4 text-lg font-semibold text-[var(--ink-900)]">
                            {statusLabels[currentStatus] || currentStatus}
                        </h2>
                        <p className="mt-2 max-w-xl text-sm text-[var(--ink-500)]">
                            {currentStatus === 'failed'
                                ? 'O processamento APS falhou. Tente processar novamente ou verifique se o formato enviado e suportado pelo Model Derivative.'
                                : 'A Autodesk pode levar alguns minutos para preparar o arquivo para visualizacao. Esta tela consulta o status automaticamente.'}
                        </p>
                        {progress && <p className="mono mt-3 text-xs text-[var(--ink-500)]">{progress}</p>}
                        <button
                            type="button"
                            onClick={() => router.reload({ only: ['version'] })}
                            className="sig-btn sig-btn-secondary mt-5"
                        >
                            <RefreshCw size={15} />
                            Atualizar
                        </button>
                    </div>
                )}
            </section>
        </AuthenticatedLayout>
    );
}

function ProjectReviewPanel({
    tenant,
    version,
    canReviewProjects,
    contractUsers,
    markups,
    checklist,
    captureViewerState,
    restoreViewerState,
}) {
    const [markupForm, setMarkupForm] = useState({
        title: '',
        description: '',
        assigned_to_id: '',
        priority: 'normal',
        due_date: '',
    });
    const checklistItems = checklist?.items || [];
    const checklistSignature = useMemo(
        () => checklistItems.map((item) => `${item.id}:${item.checked}:${item.notes || ''}`).join('|'),
        [checklistItems],
    );
    const [checklistNotes, setChecklistNotes] = useState(() => Object.fromEntries(
        checklistItems.map((item) => [item.id, item.notes || '']),
    ));

    useEffect(() => {
        setChecklistNotes(Object.fromEntries(checklistItems.map((item) => [item.id, item.notes || ''])));
    }, [checklistSignature]);

    const completedItems = checklistItems.filter((item) => item.checked).length;
    const openMarkups = markups.filter((markup) => markup.status !== 'resolved').length;

    const createMarkup = (event) => {
        event.preventDefault();

        router.post(route('tenant.projects.markups.store', [tenant.slug, version.id]), {
            ...markupForm,
            assigned_to_id: markupForm.assigned_to_id || null,
            due_date: markupForm.due_date || null,
            viewer_state: captureViewerState(),
            markup_payload: {
                source: 'aps_viewer',
                document_code: version.document.code,
            },
        }, {
            preserveScroll: true,
            onSuccess: () => setMarkupForm({
                title: '',
                description: '',
                assigned_to_id: '',
                priority: 'normal',
                due_date: '',
            }),
        });
    };

    const updateMarkup = (markup, payload) => {
        router.patch(route('tenant.projects.markups.update', [tenant.slug, markup.id]), payload, {
            preserveScroll: true,
            preserveState: true,
        });
    };

    const deleteMarkup = (markup) => {
        router.delete(route('tenant.projects.markups.destroy', [tenant.slug, markup.id]), {
            preserveScroll: true,
            preserveState: true,
        });
    };

    const updateChecklistItem = (item, payload) => {
        router.patch(route('tenant.projects.checklist-items.update', [tenant.slug, item.id]), {
            checked: payload.checked ?? item.checked,
            notes: payload.notes ?? checklistNotes[item.id] ?? '',
        }, {
            preserveScroll: true,
            preserveState: true,
        });
    };

    return (
        <aside className="sig-review-panel">
            <div className="border-b border-[var(--border)] p-4">
                <div className="flex items-center justify-between gap-3">
                    <div>
                        <span className="eyebrow">Revisao do projeto</span>
                        <h2 className="mt-1 text-base font-semibold text-[var(--ink-900)]">
                            Marcacoes e checklist
                        </h2>
                    </div>
                    <span className="sig-pill sig-pill-blue">{version.revision}</span>
                </div>

                <div className="mt-4 grid grid-cols-2 gap-2">
                    <Metric label="Marcacoes abertas" value={openMarkups} />
                    <Metric label="Checklist" value={`${completedItems}/${checklistItems.length}`} />
                </div>
            </div>

            {canReviewProjects ? (
                <form onSubmit={createMarkup} className="grid gap-3 border-b border-[var(--border)] p-4">
                    <div className="flex items-center gap-2 text-[var(--ink-500)]">
                        <MessageSquarePlus size={15} />
                        <span className="eyebrow">Nova marcacao</span>
                    </div>
                    <label>
                        <span className="eyebrow mb-1 block">Titulo</span>
                        <span className="sig-input bg-white">
                            <input
                                value={markupForm.title}
                                onChange={(event) => setMarkupForm((current) => ({ ...current, title: event.target.value }))}
                                placeholder="Ex: Ajustar detalhe do eixo"
                                required
                            />
                        </span>
                    </label>
                    <label>
                        <span className="eyebrow mb-1 block">Descricao</span>
                        <textarea
                            value={markupForm.description}
                            onChange={(event) => setMarkupForm((current) => ({ ...current, description: event.target.value }))}
                            placeholder="Descreva o ponto de revisao"
                            rows={3}
                            className="w-full rounded-lg border border-[var(--border)] px-3 py-2 text-sm outline-none focus:border-[var(--primary)]"
                        />
                    </label>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <label>
                            <span className="eyebrow mb-1 block">Responsavel</span>
                            <span className="sig-input bg-white">
                                <select
                                    value={markupForm.assigned_to_id}
                                    onChange={(event) => setMarkupForm((current) => ({ ...current, assigned_to_id: event.target.value }))}
                                >
                                    <option value="">Sem responsavel</option>
                                    {contractUsers.map((user) => (
                                        <option key={user.id} value={user.id}>{userLabel(user)}</option>
                                    ))}
                                </select>
                            </span>
                        </label>
                        <label>
                            <span className="eyebrow mb-1 block">Prioridade</span>
                            <span className="sig-input bg-white">
                                <select
                                    value={markupForm.priority}
                                    onChange={(event) => setMarkupForm((current) => ({ ...current, priority: event.target.value }))}
                                >
                                    {Object.entries(priorityLabels).map(([value, label]) => (
                                        <option key={value} value={value}>{label}</option>
                                    ))}
                                </select>
                            </span>
                        </label>
                    </div>
                    <label>
                        <span className="eyebrow mb-1 flex items-center gap-1">
                            <Calendar size={12} />
                            Prazo
                        </span>
                        <span className="sig-input bg-white">
                            <input
                                type="date"
                                value={markupForm.due_date}
                                onChange={(event) => setMarkupForm((current) => ({ ...current, due_date: event.target.value }))}
                            />
                        </span>
                    </label>
                    <button type="submit" className="sig-btn sig-btn-primary">
                        <MapPin size={15} />
                        Salvar posicao atual
                    </button>
                </form>
            ) : (
                <div className="border-b border-[var(--border)] bg-[var(--surface-muted)] p-4 text-sm text-[var(--ink-500)]">
                    Voce esta visualizando as marcacoes em modo leitura.
                </div>
            )}

            <section className="border-b border-[var(--border)] p-4">
                <div className="mb-3 flex items-center gap-2 text-[var(--ink-500)]">
                    <MapPin size={15} />
                    <span className="eyebrow">Marcacoes</span>
                </div>

                {markups.length > 0 ? (
                    <div className="grid gap-3">
                        {markups.map((markup) => (
                            <article key={markup.id} className="rounded-lg border border-[var(--border)] bg-white p-3">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className={`sig-pill ${priorityClasses[markup.priority] || 'sig-pill-blue'}`}>
                                                {priorityLabels[markup.priority] || markup.priority}
                                            </span>
                                            <span className="sig-pill sig-pill-muted">
                                                {markupStatusLabels[markup.status] || markup.status}
                                            </span>
                                        </div>
                                        <h3 className="mt-2 text-sm font-semibold text-[var(--ink-900)]">{markup.title}</h3>
                                    </div>
                                    <button
                                        type="button"
                                        className="sig-btn sig-btn-secondary sig-btn-sm !px-2"
                                        title="Ver marcacao"
                                        aria-label="Ver marcacao"
                                        onClick={() => restoreViewerState(markup.viewer_state)}
                                    >
                                        <Eye size={13} />
                                    </button>
                                </div>

                                {markup.description && (
                                    <p className="mt-2 whitespace-pre-line text-sm text-[var(--ink-500)]">{markup.description}</p>
                                )}

                                <div className="mt-3 grid gap-2 text-xs text-[var(--ink-500)]">
                                    <span className="flex items-center gap-1">
                                        <UserRound size={13} />
                                        {userLabel(markup.assignee)}
                                    </span>
                                    <span className="flex items-center gap-1">
                                        <Calendar size={13} />
                                        {formatDate(markup.due_date)}
                                    </span>
                                </div>

                                {canReviewProjects && (
                                    <div className="mt-3 grid gap-2">
                                        <div className="grid grid-cols-[1fr_auto] gap-2">
                                            <span className="sig-input bg-white">
                                                <select
                                                    value={markup.status}
                                                    onChange={(event) => updateMarkup(markup, { status: event.target.value })}
                                                >
                                                    {Object.entries(markupStatusLabels).map(([value, label]) => (
                                                        <option key={value} value={value}>{label}</option>
                                                    ))}
                                                </select>
                                            </span>
                                            <ConfirmActionButton
                                                title="Remover marcacao"
                                                message={`Deseja mesmo remover a marcacao "${markup.title}"? O registro saira desta revisao.`}
                                                confirmLabel="Remover"
                                                className="sig-btn sig-btn-secondary sig-btn-sm text-[var(--red)]"
                                                onConfirm={() => deleteMarkup(markup)}
                                            >
                                                <Trash2 size={13} />
                                            </ConfirmActionButton>
                                        </div>
                                        <span className="sig-input bg-white">
                                            <select
                                                value={markup.assigned_to_id || ''}
                                                onChange={(event) => updateMarkup(markup, { assigned_to_id: event.target.value || null })}
                                            >
                                                <option value="">Sem responsavel</option>
                                                {contractUsers.map((user) => (
                                                    <option key={user.id} value={user.id}>{userLabel(user)}</option>
                                                ))}
                                            </select>
                                        </span>
                                    </div>
                                )}
                            </article>
                        ))}
                    </div>
                ) : (
                    <div className="rounded-lg border border-dashed border-[var(--border)] bg-[var(--surface-muted)] p-4 text-sm text-[var(--ink-500)]">
                        Nenhuma marcacao registrada nesta versao.
                    </div>
                )}
            </section>

            <section className="p-4">
                <div className="mb-3 flex items-center gap-2 text-[var(--ink-500)]">
                    <CheckSquare size={15} />
                    <span className="eyebrow">Checklist de analise</span>
                </div>

                {checklistItems.length > 0 ? (
                    <div className="grid gap-3">
                        {checklistItems.map((item) => (
                            <article key={item.id} className="rounded-lg border border-[var(--border)] bg-white p-3">
                                <label className="flex items-start gap-3">
                                    <input
                                        type="checkbox"
                                        className="mt-1 h-4 w-4 rounded border-[var(--border)]"
                                        checked={item.checked}
                                        disabled={!canReviewProjects}
                                        onChange={(event) => updateChecklistItem(item, { checked: event.target.checked })}
                                    />
                                    <span className="min-w-0 flex-1">
                                        <span className="block text-sm font-semibold text-[var(--ink-900)]">{item.label}</span>
                                        {item.checked_by && (
                                            <span className="mt-1 block text-xs text-[var(--ink-500)]">
                                                Concluido por {item.checked_by.name}
                                            </span>
                                        )}
                                    </span>
                                    {item.checked && <CheckCircle2 size={17} className="text-[var(--green)]" />}
                                </label>

                                <textarea
                                    value={checklistNotes[item.id] || ''}
                                    disabled={!canReviewProjects}
                                    onChange={(event) => setChecklistNotes((current) => ({ ...current, [item.id]: event.target.value }))}
                                    onBlur={() => updateChecklistItem(item, { notes: checklistNotes[item.id] || '' })}
                                    placeholder="Observacao do item"
                                    rows={2}
                                    className="mt-3 w-full rounded-lg border border-[var(--border)] px-3 py-2 text-sm outline-none focus:border-[var(--primary)] disabled:bg-[var(--surface-muted)]"
                                />
                            </article>
                        ))}
                    </div>
                ) : (
                    <div className="rounded-lg border border-dashed border-[var(--border)] bg-[var(--surface-muted)] p-4 text-sm text-[var(--ink-500)]">
                        Checklist ainda nao iniciado.
                    </div>
                )}
            </section>
        </aside>
    );
}

function Metric({ label, value }) {
    return (
        <div className="rounded-lg border border-[var(--border)] bg-[var(--surface-muted)] p-3">
            <div className="eyebrow">{label}</div>
            <div className="mt-1 text-lg font-semibold text-[var(--ink-900)]">{value}</div>
        </div>
    );
}
