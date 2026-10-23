export const NOTIFICATION_SOUND_URL = '/audio/notification-new-012.mp3';
const limit = 500;

export function soundPreferenceKey(userId) {
    return `sigworks:notifications:${userId}:sound`;
}

export function readSoundPreference(storage, userId) {
    try { return storage?.getItem(soundPreferenceKey(userId)) !== 'false'; }
    catch { return true; }
}

export function writeSoundPreference(storage, userId, enabled) {
    try { storage?.setItem(soundPreferenceKey(userId), String(enabled)); }
    catch { /* Sound controls still work when browser storage is unavailable. */ }
}

export class NotificationSoundGate {
    constructor({ userId, storage, locks, play, wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms)) }) {
        this.key = `sigworks:notifications:${userId}:heard`;
        this.userId = userId;
        this.storage = storage;
        this.locks = locks;
        this.play = play;
        this.wait = wait;
        this.seen = null;
        this.memory = [];
        this.enabled = true;
        this.generation = 0;
    }

    cancel() { this.generation++; }

    read() {
        if (!this.storage) return this.memory;
        try {
            const raw = this.storage.getItem(this.key);
            if (raw === null) return this.memory;
            const stored = JSON.parse(raw);
            return Array.isArray(stored) ? stored.filter((id) => typeof id === 'string').slice(-limit) : this.memory;
        } catch { return this.memory; }
    }

    remember(ids) {
        this.memory = [...new Set([...this.read(), ...ids])].slice(-limit);
        try { this.storage?.setItem(this.key, JSON.stringify(this.memory)); }
        catch { /* Keep the in-memory history if storage is blocked or full. */ }
    }

    async observe(items, enabled) {
        const generation = this.generation;
        this.enabled = enabled;
        const ids = items.map((item) => item.id);
        if (this.seen === null) {
            this.seen = new Set(ids);
            this.remember(ids);
            return false;
        }
        const incoming = items.filter((item) => item.unread && !this.seen.has(item.id)).map((item) => item.id);
        this.seen = new Set([...this.seen, ...ids].slice(-limit));
        if (!incoming.length) return false;
        if (!enabled) {
            this.remember(incoming);
            return false;
        }

        const commit = async () => {
            if (this.generation !== generation) return false;
            const heard = new Set(this.read());
            const fresh = incoming.filter((id) => !heard.has(id));
            if (!fresh.length) return false;
            this.remember(fresh);
            if (!this.enabled || !readSoundPreference(this.storage, this.userId)) return false;
            try { return (await this.play()) !== false; }
            catch { return false; }
        };

        // Only one tab may claim the same notification at a time.
        if (this.locks?.request) {
            try { return await this.locks.request(this.key, commit); }
            catch { /* Older browser contexts use the shared-storage claim below. */ }
        }
        if (!this.storage) return commit();
        const claimKey = `${this.key}:claim`;
        const claim = `${Date.now()}:${Math.random()}`;
        try {
            this.storage.setItem(claimKey, claim);
            await this.wait(80);
            if (this.storage.getItem(claimKey) !== claim) return false;
        } catch { return commit(); }
        return commit();
    }
}
