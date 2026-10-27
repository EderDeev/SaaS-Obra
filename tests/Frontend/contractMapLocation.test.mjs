import { test } from 'node:test';
import assert from 'node:assert/strict';
import { contractMapLocation } from '../../resources/js/Support/contractMapLocation.js';

const result = center => ({ ok: true, json: async () => ({ features: center ? [{ geometry: { coordinates: center } }] : [] }) });

test('uses the contract city and full state name, scoped to Brazil', async () => {
    const view = await contractMapLocation({ city: 'Belém', state: 'PA' }, 'token', undefined, async url => {
        const params = new URL(url).searchParams;
        assert.equal(params.get('q'), 'Belém, Pará, Brasil');
        assert.equal(params.get('types'), 'place');
        assert.equal(params.get('country'), 'br');
        return result([-48.5, -1.45]);
    });
    assert.deepEqual(view, { center: [-48.5, -1.45], zoom: 12 });
});

test('falls back to the state when the city has no result', async () => {
    let calls = 0;
    const view = await contractMapLocation({ city: 'Unknown', state: 'SP' }, 'token', undefined, async url => {
        calls++;
        return new URL(url).searchParams.get('types') === 'place' ? result(null) : result([-48, -22]);
    });
    assert.equal(calls, 2);
    assert.deepEqual(view, { center: [-48, -22], zoom: 6 });
});

test('uses the state without a city and accepts zero coordinates', async () => {
    assert.deepEqual(await contractMapLocation({ state: 'Pará' }, 'token', undefined, async () => result([0, 0])), { center: [0, 0], zoom: 6 });
});

test('does not search when contract location is missing', async () => {
    assert.equal(await contractMapLocation({}, 'token', undefined, async () => { throw new Error('Unexpected request'); }), null);
});

test('rejects invalid geometry and request failures without inventing a location', async () => {
    assert.equal(await contractMapLocation({ city: 'City' }, 'token', undefined, async () => result([200, 95])), null);
    await assert.rejects(contractMapLocation({ city: 'City' }, 'token', undefined, async () => ({ ok: false })));
});

test('passes cancellation to the geocoding request', async () => {
    const controller = new AbortController();
    controller.abort();
    await assert.rejects(contractMapLocation({ city: 'City' }, 'token', controller.signal, async (_url, { signal }) => {
        assert.equal(signal, controller.signal);
        signal.throwIfAborted();
    }), { name: 'AbortError' });
});
