import { ChevronDown, ChevronUp, LoaderCircle, MapPin, RotateCcw } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import 'mapbox-gl/dist/mapbox-gl.css';

const token = import.meta.env.VITE_MAPBOX_ACCESS_TOKEN;

export default function LocationMap({ latitude, longitude }) {
    const [expanded, setExpanded] = useState(true);
    const panelId = useId();
    const lat = latitude == null || String(latitude).trim() === '' ? NaN : Number(latitude);
    const lng = longitude == null || String(longitude).trim() === '' ? NaN : Number(longitude);
    const valid = Number.isFinite(lat) && Number.isFinite(lng) && Math.abs(lat) <= 90 && Math.abs(lng) <= 180;

    return <section className="sig-card overflow-hidden print:hidden" aria-label="Localização da RNC no mapa">
        <div className="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
            <div><h2 className="flex items-center gap-2 text-base font-semibold"><MapPin size={17} />Localização da RNC</h2>
                {valid && <p className="mt-1 text-xs text-[var(--ink-500)]">{lat.toFixed(6)}, {lng.toFixed(6)} · Satélite</p>}</div>
            <button type="button" className="sig-btn sig-btn-secondary sig-btn-sm" aria-expanded={expanded} aria-controls={panelId} onClick={() => setExpanded(value => !value)}>
                {expanded ? <ChevronUp size={16} /> : <ChevronDown size={16} />}{expanded ? 'Minimizar mapa' : 'Mostrar mapa'}
            </button>
        </div>
        <div id={panelId} hidden={!expanded} className="border-t border-[var(--border)]">
            {expanded && (!valid ? <p className="p-5 text-sm text-[var(--ink-500)]">Esta RNC não possui coordenadas válidas para exibir no mapa.</p>
                : !token ? <p className="p-5 text-sm text-[var(--ink-500)]">O mapa está indisponível neste ambiente. As coordenadas da RNC continuam disponíveis acima.</p>
                    : <MapViewport latitude={lat} longitude={lng} />)}
        </div>
    </section>;
}

function MapViewport({ latitude, longitude }) {
    const container = useRef(null);
    const [state, setState] = useState('loading');
    const [retry, setRetry] = useState(0);

    useEffect(() => {
        let cancelled = false;
        let map;
        let marker;
        let observer;
        setState('loading');
        const timeout = setTimeout(() => { if (!cancelled) setState('error'); }, 30000);
        async function initialize() {
            try {
                const { default: mapboxgl } = await import('mapbox-gl');
                if (cancelled || !container.current) return;
                if (!mapboxgl.supported()) throw new Error('WebGL unavailable');
                map = new mapboxgl.Map({
                    container: container.current, accessToken: token,
                    style: 'mapbox://styles/mapbox/standard-satellite',
                    center: [longitude, latitude], zoom: 16, pitch: 35,
                    attributionControl: false,
                });
                // The saved location is for consultation; it cannot be moved here.
                marker = new mapboxgl.Marker({ color: '#d92d20', draggable: false }).setLngLat([longitude, latitude]).addTo(map);
                map.addControl(new mapboxgl.NavigationControl(), 'top-right');
                map.addControl(new mapboxgl.AttributionControl({ compact: true }), 'bottom-right');
                map.scrollZoom.disable();
                map.on('load', () => { if (!cancelled) { clearTimeout(timeout); setState('ready'); map.resize(); } });
                map.on('error', () => { if (!cancelled) { clearTimeout(timeout); setState('error'); } });
                observer = new ResizeObserver(() => map.resize());
                observer.observe(container.current);
            } catch {
                if (!cancelled) { clearTimeout(timeout); setState('error'); }
            }
        }
        initialize();
        return () => { cancelled = true; clearTimeout(timeout); observer?.disconnect(); marker?.remove(); map?.remove(); };
    }, [latitude, longitude, retry]);

    return <div className="relative h-80 bg-slate-100 sm:h-96">
        <div ref={container} className="h-full w-full" role="region" aria-label="Mapa de satélite da localização registrada na RNC" />
        {state === 'loading' && <div role="status" className="pointer-events-none absolute inset-0 flex items-center justify-center gap-2 bg-white/85 text-sm text-slate-600"><LoaderCircle size={18} className="animate-spin" />Carregando mapa...</div>}
        {state === 'error' && <div role="alert" className="absolute inset-0 flex flex-col items-center justify-center gap-3 bg-slate-50 p-5 text-center text-sm text-slate-600"><p>Não foi possível carregar o mapa. Verifique sua conexão e tente novamente.</p><button type="button" className="sig-btn sig-btn-secondary" onClick={() => setRetry(value => value + 1)}><RotateCcw size={15} />Tentar novamente</button></div>}
    </div>;
}
