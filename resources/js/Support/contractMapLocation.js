const states = { AC: 'Acre', AL: 'Alagoas', AP: 'Amapá', AM: 'Amazonas', BA: 'Bahia', CE: 'Ceará', DF: 'Distrito Federal', ES: 'Espírito Santo', GO: 'Goiás', MA: 'Maranhão', MT: 'Mato Grosso', MS: 'Mato Grosso do Sul', MG: 'Minas Gerais', PA: 'Pará', PB: 'Paraíba', PR: 'Paraná', PE: 'Pernambuco', PI: 'Piauí', RJ: 'Rio de Janeiro', RN: 'Rio Grande do Norte', RS: 'Rio Grande do Sul', RO: 'Rondônia', RR: 'Roraima', SC: 'Santa Catarina', SP: 'São Paulo', SE: 'Sergipe', TO: 'Tocantins' };

export const brazilMapView = { center: [-53, -14], zoom: 3 };

export async function contractMapLocation(contract, token, signal, request = fetch) {
    const city = contract?.city?.trim();
    const rawState = contract?.state?.trim();
    const state = states[rawState?.toUpperCase()] || rawState;
    const searches = [city && { query: [city, state, 'Brasil'].filter(Boolean).join(', '), type: 'place', zoom: 12 },
        state && { query: `${state}, Brasil`, type: 'region', zoom: 6 }].filter(Boolean);
    for (const search of searches) {
        const params = new URLSearchParams({ q: search.query, country: 'br', types: search.type, limit: '1', autocomplete: 'false', language: 'pt', access_token: token });
        const response = await request(`https://api.mapbox.com/search/geocode/v6/forward?${params}`, { signal });
        if (!response.ok) throw new Error('Contract location unavailable');
        const data = await response.json();
        const center = data.features?.[0]?.geometry?.coordinates;
        if (Array.isArray(center) && center.length === 2 && center.every(Number.isFinite) && Math.abs(center[0]) <= 180 && Math.abs(center[1]) <= 90) {
            return { center, zoom: search.zoom };
        }
    }
    return null;
}
