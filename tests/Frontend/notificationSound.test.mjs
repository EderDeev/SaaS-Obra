import test from 'node:test';
import assert from 'node:assert/strict';
import { NotificationSoundGate, readSoundPreference, writeSoundPreference } from '../../resources/js/Support/notificationSound.js';

const notice = (id, unread = true) => ({ id, unread });
function memoryStorage() {
    const values = new Map();
    return { getItem: (key) => values.get(key) ?? null, setItem: (key, value) => values.set(key, value) };
}
function exclusiveLocks() {
    let previous = Promise.resolve();
    return { request: (_key, callback) => {
        const next = previous.then(callback);
        previous = next.catch(() => {});
        return next;
    } };
}
function context(extra = {}) {
    let played = 0;
    const options = { userId: 1, storage: memoryStorage(), locks: exclusiveLocks(), play: async () => { played++; }, ...extra };
    return { gate: new NotificationSoundGate(options), options, plays: () => played };
}

test('opening a page with unread notifications stays silent', async () => {
    const { gate, plays } = context();
    await gate.observe([notice('existing')], true);
    await gate.observe([notice('existing')], true);
    assert.equal(plays(), 0);
});

test('a new unread alert plays once; repeating a poll or marking it read does not replay it', async () => {
    const { gate, plays } = context();
    await gate.observe([], true);
    assert.equal(await gate.observe([notice('new')], true), true);
    await gate.observe([notice('new')], true);
    await gate.observe([notice('new', false)], true);
    await gate.observe([notice('new')], true);
    assert.equal(plays(), 1);
});

test('newly loaded read alerts stay silent and a batch produces one sound', async () => {
    const { gate, plays } = context();
    await gate.observe([], true);
    await gate.observe([notice('read', false)], true);
    assert.equal(plays(), 0);
    await gate.observe([notice('a'), notice('b'), notice('read', false)], true);
    assert.equal(plays(), 1);
});

test('muted arrivals are not replayed when the sound is enabled again', async () => {
    const { gate, plays, options } = context();
    await gate.observe([], true);
    writeSoundPreference(options.storage, 1, false);
    await gate.observe([notice('while-muted')], false);
    writeSoundPreference(options.storage, 1, true);
    await gate.observe([notice('while-muted')], true);
    assert.equal(plays(), 0);
    await gate.observe([notice('new'), notice('while-muted')], true);
    assert.equal(plays(), 1);
});

test('two tabs cannot play the same incoming notification twice', async () => {
    const { options, plays } = context();
    const a = new NotificationSoundGate(options);
    const b = new NotificationSoundGate(options);
    await Promise.all([a.observe([], true), b.observe([], true)]);
    await Promise.all([a.observe([notice('shared')], true), b.observe([notice('shared')], true)]);
    assert.equal(plays(), 1);
});

test('shared-storage claims suppress duplicate sounds when Web Locks are unavailable', async () => {
    const { options, plays } = context({ locks: null, wait: async () => {} });
    const a = new NotificationSoundGate(options);
    const b = new NotificationSoundGate(options);
    await Promise.all([a.observe([], true), b.observe([], true)]);
    await Promise.all([a.observe([notice('shared')], true), b.observe([notice('shared')], true)]);
    assert.equal(plays(), 1);
});

test('a fresh tab initializes silently and remembers alerts already handled elsewhere', async () => {
    const { options, plays } = context();
    const a = new NotificationSoundGate(options);
    await a.observe([], true);
    await a.observe([notice('a')], true);
    const b = new NotificationSoundGate(options);
    await b.observe([], true);
    await b.observe([notice('a')], true);
    assert.equal(plays(), 1);
});

test('preferences and notification histories are separated by user', async () => {
    const { options, plays } = context();
    writeSoundPreference(options.storage, 1, false);
    assert.equal(readSoundPreference(options.storage, 1), false);
    assert.equal(readSoundPreference(options.storage, 2), true);
    const a = new NotificationSoundGate(options);
    const b = new NotificationSoundGate({ ...options, userId: 2 });
    await a.observe([], false);
    await a.observe([notice('id')], false);
    await b.observe([], true);
    await b.observe([notice('id')], true);
    assert.equal(plays(), 1);
});

test('failed playback is contained and the next poll does not retry an old alert', async () => {
    let attempts = 0;
    const { gate } = context({ play: async () => { attempts++; throw new Error('Autoplay denied'); } });
    await gate.observe([], true);
    assert.equal(await gate.observe([notice('a')], true), false);
    await gate.observe([notice('a')], true);
    assert.equal(attempts, 1);
});

test('unavailable browser storage does not break notifications', async () => {
    const storage = { getItem: () => { throw new Error('Blocked'); }, setItem: () => { throw new Error('Full'); } };
    assert.equal(readSoundPreference(storage, 1), true);
    assert.doesNotThrow(() => writeSoundPreference(storage, 1, false));
    const { gate, plays } = context({ storage, locks: null });
    await gate.observe([], true);
    await gate.observe([notice('new')], true);
    await gate.observe([notice('new')], true);
    assert.equal(plays(), 1);
});

test('muting during a pending claim prevents playback', async () => {
    const { options, plays } = context();
    const gate = new NotificationSoundGate(options);
    await gate.observe([], true);
    const incoming = gate.observe([notice('new')], true);
    writeSoundPreference(options.storage, 1, false);
    await gate.observe([notice('new')], false);
    await incoming;
    assert.equal(plays(), 0);
});

test('leaving the page cancels a pending sound claim', async () => {
    const { gate, plays } = context();
    await gate.observe([], true);
    const incoming = gate.observe([notice('new')], true);
    gate.cancel();
    await incoming;
    assert.equal(plays(), 0);
});
