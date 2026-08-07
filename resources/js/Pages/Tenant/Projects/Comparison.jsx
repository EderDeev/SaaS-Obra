import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { projectEap } from '@/Utils/projectEap';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Columns2, ExternalLink, Link2, Unlink } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

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

function fileName(version) {
    return version.stored_name || version.original_name || 'Arquivo sem nome';
}

function loadModel(viewer, version) {
    return new Promise((resolve, reject) => {
        window.Autodesk.Viewing.Document.load(
            `urn:${version.aps_urn}`,
            (document) => {
                const viewable = document.getRoot().getDefaultGeometry();
                let completed = false;
                let fallbackTimeout = null;

                if (!viewable) {
                    reject(new Error('O arquivo não possui uma vista compatível com o Autodesk Viewer.'));
                    return;
                }

                const finishInitialFit = () => {
                    if (completed) {
                        return;
                    }

                    completed = true;
                    window.clearTimeout(fallbackTimeout);
                    viewer.removeEventListener(
                        window.Autodesk.Viewing.GEOMETRY_LOADED_EVENT,
                        finishInitialFit,
                    );
                    viewer.resize();
                    viewer.fitToView();
                    resolve();
                };

                viewer.addEventListener(
                    window.Autodesk.Viewing.GEOMETRY_LOADED_EVENT,
                    finishInitialFit,
                );

                Promise.resolve(viewer.loadDocumentNode(document, viewable))
                    .then(() => {
                        if (!completed) {
                            fallbackTimeout = window.setTimeout(finishInitialFit, 3000);
                        }
                    })
                    .catch((error) => {
                        viewer.removeEventListener(
                            window.Autodesk.Viewing.GEOMETRY_LOADED_EVENT,
                            finishInitialFit,
                        );
                        reject(error);
                    });
            },
            reject,
        );
    });
}

export default function Comparison({ tenant, baseVersion, currentVersion, apsViewerApi = 'streamingV2' }) {
    const baseContainerRef = useRef(null);
    const currentContainerRef = useRef(null);
    const baseViewerRef = useRef(null);
    const currentViewerRef = useRef(null);
    const syncingRef = useRef(false);
    const syncEnabledRef = useRef(true);
    const [syncEnabled, setSyncEnabled] = useState(true);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);

    useEffect(() => {
        syncEnabledRef.current = syncEnabled;
    }, [syncEnabled]);

    useEffect(() => {
        let cancelled = false;
        let resizeObserver = null;
        let syncFromBase = null;
        let syncFromCurrent = null;

        const synchronizeCamera = (sourceViewer, targetViewer) => {
            if (!syncEnabledRef.current || syncingRef.current || !sourceViewer || !targetViewer) {
                return;
            }

            syncingRef.current = true;

            try {
                const navigation = sourceViewer.navigation;
                const targetNavigation = targetViewer.navigation;

                targetNavigation.setView(navigation.getPosition(), navigation.getTarget());
                targetNavigation.setCameraUpVector(navigation.getCameraUpVector());
                targetNavigation.setPivotPoint(navigation.getPivotPoint());
            } finally {
                window.requestAnimationFrame(() => {
                    syncingRef.current = false;
                });
            }
        };

        loadViewerAssets()
            .then(() => {
                if (cancelled || !baseContainerRef.current || !currentContainerRef.current) {
                    return;
                }

                const options = {
                    env: 'AutodeskProduction2',
                    api: apsViewerApi,
                    getAccessToken: async (callback) => {
                        const response = await fetch(route('tenant.projects.viewer-token', tenant.slug), {
                            headers: { Accept: 'application/json' },
                        });
                        const token = await response.json();

                        callback(token.access_token, token.expires_in);
                    },
                };

                window.Autodesk.Viewing.Initializer(options, () => {
                    if (cancelled) {
                        return;
                    }

                    const baseViewer = new window.Autodesk.Viewing.GuiViewer3D(baseContainerRef.current);
                    const currentViewer = new window.Autodesk.Viewing.GuiViewer3D(currentContainerRef.current);
                    baseViewer.start();
                    currentViewer.start();
                    baseViewerRef.current = baseViewer;
                    currentViewerRef.current = currentViewer;

                    Promise.all([
                        loadModel(baseViewer, baseVersion),
                        loadModel(currentViewer, currentVersion),
                    ]).then(() => {
                        if (cancelled) {
                            return;
                        }

                        syncFromBase = () => synchronizeCamera(baseViewer, currentViewer);
                        syncFromCurrent = () => synchronizeCamera(currentViewer, baseViewer);
                        baseViewer.addEventListener(window.Autodesk.Viewing.CAMERA_CHANGE_EVENT, syncFromBase);
                        currentViewer.addEventListener(window.Autodesk.Viewing.CAMERA_CHANGE_EVENT, syncFromCurrent);
                        setLoading(false);
                    }).catch(() => {
                        if (!cancelled) {
                            setError('Nao foi possivel carregar uma das revisoes no Autodesk Viewer.');
                            setLoading(false);
                        }
                    });

                    if (window.ResizeObserver) {
                        resizeObserver = new window.ResizeObserver(() => {
                            baseViewer.resize();
                            currentViewer.resize();
                        });
                        resizeObserver.observe(baseContainerRef.current);
                        resizeObserver.observe(currentContainerRef.current);
                    }
                });
            })
            .catch(() => {
                if (!cancelled) {
                    setError('Nao foi possivel carregar a biblioteca do Autodesk Viewer.');
                    setLoading(false);
                }
            });

        return () => {
            cancelled = true;
            resizeObserver?.disconnect();

            if (baseViewerRef.current) {
                if (syncFromBase && window.Autodesk?.Viewing) {
                    baseViewerRef.current.removeEventListener(window.Autodesk.Viewing.CAMERA_CHANGE_EVENT, syncFromBase);
                }
                baseViewerRef.current.finish();
                baseViewerRef.current = null;
            }

            if (currentViewerRef.current) {
                if (syncFromCurrent && window.Autodesk?.Viewing) {
                    currentViewerRef.current.removeEventListener(window.Autodesk.Viewing.CAMERA_CHANGE_EVENT, syncFromCurrent);
                }
                currentViewerRef.current.finish();
                currentViewerRef.current = null;
            }
        };
    }, [apsViewerApi, baseVersion, currentVersion, tenant.slug]);

    const toggleSynchronization = () => setSyncEnabled((enabled) => !enabled);

    return (
        <AuthenticatedLayout>
            <Head title={`Comparar ${baseVersion.revision} e ${currentVersion.revision}`} />

            <section className="sig-content grid gap-4">
                <header className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex min-w-0 items-center gap-3">
                        <Link href={route('tenant.projects.revisions.index', tenant.slug)} className="sig-btn sig-btn-secondary !min-h-9 !px-2" title="Voltar para projetos revisados">
                            <ArrowLeft size={17} />
                        </Link>
                        <div className="min-w-0">
                            <div className="flex items-center gap-2 text-[var(--ink-500)]">
                                <Columns2 size={15} />
                                <span className="eyebrow">Comparacao de revisoes</span>
                            </div>
                            <h1 className="mono mt-1 break-all text-xl font-bold text-[var(--primary)]">{projectEap(currentVersion.document, currentVersion)}</h1>
                            <p className="mt-1 break-all text-sm font-medium text-[var(--ink-700)]">{currentVersion.original_name || fileName(currentVersion)}</p>
                            <p className="mt-1 text-xs text-[var(--ink-500)]">{currentVersion.document.title}</p>
                        </div>
                    </div>
                    <button type="button" className={`sig-btn ${syncEnabled ? 'sig-btn-primary' : 'sig-btn-secondary'}`} onClick={toggleSynchronization}>
                        {syncEnabled ? <Link2 size={16} /> : <Unlink size={16} />}
                        {syncEnabled ? 'Visoes sincronizadas' : 'Sincronizar visoes'}
                    </button>
                </header>

                <div className="relative grid min-h-[620px] overflow-hidden rounded-lg border border-[var(--border)] bg-white lg:h-[calc(100vh-210px)] lg:grid-cols-2">
                    <VersionPanel tenant={tenant} label="Revisao anterior" version={baseVersion} containerRef={baseContainerRef} />
                    <VersionPanel tenant={tenant} label="Revisao atual" version={currentVersion} containerRef={currentContainerRef} current />

                    {(loading || error) && (
                        <div className="pointer-events-none absolute inset-x-0 top-1/2 z-10 flex justify-center px-4">
                            <div className={`rounded-lg border px-4 py-3 text-sm font-semibold shadow-lg ${error ? 'border-red-200 bg-red-50 text-red-700' : 'border-[var(--border)] bg-white text-[var(--ink-700)]'}`}>
                                {error || 'Carregando as duas revisoes...'}
                            </div>
                        </div>
                    )}
                </div>
            </section>
        </AuthenticatedLayout>
    );
}

function VersionPanel({ tenant, label, version, containerRef, current = false }) {
    return (
        <section className={`relative flex min-h-[520px] min-w-0 flex-col ${current ? 'border-t border-[var(--border)] lg:border-l lg:border-t-0' : ''}`}>
            <header className="flex flex-wrap items-center justify-between gap-2 border-b border-[var(--border)] bg-[var(--surface-muted)] px-4 py-3">
                <div className="min-w-0">
                    <div className="eyebrow">{label}</div>
                    <div className="mt-1 flex flex-wrap items-center gap-2">
                        <span className={`sig-pill ${current ? 'sig-pill-blue' : 'sig-pill-muted'} font-semibold`}>{version.revision}</span>
                        <span className="max-w-[420px] truncate text-sm font-semibold text-[var(--ink-900)]">{fileName(version)}</span>
                    </div>
                </div>
                <Link href={`${route('tenant.projects.viewer', [tenant.slug, version.id])}?workspace=view`} className="sig-btn sig-btn-secondary sig-btn-sm" target="_blank">
                    <ExternalLink size={13} />
                    Abrir
                </Link>
            </header>
            <div ref={containerRef} className="min-h-0 flex-1 bg-[#eef2f7]" />
        </section>
    );
}
