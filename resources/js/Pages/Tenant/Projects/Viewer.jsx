import ConfirmActionButton from '@/Components/ConfirmActionButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowLeft,
    CheckCircle2,
    CheckSquare,
    Download,
    Eye,
    EyeOff,
    Loader2,
    MapPin,
    MessageSquarePlus,
    PanelRightClose,
    PanelRightOpen,
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
    ready: 'Pronto para visualização',
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
    critica: 'Crítica',
};

const priorityClasses = {
    baixa: 'sig-pill-green',
    normal: 'sig-pill-blue',
    alta: 'sig-pill-amber',
    critica: 'sig-pill-red',
};

const markupColorOptions = [
    { label: 'Vermelho', value: '#ef3340' },
    { label: 'Azul', value: '#0b5fff' },
    { label: 'Verde', value: '#0f9d63' },
    { label: 'Amarelo', value: '#f2b705' },
    { label: 'Preto', value: '#111827' },
    { label: 'Branco', value: '#ffffff' },
];

const defaultMarkupStyle = {
    color: '#ef3340',
    strokeWidth: 0.02,
    fontSize: 0.12,
    fillEnabled: false,
};

const strokeWidthRange = {
    min: 0.001,
    max: 0.5,
};

const fontSizeRange = {
    min: 0.005,
    max: 2,
};

const coreMarkupTools = {
    cloud: {
        label: 'Nuvem',
        className: 'EditModeCloud',
    },
    arrow: {
        label: 'Seta',
        className: 'EditModeArrow',
    },
    rectangle: {
        label: 'Retângulo',
        className: 'EditModeRectangle',
    },
    freehand: {
        label: 'Livre',
        className: 'EditModeFreehand',
    },
    text: {
        label: 'Texto',
        className: 'EditModeText',
    },
};

function fileDisplayName(version) {
    return version?.stored_name || version?.original_name || '';
}

function userLabel(user) {
    return user ? `${user.name} (${user.email})` : 'Sem responsavel';
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

function numberOrNull(value) {
    const number = Number(value);

    return Number.isFinite(number) ? number : null;
}

function clamp(value, min, max) {
    return Math.min(Math.max(value, min), max);
}

function normalizeStrokeWidth(value) {
    const width = Number(value);

    return Number.isFinite(width) ? clamp(width, strokeWidthRange.min, strokeWidthRange.max) : defaultMarkupStyle.strokeWidth;
}

function normalizeFontSize(value) {
    const size = Number(value);

    return Number.isFinite(size) ? clamp(size, fontSizeRange.min, fontSizeRange.max) : defaultMarkupStyle.fontSize;
}

function formatMarkupScale(value) {
    const number = Number(value);

    return number < 0.01 ? number.toFixed(4) : number.toFixed(3);
}

function scaleToSlider(value, range) {
    const safeValue = clamp(Number(value), range.min, range.max);
    const min = Math.log10(range.min);
    const max = Math.log10(range.max);

    return ((Math.log10(safeValue) - min) / (max - min)) * 100;
}

function sliderToScale(value, range) {
    const position = clamp(Number(value), 0, 100);
    const min = Math.log10(range.min);
    const max = Math.log10(range.max);

    return 10 ** (min + (position / 100) * (max - min));
}

function markupVisualAnchor(markup) {
    return markup?.markup_payload?.visual_anchor || markup?.markup_payload?.position || null;
}

function markupCoreSvg(markup) {
    const svg = markup?.markup_payload?.markups_core_svg || markup?.markup_payload?.svg;

    return typeof svg === 'string' && svg.trim() ? svg : null;
}

function markupLayerId(markup) {
    return `deming-project-markup-${markup.id}`;
}

function technicalErrorMessage(error) {
    return error instanceof Error && error.message ? ` Detalhe: ${error.message}` : '';
}

function parseSvgNumberList(value) {
    return String(value || '')
        .match(/-?\d*\.?\d+(?:e[-+]?\d+)?/gi)
        ?.map(Number)
        .filter(Number.isFinite) || [];
}

function parseSvgLength(value, fallback) {
    if (typeof value !== 'string' || value.includes('%')) {
        return fallback;
    }

    const number = Number.parseFloat(value);

    return Number.isFinite(number) ? number : fallback;
}

function svgPointToViewport(point, viewBox, width, height) {
    return {
        x: clamp((point.x - viewBox.x) / viewBox.width, 0, 1) * width,
        y: clamp((point.y - viewBox.y) / viewBox.height, 0, 1) * height,
    };
}

function extractSvgViewportCandidates(svg, container) {
    if (typeof window === 'undefined' || !container || typeof svg !== 'string' || !svg.trim()) {
        return [];
    }

    try {
        const documentNode = new window.DOMParser().parseFromString(svg, 'image/svg+xml');
        const svgElement = documentNode.querySelector('svg');

        if (!svgElement || documentNode.querySelector('parsererror')) {
            return [];
        }

        const width = parseSvgLength(svgElement.getAttribute('width'), container.clientWidth || 1);
        const height = parseSvgLength(svgElement.getAttribute('height'), container.clientHeight || 1);
        const viewBoxNumbers = parseSvgNumberList(svgElement.getAttribute('viewBox'));
        const viewBox = viewBoxNumbers.length >= 4
            ? { x: viewBoxNumbers[0], y: viewBoxNumbers[1], width: viewBoxNumbers[2], height: viewBoxNumbers[3] }
            : { x: 0, y: 0, width, height };
        const points = [];

        documentNode.querySelectorAll('*').forEach((element) => {
            const tagName = element.tagName.toLowerCase();
            const addPoint = (x, y) => {
                const point = { x: Number(x), y: Number(y) };

                if (Number.isFinite(point.x) && Number.isFinite(point.y)) {
                    points.push(point);
                }
            };

            if (element.hasAttribute('x') && element.hasAttribute('y')) {
                const x = Number.parseFloat(element.getAttribute('x'));
                const y = Number.parseFloat(element.getAttribute('y'));

                if (tagName === 'rect' && element.hasAttribute('width') && element.hasAttribute('height')) {
                    addPoint(x + Number.parseFloat(element.getAttribute('width')) / 2, y + Number.parseFloat(element.getAttribute('height')) / 2);
                } else {
                    addPoint(x, y);
                }
            }

            if (element.hasAttribute('x1') && element.hasAttribute('y1')) {
                addPoint(element.getAttribute('x1'), element.getAttribute('y1'));
            }

            if (element.hasAttribute('x2') && element.hasAttribute('y2')) {
                addPoint(element.getAttribute('x2'), element.getAttribute('y2'));
            }

            if (element.hasAttribute('cx') && element.hasAttribute('cy')) {
                addPoint(element.getAttribute('cx'), element.getAttribute('cy'));
            }

            if (element.hasAttribute('points')) {
                const numbers = parseSvgNumberList(element.getAttribute('points'));

                for (let index = 0; index < numbers.length - 1; index += 2) {
                    addPoint(numbers[index], numbers[index + 1]);
                }
            }

            if (element.hasAttribute('d')) {
                const numbers = parseSvgNumberList(element.getAttribute('d'));

                for (let index = 0; index < numbers.length - 1; index += 2) {
                    addPoint(numbers[index], numbers[index + 1]);
                }
            }
        });

        if (!points.length) {
            return [{ x: width / 2, y: height / 2 }];
        }

        const xs = points.map((point) => point.x);
        const ys = points.map((point) => point.y);
        const bboxCenter = {
            x: (Math.min(...xs) + Math.max(...xs)) / 2,
            y: (Math.min(...ys) + Math.max(...ys)) / 2,
        };
        const unique = [bboxCenter, ...points]
            .map((point) => svgPointToViewport(point, viewBox, width, height))
            .filter((point, index, list) => list.findIndex((candidate) => (
                Math.abs(candidate.x - point.x) < 1 && Math.abs(candidate.y - point.y) < 1
            )) === index);

        return unique.slice(0, 24);
    } catch {
        return [];
    }
}

function plainVector(point) {
    return {
        x: Number(point.x),
        y: Number(point.y),
        z: Number(point.z),
    };
}

function validBox(box) {
    return box
        && Number.isFinite(box.min?.x)
        && Number.isFinite(box.min?.y)
        && Number.isFinite(box.min?.z)
        && Number.isFinite(box.max?.x)
        && Number.isFinite(box.max?.y)
        && Number.isFinite(box.max?.z)
        && box.max.x >= box.min.x
        && box.max.y >= box.min.y
        && box.max.z >= box.min.z;
}

function dbIdWorldBounds(viewer, dbId) {
    const model = viewer?.model;
    const instanceTree = model?.getInstanceTree?.();
    const fragmentList = model?.getFragmentList?.();
    const Box3 = window.THREE?.Box3;

    if (!instanceTree || !fragmentList || !Box3 || !Number.isFinite(Number(dbId))) {
        return null;
    }

    const bounds = new Box3();
    let hasBounds = false;

    try {
        instanceTree.enumNodeFragments(Number(dbId), (fragId) => {
            const fragmentBounds = new Box3();

            fragmentList.getWorldBounds(fragId, fragmentBounds);

            if (validBox(fragmentBounds)) {
                if (hasBounds) {
                    bounds.union(fragmentBounds);
                } else {
                    bounds.copy(fragmentBounds);
                    hasBounds = true;
                }
            }
        }, true);
    } catch {
        return null;
    }

    return hasBounds && validBox(bounds) ? bounds : null;
}

function dbIdDisplayName(viewer, dbId) {
    try {
        const name = viewer?.model?.getInstanceTree?.()?.getNodeName?.(Number(dbId));

        return typeof name === 'string' && name.trim() ? name.trim() : null;
    } catch {
        return null;
    }
}

function markupObjectName(markup) {
    const anchor = markupVisualAnchor(markup);

    if (!anchor?.dbId) {
        return null;
    }

    return anchor.name || anchor.object_name || `Objeto #${anchor.dbId}`;
}

function calculateMarkupPinPositions(markups, viewer, container) {
    if (!container || typeof window === 'undefined') {
        return {};
    }

    const rect = container.getBoundingClientRect();

    if (!rect.width || !rect.height) {
        return {};
    }

    return Object.fromEntries(markups.map((markup) => {
        const anchor = markupVisualAnchor(markup);
        const viewport = anchor?.viewport || { x: 0.5, y: 0.5 };
        let left = numberOrNull(viewport.x) !== null ? numberOrNull(viewport.x) * rect.width : rect.width / 2;
        let top = numberOrNull(viewport.y) !== null ? numberOrNull(viewport.y) * rect.height : rect.height / 2;

        let projectedPoint = anchor?.point || null;

        if (anchor?.type === 'object' && anchor.dbId && !projectedPoint) {
            const objectBounds = dbIdWorldBounds(viewer, anchor.dbId);

            if (objectBounds && window.THREE?.Vector3) {
                projectedPoint = plainVector(objectBounds.getCenter(new window.THREE.Vector3()));
            }
        }

        if ((anchor?.type === 'world' || anchor?.type === 'object') && projectedPoint && viewer?.worldToClient && window.THREE?.Vector3) {
            try {
                const point = new window.THREE.Vector3(
                    Number(projectedPoint.x),
                    Number(projectedPoint.y),
                    Number(projectedPoint.z),
                );
                const client = viewer.worldToClient(point);

                if (Number.isFinite(client?.x) && Number.isFinite(client?.y)) {
                    left = client.x;
                    top = client.y;
                }
            } catch {
                // Mantém a posição normalizada caso o viewer não consiga projetar o ponto 3D.
            }
        }

        const offscreen = left < -24 || top < -24 || left > rect.width + 24 || top > rect.height + 24;

        return [markup.id, {
            left: clamp(left, 16, Math.max(16, rect.width - 16)),
            top: clamp(top, 16, Math.max(16, rect.height - 16)),
            offscreen,
        }];
    }));
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
    workspaceMode = 'view',
    showCommentsPanel = false,
    showChecklistPanel = false,
}) {
    const containerRef = useRef(null);
    const viewerRef = useRef(null);
    const overlayFrameRef = useRef(null);
    const markupsCoreRef = useRef(null);
    const loadedMarkupLayerIdsRef = useRef([]);
    const coreRenderRequestRef = useRef(0);
    const coreDraftLayerRef = useRef(null);
    const selectedObjectAnchorRef = useRef(null);
    const draftObjectAnchorRef = useRef(null);
    const lastObjectHitAnchorRef = useRef(null);
    const markupsCoreEditingRef = useRef(false);
    const [currentStatus, setCurrentStatus] = useState(version.derivative_status);
    const [progress, setProgress] = useState(null);
    const [error, setError] = useState(null);
    const [reviewPanelCollapsed, setReviewPanelCollapsed] = useState(false);
    const [viewerOverlayTick, setViewerOverlayTick] = useState(0);
    const [selectedMarkupId, setSelectedMarkupId] = useState(null);
    const [allMarkupsVisible, setAllMarkupsVisible] = useState(false);
    const [markupsCoreReady, setMarkupsCoreReady] = useState(false);
    const [markupsCoreEditing, setMarkupsCoreEditing] = useState(false);
    const [markupsCoreError, setMarkupsCoreError] = useState(null);
    const [activeMarkupTool, setActiveMarkupTool] = useState('cloud');
    const [markupStyle, setMarkupStyle] = useState(defaultMarkupStyle);
    const [selectedObjectAnchor, setSelectedObjectAnchor] = useState(null);

    const isReady = currentStatus === 'ready' && version.aps_urn;
    const displayName = fileDisplayName(version);
    const visibleMarkups = useMemo(
        () => (showCommentsPanel ? reviewMarkups : []),
        [reviewMarkups, showCommentsPanel],
    );
    const visualizedMarkups = useMemo(() => {
        if (allMarkupsVisible) {
            return visibleMarkups;
        }

        if (selectedMarkupId) {
            return visibleMarkups.filter((markup) => markup.id === selectedMarkupId);
        }

        return [];
    }, [allMarkupsVisible, selectedMarkupId, visibleMarkups]);
    const visibleOverlayMarkups = useMemo(
        () => (allMarkupsVisible
            ? visualizedMarkups
            : visualizedMarkups.filter((markup) => !markupCoreSvg(markup))),
        [allMarkupsVisible, visualizedMarkups],
    );

    const processAps = () => {
        router.post(route('tenant.projects.process-aps', [tenant.slug, version.id]));
    };

    useEffect(() => {
        document.body.classList.add('sig-viewer-body');

        return () => {
            document.body.classList.remove('sig-viewer-body');
        };
    }, []);

    useEffect(() => {
        markupsCoreEditingRef.current = markupsCoreEditing;
    }, [markupsCoreEditing]);

    const requestViewerOverlayUpdate = () => {
        if (typeof window === 'undefined' || overlayFrameRef.current) {
            return;
        }

        overlayFrameRef.current = window.requestAnimationFrame(() => {
            overlayFrameRef.current = null;
            setViewerOverlayTick((tick) => tick + 1);
        });
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

    const restoreViewerNavigation = ({ hideMarkups = false } = {}) => {
        const markupsCore = markupsCoreRef.current;
        const viewer = viewerRef.current;

        try {
            markupsCore?.leaveEditMode?.();
            markupsCore?.allowNavigation?.(true);
            markupsCore?.disableMarkupInteractions?.(true);

            if (hideMarkups) {
                markupsCore?.hide?.();
            }
        } catch {
            // Se a extensao nao aceitar algum metodo, seguimos restaurando a navegacao do viewer.
        }

        window.setTimeout(() => {
            try {
                const navigationTool = viewer?.model?.is2d?.() ? 'pan' : 'orbit';

                viewer?.setDefaultNavigationTool?.(navigationTool);
                viewer?.setActiveNavigationTool?.(navigationTool);
                viewer?.toolController?.activateTool?.(navigationTool);
                viewer?.impl?.invalidate?.(true, true, true);
                viewer?.resize?.();
            } catch {
                // A restauracao de ferramenta e defensiva para manter compatibilidade entre versoes do APS Viewer.
            }
        }, 0);
    };

    const unloadCoreMarkupLayers = () => {
        const markupsCore = markupsCoreRef.current;

        if (!markupsCore) {
            return;
        }

        try {
            if (typeof markupsCore.unloadMarkupsAllLayers === 'function') {
                markupsCore.unloadMarkupsAllLayers();
            } else {
                loadedMarkupLayerIdsRef.current.forEach((layerId) => markupsCore.unloadMarkups?.(layerId));
            }
        } catch {
            // A limpeza visual nao deve bloquear a navegação do projeto.
        } finally {
            loadedMarkupLayerIdsRef.current = [];
        }
    };

    const currentSelectedDbId = () => {
        const dbId = Number(viewerRef.current?.getSelection?.()?.[0]);

        return Number.isFinite(dbId) ? dbId : null;
    };

    const objectAnchorFromHit = (result, x, y, container = containerRef.current) => {
        const viewer = viewerRef.current;
        const dbId = Number(result?.hit?.dbId);
        const point = result?.point;

        if (!viewer || !container || !Number.isFinite(dbId) || !point) {
            return null;
        }

        const rect = container.getBoundingClientRect();

        return {
            type: 'object',
            dbId,
            name: dbIdDisplayName(viewer, dbId),
            source: 'clicked_object',
            point: plainVector(point),
            viewport: {
                x: rect.width ? clamp(x / rect.width, 0, 1) : 0.5,
                y: rect.height ? clamp(y / rect.height, 0, 1) : 0.5,
            },
        };
    };

    const buildSelectedObjectAnchor = (container = containerRef.current) => {
        const viewer = viewerRef.current;
        const dbId = currentSelectedDbId();

        if (!viewer || !container || !Number.isFinite(dbId)) {
            return null;
        }

        const lastHitAnchor = lastObjectHitAnchorRef.current;

        if (lastHitAnchor?.dbId === dbId) {
            return lastHitAnchor;
        }

        const objectBounds = dbIdWorldBounds(viewer, dbId);

        if (!objectBounds || !window.THREE?.Vector3) {
            return null;
        }

        const center = objectBounds.getCenter(new window.THREE.Vector3());
        const rect = container.getBoundingClientRect();
        let viewport = { x: 0.5, y: 0.5 };

        try {
            const client = viewer.worldToClient?.(center);

            if (rect.width && rect.height && Number.isFinite(client?.x) && Number.isFinite(client?.y)) {
                viewport = {
                    x: clamp(client.x / rect.width, 0, 1),
                    y: clamp(client.y / rect.height, 0, 1),
                };
            }
        } catch {
            // A posição mundial do objeto continua válida mesmo sem projeção 2D.
        }

        return {
            type: 'object',
            dbId,
            name: dbIdDisplayName(viewer, dbId),
            source: 'selected_object_center',
            point: plainVector(center),
            viewport,
        };
    };

    const refreshSelectedObjectAnchor = () => {
        const anchor = buildSelectedObjectAnchor();

        selectedObjectAnchorRef.current = anchor;
        setSelectedObjectAnchor(anchor);

        return anchor;
    };

    const hitTestViewerPoint = (x, y) => {
        const viewer = viewerRef.current;

        if (!viewer) {
            return null;
        }

        try {
            const hit = viewer.clientToWorld?.(x, y, true)
                || viewer.impl?.hitTest?.(x, y, true);
            const point = hit?.point || hit?.intersectPoint;

            return point ? { hit, point } : null;
        } catch {
            return null;
        }
    };

    const findWorldAnchorForMarkup = (coreSvg, container) => {
        const viewer = viewerRef.current;

        if (!viewer || !container) {
            return null;
        }

        const rect = container.getBoundingClientRect();
        const candidates = extractSvgViewportCandidates(coreSvg, container);
        const fallback = { x: rect.width / 2, y: rect.height / 2 };
        const searchPoints = candidates.length ? candidates : [fallback];
        const offsets = [
            [0, 0],
            [12, 0],
            [-12, 0],
            [0, 12],
            [0, -12],
            [28, 0],
            [-28, 0],
            [0, 28],
            [0, -28],
            [48, 48],
            [-48, 48],
            [48, -48],
            [-48, -48],
        ];

        for (const candidate of searchPoints) {
            for (const [offsetX, offsetY] of offsets) {
                const x = clamp(candidate.x + offsetX, 0, rect.width);
                const y = clamp(candidate.y + offsetY, 0, rect.height);
                const result = hitTestViewerPoint(x, y);

                if (result?.point) {
                    const hitDbId = Number(result.hit?.dbId);

                    return {
                        type: 'world',
                        point: {
                            x: Number(result.point.x),
                            y: Number(result.point.y),
                            z: Number(result.point.z),
                        },
                        dbId: Number.isFinite(hitDbId) ? hitDbId : null,
                        name: Number.isFinite(hitDbId) ? dbIdDisplayName(viewer, hitDbId) : null,
                        viewport: {
                            x: rect.width ? clamp(candidate.x / rect.width, 0, 1) : 0.5,
                            y: rect.height ? clamp(candidate.y / rect.height, 0, 1) : 0.5,
                        },
                    };
                }
            }
        }

        return null;
    };

    const applyCoreMarkupStyle = (style = markupStyle) => {
        const markupsCore = markupsCoreRef.current;
        const strokeWidth = normalizeStrokeWidth(style.strokeWidth);
        const fontSize = normalizeFontSize(style.fontSize);
        const fillOpacity = style.fillEnabled ? 0.14 : 0;

        try {
            markupsCore?.setStyle?.({
                'stroke-color': style.color,
                strokeColor: style.color,
                'stroke-width': strokeWidth,
                strokeWidth,
                'stroke-opacity': 1,
                strokeOpacity: 1,
                'fill-color': style.color,
                fillColor: style.color,
                'fill-opacity': fillOpacity,
                fillOpacity,
                'font-color': style.color,
                fontColor: style.color,
                'font-size': fontSize,
                fontSize,
                color: style.color,
            });
        } catch {
            // O MarkupsCore pode ignorar estilos nao suportados pela ferramenta ativa.
        }
    };

    const showCoreMarkup = (markup) => {
        const markupsCore = markupsCoreRef.current;
        const svg = markupCoreSvg(markup);

        if (!markupsCore || !svg) {
            unloadCoreMarkupLayers();
            return;
        }

        try {
            const renderRequestId = coreRenderRequestRef.current + 1;

            coreRenderRequestRef.current = renderRequestId;

            if (markup.viewer_state && viewerRef.current?.restoreState) {
                viewerRef.current.restoreState(markup.viewer_state, null, true);
            }

            window.setTimeout(() => {
                if (coreRenderRequestRef.current !== renderRequestId) {
                    return;
                }

                try {
                    unloadCoreMarkupLayers();
                    markupsCore.show?.();
                    markupsCore.loadMarkups(svg, markupLayerId(markup));
                    loadedMarkupLayerIdsRef.current = [markupLayerId(markup)];
                    restoreViewerNavigation();
                    viewerRef.current?.resize?.();
                } catch {
                    setMarkupsCoreError('Não foi possível carregar o desenho da marcação.');
                }
            }, 120);
        } catch {
            setMarkupsCoreError('Não foi possível restaurar a marcação criada no MarkupsCore.');
        }
    };

    const showAllCoreMarkups = () => {
        const markupsCore = markupsCoreRef.current;
        const svgMarkups = visibleMarkups.filter((markup) => markupCoreSvg(markup));

        if (svgMarkups.length > 0 && !markupsCore) {
            setMarkupsCoreError('MarkupsCore ainda nao esta disponivel para visualizar todos os comentarios.');
            return;
        }

        try {
            if (markupsCoreEditing) {
                cancelCoreMarkupEdit();
            }

            const renderRequestId = coreRenderRequestRef.current + 1;

            coreRenderRequestRef.current = renderRequestId;
            setSelectedMarkupId(null);
            setAllMarkupsVisible(true);
            setMarkupsCoreError(null);
            requestViewerOverlayUpdate();

            if (svgMarkups.length > 0) {
                if (svgMarkups[0].viewer_state && viewerRef.current?.restoreState) {
                    viewerRef.current.restoreState(svgMarkups[0].viewer_state, null, true);
                }

                window.setTimeout(() => {
                    if (coreRenderRequestRef.current !== renderRequestId) {
                        return;
                    }

                    const loadedLayerIds = [];

                    unloadCoreMarkupLayers();
                    markupsCore.show?.();
                    svgMarkups.forEach((markup) => {
                        const layerId = markupLayerId(markup);

                        markupsCore.loadMarkups(markupCoreSvg(markup), layerId);
                        loadedLayerIds.push(layerId);
                    });
                    loadedMarkupLayerIdsRef.current = loadedLayerIds;
                    restoreViewerNavigation();
                    requestViewerOverlayUpdate();
                }, 120);
            } else {
                unloadCoreMarkupLayers();
                restoreViewerNavigation({ hideMarkups: true });
            }
        } catch (exception) {
            setMarkupsCoreError(`Nao foi possivel visualizar todos os comentarios.${technicalErrorMessage(exception)}`);
        }
    };

    const hideAllCoreMarkups = () => {
        coreRenderRequestRef.current += 1;
        unloadCoreMarkupLayers();
        restoreViewerNavigation({ hideMarkups: true });
        setAllMarkupsVisible(false);
        setSelectedMarkupId(null);
        requestViewerOverlayUpdate();
    };

    const selectMarkupInViewer = (markup) => {
        setAllMarkupsVisible(false);
        setSelectedMarkupId(markup.id);

        if (markupCoreSvg(markup)) {
            showCoreMarkup(markup);
        } else {
            unloadCoreMarkupLayers();
            restoreViewerState(markup.viewer_state);
            restoreViewerNavigation({ hideMarkups: true });
        }

        requestViewerOverlayUpdate();
    };

    const beginCoreMarkupEdit = (tool) => {
        const markupsCore = markupsCoreRef.current;
        const coreNamespace = window.Autodesk?.Viewing?.Extensions?.Markups?.Core;
        const toolConfig = coreMarkupTools[tool] || coreMarkupTools.cloud;
        const ToolClass = coreNamespace?.[toolConfig.className];

        if (!markupsCore || !ToolClass) {
            setMarkupsCoreError('MarkupsCore ainda não está disponível neste viewer.');
            return;
        }

        try {
            if (markupsCoreEditing) {
                markupsCore.changeEditMode(new ToolClass(markupsCore));
                markupsCore.allowNavigation?.(false);
                markupsCore.disableMarkupInteractions?.(false);
                applyCoreMarkupStyle();
                setActiveMarkupTool(tool);
                setMarkupsCoreError(null);
                return;
            }

            draftObjectAnchorRef.current = refreshSelectedObjectAnchor();
            coreRenderRequestRef.current += 1;
            unloadCoreMarkupLayers();
            setAllMarkupsVisible(false);
            setSelectedMarkupId(null);

            const layerId = `demingDraft${Date.now()}`;
            coreDraftLayerRef.current = null;
            markupsCore.hide?.();
            let enteredEditMode = markupsCore.enterEditMode();

            if (enteredEditMode === false) {
                markupsCore.leaveEditMode?.();
                markupsCore.hide?.();
                enteredEditMode = markupsCore.enterEditMode();
            }

            if (enteredEditMode === false) {
                enteredEditMode = markupsCore.enterEditMode(layerId);
                coreDraftLayerRef.current = enteredEditMode === false ? null : layerId;
            }

            if (enteredEditMode === false) {
                throw new Error('MarkupsCore recusou o modo de edição.');
            }

            markupsCore.changeEditMode(new ToolClass(markupsCore));
            markupsCore.allowNavigation?.(false);
            markupsCore.disableMarkupInteractions?.(false);
            applyCoreMarkupStyle();

            setActiveMarkupTool(tool);
            setMarkupsCoreEditing(true);
            markupsCoreEditingRef.current = true;
            setMarkupsCoreError(null);
        } catch (exception) {
            setMarkupsCoreError(`Não foi possível iniciar a ferramenta de marcação.${technicalErrorMessage(exception)}`);
        }
    };

    const cancelCoreMarkupEdit = () => {
        const markupsCore = markupsCoreRef.current;

        try {
            coreRenderRequestRef.current += 1;
            markupsCore?.leaveEditMode?.();

            if (coreDraftLayerRef.current) {
                markupsCore?.unloadMarkups?.(coreDraftLayerRef.current);
            }
        } catch {
            // Se o cancelamento falhar, basta sair do estado local de edição.
        } finally {
            coreDraftLayerRef.current = null;
            draftObjectAnchorRef.current = null;
            setMarkupsCoreEditing(false);
            markupsCoreEditingRef.current = false;
            restoreViewerNavigation({ hideMarkups: true });
        }
    };

    const generateCoreMarkupData = () => {
        const markupsCore = markupsCoreRef.current;

        if (!markupsCoreEditing || !markupsCore?.generateData) {
            return null;
        }

        try {
            if (markupsCore.isUndoStackEmpty?.() === true) {
                setMarkupsCoreError('Desenhe uma marcação no projeto antes de salvar o comentário visual.');
                return null;
            }

            const svg = markupsCore.generateData();

            if (typeof svg !== 'string' || !svg.trim()) {
                setMarkupsCoreError('Desenhe uma marcação no projeto antes de salvar o comentário visual.');
                return null;
            }

            markupsCore.leaveEditMode?.();
            coreDraftLayerRef.current = null;
            setMarkupsCoreEditing(false);
            markupsCoreEditingRef.current = false;
            setMarkupsCoreError(null);
            restoreViewerNavigation();

            return svg;
        } catch (exception) {
            setMarkupsCoreError(`Não foi possível gerar os dados da marcação. Tente cancelar o desenho e iniciar a ferramenta novamente.${technicalErrorMessage(exception)}`);
            return null;
        }
    };

    const captureMarkupPayload = () => {
        const fallbackAnchor = {
            type: 'viewport',
            viewport: { x: 0.5, y: 0.5 },
        };
        const coreSvg = generateCoreMarkupData();

        if (markupsCoreEditing && !coreSvg) {
            return null;
        }

        const payload = {
            source: 'aps_viewer',
            document_code: version.document.code,
            visual_anchor: fallbackAnchor,
            markups_core_svg: coreSvg,
            markups_core_tool: coreSvg ? activeMarkupTool : null,
            markups_core_style: coreSvg ? markupStyle : null,
        };
        const viewer = viewerRef.current;
        const container = containerRef.current;

        if (!viewer || !container) {
            return payload;
        }

        const objectAnchor = (coreSvg ? draftObjectAnchorRef.current : null)
            || selectedObjectAnchorRef.current
            || buildSelectedObjectAnchor(container);
        const worldAnchor = findWorldAnchorForMarkup(coreSvg, container);

        if (objectAnchor) {
            return {
                ...payload,
                visual_anchor: {
                    ...objectAnchor,
                    source: worldAnchor ? 'selected_object_with_markup_point' : objectAnchor.source,
                    point: objectAnchor.point,
                    markupPoint: worldAnchor?.point || null,
                    hitDbId: worldAnchor?.dbId || null,
                    hitName: worldAnchor?.name || null,
                    viewport: objectAnchor.viewport,
                },
            };
        }

        if (worldAnchor) {
            return {
                ...payload,
                visual_anchor: worldAnchor,
            };
        }

        if (coreSvg) {
            setMarkupsCoreError('Não foi possível vincular a marcação a um objeto ou ponto do modelo. Selecione um objeto antes de desenhar ou desenhe sobre a geometria do projeto.');
            return null;
        }

        return payload;
    };

    const markupPinPositions = useMemo(
        () => calculateMarkupPinPositions(visibleOverlayMarkups, viewerRef.current, containerRef.current),
        [visibleOverlayMarkups, viewerOverlayTick],
    );

    useEffect(() => {
        if (selectedMarkupId && !visibleMarkups.some((markup) => markup.id === selectedMarkupId)) {
            setSelectedMarkupId(null);
            setAllMarkupsVisible(false);
            unloadCoreMarkupLayers();
            restoreViewerNavigation({ hideMarkups: true });
        }
    }, [visibleMarkups, selectedMarkupId]);

    useEffect(() => () => {
        if (overlayFrameRef.current && typeof window !== 'undefined') {
            window.cancelAnimationFrame(overlayFrameRef.current);
        }
    }, []);

    useEffect(() => {
        requestViewerOverlayUpdate();
    }, [visibleMarkups, reviewPanelCollapsed]);

    useEffect(() => {
        if (!showCommentsPanel) {
            unloadCoreMarkupLayers();
            restoreViewerNavigation({ hideMarkups: true });
            setSelectedMarkupId(null);
            setAllMarkupsVisible(false);
        }
    }, [showCommentsPanel]);

    useEffect(() => {
        if (!markupsCoreReady || !selectedMarkupId || allMarkupsVisible) {
            return;
        }

        const selectedMarkup = visibleMarkups.find((markup) => markup.id === selectedMarkupId);

        if (selectedMarkup && markupCoreSvg(selectedMarkup)) {
            showCoreMarkup(selectedMarkup);
        }
    }, [allMarkupsVisible, markupsCoreReady, selectedMarkupId, visibleMarkups]);

    useEffect(() => {
        if (!allMarkupsVisible || !showCommentsPanel) {
            return;
        }

        if (visibleMarkups.some((markup) => markupCoreSvg(markup)) && !markupsCoreReady) {
            return;
        }

        showAllCoreMarkups();
    }, [allMarkupsVisible, markupsCoreReady, showCommentsPanel, visibleMarkups]);

    useEffect(() => {
        if (markupsCoreEditing) {
            applyCoreMarkupStyle(markupStyle);
        }
    }, [markupStyle, markupsCoreEditing]);

    const restoreViewerState = (state) => {
        if (!state || !viewerRef.current?.restoreState) {
            return;
        }

        try {
            viewerRef.current.restoreState(state, null, true);
            window.setTimeout(() => viewerRef.current?.resize(), 80);
        } catch {
            setError('Não foi possível restaurar a posição da marcação.');
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
                    throw new Error(payload.message || 'Não foi possível consultar o status APS.');
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
        let overlayEventHandler = null;
        let selectionEventHandler = null;
        let objectPointerHandler = null;
        let viewerContainer = null;
        const resizeViewer = () => {
            window.requestAnimationFrame(() => {
                if (!viewerRef.current) {
                    return;
                }

                viewerRef.current.resize();
                requestViewerOverlayUpdate();
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
                    overlayEventHandler = requestViewerOverlayUpdate;
                    selectionEventHandler = () => {
                        refreshSelectedObjectAnchor();
                        requestViewerOverlayUpdate();
                    };
                    setMarkupsCoreReady(false);
                    setMarkupsCoreError(null);

                    viewer.loadExtension('Autodesk.Viewing.MarkupsCore')
                        .then((extension) => {
                            if (cancelled) {
                                return;
                            }

                            markupsCoreRef.current = extension;
                            setMarkupsCoreReady(true);
                        })
                        .catch(() => {
                            if (!cancelled) {
                                setMarkupsCoreError('Não foi possível carregar o Autodesk MarkupsCore.');
                            }
                        });

                    viewer.addEventListener(window.Autodesk.Viewing.CAMERA_CHANGE_EVENT, overlayEventHandler);
                    viewer.addEventListener(window.Autodesk.Viewing.GEOMETRY_LOADED_EVENT, overlayEventHandler);
                    viewer.addEventListener(window.Autodesk.Viewing.SELECTION_CHANGED_EVENT, selectionEventHandler);
                    viewerContainer = containerRef.current;
                    objectPointerHandler = (event) => {
                        if (markupsCoreEditingRef.current || !viewerContainer) {
                            return;
                        }

                        const rect = viewerContainer.getBoundingClientRect();
                        const x = event.clientX - rect.left;
                        const y = event.clientY - rect.top;

                        if (x < 0 || y < 0 || x > rect.width || y > rect.height) {
                            return;
                        }

                        const anchor = objectAnchorFromHit(hitTestViewerPoint(x, y), x, y, viewerContainer);

                        if (anchor) {
                            lastObjectHitAnchorRef.current = anchor;
                            selectedObjectAnchorRef.current = anchor;
                            setSelectedObjectAnchor(anchor);
                        }
                    };
                    viewerContainer?.addEventListener('pointerup', objectPointerHandler);

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
                                    requestViewerOverlayUpdate();
                                }, 150);
                            });
                        },
                        () => setError('Não foi possível carregar o modelo no Autodesk Viewer.'),
                    );
                });
            })
            .catch(() => setError('Não foi possível carregar a biblioteca do Autodesk Viewer.'));

        return () => {
            cancelled = true;
            window.removeEventListener('resize', resizeViewer);
            resizeObserver?.disconnect();

            if (viewerRef.current) {
                if (overlayEventHandler && window.Autodesk?.Viewing) {
                    viewerRef.current.removeEventListener(window.Autodesk.Viewing.CAMERA_CHANGE_EVENT, overlayEventHandler);
                    viewerRef.current.removeEventListener(window.Autodesk.Viewing.GEOMETRY_LOADED_EVENT, overlayEventHandler);
                }
                if (selectionEventHandler && window.Autodesk?.Viewing) {
                    viewerRef.current.removeEventListener(window.Autodesk.Viewing.SELECTION_CHANGED_EVENT, selectionEventHandler);
                }
                if (viewerContainer && objectPointerHandler) {
                    viewerContainer.removeEventListener('pointerup', objectPointerHandler);
                }
                cancelCoreMarkupEdit();
                unloadCoreMarkupLayers();
                markupsCoreRef.current = null;
                setMarkupsCoreReady(false);
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
                        <Link href={route('tenant.projects.review.index', tenant.slug)} className="sig-btn sig-btn-secondary">
                            <ArrowLeft size={15} />
                            Analisar projetos
                        </Link>
                        {isReady && showCommentsPanel && (
                            <button
                                type="button"
                                className="sig-btn sig-btn-secondary"
                                onClick={() => setReviewPanelCollapsed((collapsed) => !collapsed)}
                            >
                                {reviewPanelCollapsed ? <PanelRightOpen size={15} /> : <PanelRightClose size={15} />}
                                {reviewPanelCollapsed ? 'Mostrar comentários' : 'Recolher painel'}
                            </button>
                        )}
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
                {markupsCoreError && showCommentsPanel && (
                    <div className="mx-4 mt-4 rounded-lg border border-[var(--amber)] bg-[var(--amber-50)] px-4 py-3 text-sm text-[var(--amber)]">
                        {markupsCoreError}
                    </div>
                )}

                {isReady ? (
                    <div className={`sig-viewer-workspace ${!showCommentsPanel || reviewPanelCollapsed ? 'review-collapsed' : ''}`}>
                        <div className="sig-viewer-stage">
                            <div ref={containerRef} className="sig-viewer-canvas" />
                            <ProjectMarkupOverlay
                                markups={visibleOverlayMarkups}
                                pinPositions={markupPinPositions}
                                selectedMarkupId={selectedMarkupId}
                                summaryMode={allMarkupsVisible}
                                onSelect={selectMarkupInViewer}
                            />
                        </div>
                        {showCommentsPanel && !reviewPanelCollapsed && (
                            <ProjectReviewPanel
                                tenant={tenant}
                                version={version}
                                workspaceMode={workspaceMode}
                                canReviewProjects={canReviewProjects}
                                contractUsers={contractUsers}
                                markups={visibleMarkups}
                                checklist={reviewChecklist}
                                showChecklistPanel={showChecklistPanel}
                                markupsCoreReady={markupsCoreReady}
                                markupsCoreEditing={markupsCoreEditing}
                                markupsCoreError={markupsCoreError}
                                activeMarkupTool={activeMarkupTool}
                                selectedObjectAnchor={selectedObjectAnchor}
                                markupStyle={markupStyle}
                                setMarkupStyle={setMarkupStyle}
                                beginCoreMarkupEdit={beginCoreMarkupEdit}
                                cancelCoreMarkupEdit={cancelCoreMarkupEdit}
                                captureViewerState={captureViewerState}
                                captureMarkupPayload={captureMarkupPayload}
                                selectedMarkupId={selectedMarkupId}
                                allMarkupsVisible={allMarkupsVisible}
                                onShowAllMarkups={showAllCoreMarkups}
                                onHideAllMarkups={hideAllCoreMarkups}
                                onSelectMarkup={selectMarkupInViewer}
                                onCollapse={() => setReviewPanelCollapsed(true)}
                            />
                        )}
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
                                ? 'O processamento APS falhou. Tente processar novamente ou verifique se o formato enviado é suportado pelo Model Derivative.'
                                : 'A Autodesk pode levar alguns minutos para preparar o arquivo para visualização. Esta tela consulta o status automaticamente.'}
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

function ProjectMarkupOverlay({ markups, pinPositions, selectedMarkupId, summaryMode = false, onSelect }) {
    if (!markups.length) {
        return null;
    }

    return (
        <div className="sig-viewer-markup-layer" aria-label="Comentários visuais do projeto">
            {markups.map((markup, index) => {
                const position = pinPositions[markup.id];

                if (!position) {
                    return null;
                }

                if (summaryMode) {
                    return (
                        <button
                            key={markup.id}
                            type="button"
                            className={[
                                'sig-viewer-comment-chip',
                                markup.status === 'resolved' ? 'is-resolved' : '',
                                position.offscreen ? 'is-offscreen' : '',
                            ].filter(Boolean).join(' ')}
                            style={{ left: `${position.left}px`, top: `${position.top}px` }}
                            title={markup.title}
                            aria-label={`Abrir comentário visual ${markup.title}`}
                            onClick={() => onSelect(markup)}
                        >
                            <span className="sig-viewer-comment-chip-index">{index + 1}</span>
                            <span className="sig-viewer-comment-chip-title">{markup.title}</span>
                        </button>
                    );
                }

                if (markupCoreSvg(markup)) {
                    return null;
                }

                return (
                    <button
                        key={markup.id}
                        type="button"
                        className={[
                            'sig-viewer-pin',
                            markup.status === 'resolved' ? 'is-resolved' : '',
                            markup.id === selectedMarkupId ? 'is-selected' : '',
                            position.offscreen ? 'is-offscreen' : '',
                        ].filter(Boolean).join(' ')}
                        style={{ left: `${position.left}px`, top: `${position.top}px` }}
                        title={markup.title}
                        aria-label={`Abrir comentário visual ${markup.title}`}
                        onClick={() => onSelect(markup)}
                    >
                        <span>{index + 1}</span>
                    </button>
                );
            })}
        </div>
    );
}

function ProjectReviewPanel({
    tenant,
    version,
    workspaceMode,
    canReviewProjects,
    contractUsers,
    markups,
    checklist,
    showChecklistPanel,
    markupsCoreReady,
    markupsCoreEditing,
    markupsCoreError,
    activeMarkupTool,
    selectedObjectAnchor,
    markupStyle,
    setMarkupStyle,
    beginCoreMarkupEdit,
    cancelCoreMarkupEdit,
    captureViewerState,
    captureMarkupPayload,
    selectedMarkupId,
    allMarkupsVisible,
    onShowAllMarkups,
    onHideAllMarkups,
    onSelectMarkup,
    onCollapse,
}) {
    const [markupForm, setMarkupForm] = useState({
        title: '',
        description: '',
        assigned_to_id: '',
        priority: 'normal',
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

    useEffect(() => {
        if (!selectedMarkupId) {
            return;
        }

        document
            .getElementById(`project-markup-${selectedMarkupId}`)
            ?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }, [selectedMarkupId]);

    const completedItems = checklistItems.filter((item) => item.checked).length;
    const openMarkups = markups.filter((markup) => markup.status !== 'resolved').length;

    const createMarkup = (event) => {
        event.preventDefault();

        const markupPayload = captureMarkupPayload();

        if (!markupPayload) {
            return;
        }

        router.post(route('tenant.projects.markups.store', [tenant.slug, version.id]), {
            ...markupForm,
            assigned_to_id: markupForm.assigned_to_id || null,
            due_date: null,
            viewer_state: captureViewerState(),
            markup_payload: markupPayload,
        }, {
            preserveScroll: true,
            onSuccess: () => setMarkupForm({
                title: '',
                description: '',
                assigned_to_id: '',
                priority: 'normal',
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
                        <span className="eyebrow">Revisão do projeto</span>
                        <h2 className="mt-1 text-base font-semibold text-[var(--ink-900)]">
                            {showChecklistPanel ? 'Comentários visuais e checklist' : 'Comentários visuais'}
                        </h2>
                    </div>
                    <div className="flex items-center gap-2">
                        <span className="sig-pill sig-pill-blue">{version.revision}</span>
                        <button
                            type="button"
                            className="sig-btn sig-btn-ghost !min-h-8 !px-2"
                            title="Recolher painel"
                            aria-label="Recolher painel"
                            onClick={onCollapse}
                        >
                            <PanelRightClose size={15} />
                        </button>
                    </div>
                </div>

                <div className="mt-4 grid grid-cols-2 gap-2">
                    <Metric label="Comentários abertos" value={openMarkups} />
                    {showChecklistPanel && (
                        <Metric label="Checklist" value={`${completedItems}/${checklistItems.length}`} />
                    )}
                    {!showChecklistPanel && (
                        <Metric label="Modo" value={workspaceMode === 'comments' ? 'Comentários' : 'Projeto'} />
                    )}
                </div>
            </div>

            {canReviewProjects ? (
                <form onSubmit={createMarkup} className="grid gap-3 border-b border-[var(--border)] p-4">
                    <div className="flex items-center gap-2 text-[var(--ink-500)]">
                        <MessageSquarePlus size={15} />
                        <span className="eyebrow">Novo comentário visual</span>
                    </div>
                    <div className="rounded-lg border border-[var(--border)] bg-[var(--surface-muted)] p-3">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                 <div className="eyebrow">Autodesk MarkupsCore</div>
                                 <p className="mt-1 text-xs text-[var(--ink-500)]">
                                     Selecione uma peça no projeto para fixar o comentário no objeto, depois desenhe a marcação.
                                 </p>
                             </div>
                             <span className={`sig-pill ${markupsCoreReady ? 'sig-pill-green' : 'sig-pill-amber'}`}>
                                 {markupsCoreReady ? 'Pronto' : 'Carregando'}
                             </span>
                         </div>
                         <div className="mt-3 rounded-lg border border-[var(--border)] bg-white px-3 py-2 text-xs text-[var(--ink-600)]">
                             {selectedObjectAnchor ? (
                                 <span className="font-semibold text-[var(--primary)]">
                                     Objeto selecionado #{selectedObjectAnchor.dbId}
                                     {selectedObjectAnchor.name ? ` - ${selectedObjectAnchor.name}` : ''}. A marcação será vinculada ao ponto clicado nesse objeto.
                                 </span>
                             ) : (
                                 <span>
                                     Nenhum objeto selecionado. Se não selecionar uma peça, o sistema tentará fixar pelo ponto desenhado sobre a geometria.
                                 </span>
                             )}
                         </div>

                         <div className="mt-3 grid gap-2">
                            <div className="flex flex-wrap gap-2">
                                {Object.entries(coreMarkupTools).map(([tool, config]) => (
                                    <button
                                        key={tool}
                                        type="button"
                                        className={`sig-btn sig-btn-secondary sig-btn-sm ${activeMarkupTool === tool ? 'border-[var(--primary)] text-[var(--primary)]' : ''}`}
                                        disabled={!markupsCoreReady}
                                        onClick={() => beginCoreMarkupEdit(tool)}
                                    >
                                        {config.label}
                                    </button>
                                ))}
                            </div>
                            <div className="flex flex-wrap items-center gap-2 text-xs text-[var(--ink-500)]">
                                {markupsCoreEditing ? (
                                    <>
                                        <span className="sig-pill sig-pill-blue">Desenho ativo: {coreMarkupTools[activeMarkupTool]?.label}</span>
                                        <span>Use quantas ferramentas precisar antes de salvar. A navegação fica pausada enquanto o desenho está ativo.</span>
                                        <button
                                            type="button"
                                            className="sig-btn sig-btn-secondary sig-btn-sm"
                                            onClick={cancelCoreMarkupEdit}
                                        >
                                            Cancelar desenho
                                        </button>
                                    </>
                                ) : (
                                    <span>Escolha uma ferramenta para criar uma marcação SVG vinculada ao comentário.</span>
                                )}
                            </div>
                            <div className="grid gap-3 rounded-lg border border-[var(--border)] bg-white p-3">
                                <div className="flex items-center justify-between gap-3">
                                    <span className="eyebrow">Estilo</span>
                                    <span className="text-xs font-semibold text-[var(--ink-500)]">
                                        Linha {formatMarkupScale(markupStyle.strokeWidth)} · Texto {formatMarkupScale(markupStyle.fontSize)}
                                    </span>
                                </div>
                                <div className="flex flex-wrap items-center gap-2">
                                    {markupColorOptions.map((color) => (
                                        <button
                                            key={color.value}
                                            type="button"
                                            className={`h-7 w-7 rounded-full border ${markupStyle.color === color.value ? 'border-[var(--primary)] ring-2 ring-[var(--primary-100)]' : 'border-[var(--border-strong)]'}`}
                                            style={{ backgroundColor: color.value }}
                                            title={color.label}
                                            aria-label={`Usar cor ${color.label}`}
                                            onClick={() => setMarkupStyle((current) => ({ ...current, color: color.value }))}
                                        />
                                    ))}
                                </div>
                                <label className="grid gap-1">
                                    <span className="eyebrow">Espessura</span>
                                    <input
                                        type="range"
                                        min="0"
                                        max="100"
                                        step="1"
                                        value={scaleToSlider(markupStyle.strokeWidth, strokeWidthRange)}
                                        onChange={(event) => setMarkupStyle((current) => ({
                                            ...current,
                                            strokeWidth: normalizeStrokeWidth(sliderToScale(event.target.value, strokeWidthRange)),
                                        }))}
                                        className="w-full accent-[var(--primary)]"
                                    />
                                </label>
                                <label className="grid gap-1">
                                    <span className="eyebrow">Tamanho do texto</span>
                                    <input
                                        type="range"
                                        min="0"
                                        max="100"
                                        step="1"
                                        value={scaleToSlider(markupStyle.fontSize, fontSizeRange)}
                                        onChange={(event) => setMarkupStyle((current) => ({
                                            ...current,
                                            fontSize: normalizeFontSize(sliderToScale(event.target.value, fontSizeRange)),
                                        }))}
                                        className="w-full accent-[var(--primary)]"
                                    />
                                </label>
                                <label className="inline-flex items-center gap-2 text-xs font-semibold text-[var(--ink-600)]">
                                    <input
                                        type="checkbox"
                                        checked={markupStyle.fillEnabled}
                                        onChange={(event) => setMarkupStyle((current) => ({
                                            ...current,
                                            fillEnabled: event.target.checked,
                                        }))}
                                        className="h-4 w-4 rounded border-[var(--border)]"
                                    />
                                    Preenchimento leve
                                </label>
                            </div>
                            {markupsCoreError && (
                                <p className="text-xs text-[var(--amber)]">{markupsCoreError}</p>
                            )}
                        </div>
                    </div>
                    <label>
                        <span className="eyebrow mb-1 block">Título</span>
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
                        <span className="eyebrow mb-1 block">Comentário</span>
                        <textarea
                            value={markupForm.description}
                            onChange={(event) => setMarkupForm((current) => ({ ...current, description: event.target.value }))}
                            placeholder="Descreva o ponto de revisão"
                            rows={3}
                            className="w-full rounded-lg border border-[var(--border)] px-3 py-2 text-sm outline-none focus:border-[var(--primary)]"
                        />
                    </label>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <label>
                            <span className="eyebrow mb-1 block">Responsável</span>
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
                    <button type="submit" className="sig-btn sig-btn-primary">
                        <MapPin size={15} />
                        {markupsCoreEditing ? 'Salvar desenho e comentário' : 'Criar comentário'}
                    </button>
                </form>
            ) : (
                <div className="border-b border-[var(--border)] bg-[var(--surface-muted)] p-4 text-sm text-[var(--ink-500)]">
                    Você está visualizando os comentários do projeto em modo leitura.
                </div>
            )}

            <section className="border-b border-[var(--border)] p-4">
                <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <div className="flex items-center gap-2 text-[var(--ink-500)]">
                        <MapPin size={15} />
                        <span className="eyebrow">Comentários no projeto</span>
                    </div>
                    <button
                        type="button"
                        className="sig-btn sig-btn-secondary sig-btn-sm"
                        disabled={!markups.length}
                        onClick={allMarkupsVisible ? onHideAllMarkups : onShowAllMarkups}
                    >
                        {allMarkupsVisible ? <EyeOff size={13} /> : <Eye size={13} />}
                        {allMarkupsVisible ? 'Cancelar visualizacao' : 'Visualizar todos'}
                    </button>
                </div>

                {markups.length > 0 ? (
                    <div className="grid gap-3">
                        {markups.map((markup, index) => {
                            const objectName = markupObjectName(markup);

                            return (
                            <article
                                key={markup.id}
                                id={`project-markup-${markup.id}`}
                                className={`rounded-lg border bg-white p-3 ${selectedMarkupId === markup.id ? 'border-[var(--primary)] shadow-sm' : 'border-[var(--border)]'}`}
                            >
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="sig-pill sig-pill-muted">#{index + 1}</span>
                                            <span className={`sig-pill ${priorityClasses[markup.priority] || 'sig-pill-blue'}`}>
                                                {priorityLabels[markup.priority] || markup.priority}
                                            </span>
                                            <span className="sig-pill sig-pill-muted">
                                                {markupStatusLabels[markup.status] || markup.status}
                                            </span>
                                            {markupCoreSvg(markup) && (
                                                <span className="sig-pill sig-pill-blue">Desenho APS</span>
                                            )}
                                        </div>
                                        <h3 className="mt-2 text-sm font-semibold text-[var(--ink-900)]">{markup.title}</h3>
                                    </div>
                                    <button
                                        type="button"
                                        className="sig-btn sig-btn-secondary sig-btn-sm !px-2"
                                        title="Ver marcação"
                                        aria-label="Ver marcação"
                                        onClick={() => onSelectMarkup(markup)}
                                    >
                                        <Eye size={13} />
                                    </button>
                                </div>

                                 {markup.description && (
                                     <p className="mt-2 whitespace-pre-line text-sm text-[var(--ink-500)]">{markup.description}</p>
                                 )}

                                 <div className="mt-3 grid gap-2 text-xs text-[var(--ink-500)]">
                                     {objectName && (
                                         <span className="flex items-center gap-1">
                                             <MapPin size={13} />
                                             {objectName}
                                         </span>
                                     )}
                                     <span className="flex items-center gap-1">
                                         <UserRound size={13} />
                                         {userLabel(markup.assignee)}
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
                                                title="Remover comentário"
                                                message={`Deseja mesmo remover o comentário "${markup.title}"? O registro sairá desta revisão.`}
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
                            );
                        })}
                    </div>
                ) : (
                    <div className="rounded-lg border border-dashed border-[var(--border)] bg-[var(--surface-muted)] p-4 text-sm text-[var(--ink-500)]">
                        Nenhum comentário visual registrado nesta versão.
                    </div>
                )}
            </section>

            {showChecklistPanel && (
                <section className="p-4">
                    <div className="mb-3 flex items-center gap-2 text-[var(--ink-500)]">
                        <CheckSquare size={15} />
                        <span className="eyebrow">Checklist de análise</span>
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
                                                    Concluído por {item.checked_by.name}
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
                                        placeholder="Observação do item"
                                        rows={2}
                                        className="mt-3 w-full rounded-lg border border-[var(--border)] px-3 py-2 text-sm outline-none focus:border-[var(--primary)] disabled:bg-[var(--surface-muted)]"
                                    />
                                </article>
                            ))}
                        </div>
                    ) : (
                        <div className="rounded-lg border border-dashed border-[var(--border)] bg-[var(--surface-muted)] p-4 text-sm text-[var(--ink-500)]">
                            Checklist ainda não iniciado.
                        </div>
                    )}
                </section>
            )}
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
