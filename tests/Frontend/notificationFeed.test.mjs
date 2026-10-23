import test from 'node:test';
import assert from 'node:assert/strict';
import { startNotificationPolling } from '../../resources/js/Support/notificationFeed.js';

function setup(fetchNotifications = async () => ({ items: [], unread_count: 0 })) {
    let tick;
    let listener;
    let cleared = false;
    let interval;
    const updates = [];
    const page = {
        visibilityState: 'visible',
        addEventListener: (_name, callback) => { listener = callback; },
        removeEventListener: () => { listener = null; },
    };
    const stop = startNotificationPolling({
        fetchNotifications, onData: (value) => updates.push(value), document: page,
        setIntervalFn: (callback, ms) => { tick = callback; interval = ms; return 1; },
        clearIntervalFn: () => { cleared = true; },
    });
    return { page, stop, updates, tick: () => tick(), visibility: () => listener?.(), interval: () => interval, cleared: () => cleared };
}

test('polls the dedicated feed every 30 seconds without reloading on mount', async () => {
    let requests = 0;
    const c = setup(async () => { requests++; return { items: [], unread_count: 0 }; });
    assert.equal(c.interval(), 30000);
    assert.equal(requests, 0);
    await c.tick();
    assert.equal(requests, 1);
    assert.equal(c.updates.length, 1);
    c.stop();
});

test('hidden tabs do not poll and returning to a visible tab refreshes promptly', async () => {
    let requests = 0;
    const c = setup(async () => { requests++; return { items: [], unread_count: 0 }; });
    c.page.visibilityState = 'hidden';
    await c.tick();
    assert.equal(requests, 0);
    c.page.visibilityState = 'visible';
    c.visibility();
    await Promise.resolve();
    assert.equal(requests, 1);
    c.stop();
});

test('slow responses do not overlap and unmounting aborts a request without updating state', async () => {
    let finish;
    let signal;
    let requests = 0;
    const c = setup((value) => { requests++; signal = value; return new Promise((resolve) => { finish = resolve; }); });
    const first = c.tick();
    await c.tick();
    assert.equal(requests, 1);
    c.stop();
    assert.equal(signal.aborted, true);
    assert.equal(c.cleared(), true);
    finish({ items: [], unread_count: 1 });
    await first;
    assert.equal(c.updates.length, 0);
    await c.tick();
    assert.equal(requests, 1);
});

test('temporary failures and malformed responses retain the current feed', async () => {
    let requests = 0;
    const c = setup(async () => {
        requests++;
        if (requests === 1) throw new Error('Offline');
        if (requests === 2) return { items: 'invalid' };
        return { items: [{ id: 'new' }], unread_count: 1 };
    });
    await c.tick();
    await c.tick();
    assert.equal(c.updates.length, 0);
    await c.tick();
    assert.equal(c.updates[0].items[0].id, 'new');
    c.stop();
});
