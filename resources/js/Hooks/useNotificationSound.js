import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { NotificationSoundGate, readSoundPreference, soundPreferenceKey, writeSoundPreference } from '@/Support/notificationSound';

function browserStorage() {
    try { return typeof window === 'undefined' ? null : window.localStorage; }
    catch { return null; }
}

export default function useNotificationSound(userId, items) {
    const storage = useMemo(browserStorage, [userId]);
    const [enabled, setEnabled] = useState(() => readSoundPreference(storage, userId));
    const enabledRef = useRef(enabled);
    enabledRef.current = enabled;
    const [blocked, setBlocked] = useState(false);
    const audioRef = useRef(null);
    const priming = useRef(null);

    const prime = useCallback(() => {
        const audio = audioRef.current;
        if (!audio || priming.current) return;
        audio.muted = true;
        priming.current = audio.play().then(() => {
            audio.pause();
            audio.currentTime = 0;
        }).catch(() => {}).finally(() => {
            audio.muted = false;
            priming.current = null;
        });
    }, []);

    const play = useCallback(async () => {
        await priming.current;
        const audio = audioRef.current;
        if (!audio || !enabledRef.current) return false;
        audio.muted = false;
        audio.volume = 0.5;
        audio.currentTime = 0;
        try {
            await audio.play();
            setBlocked(false);
            return true;
        } catch (error) {
            if (error?.name === 'NotAllowedError') setBlocked(true);
            return false;
        }
    }, []);

    const gate = useMemo(() => new NotificationSoundGate({
        userId, storage, play,
        locks: typeof navigator === 'undefined' ? null : navigator.locks,
    }), [userId, storage, play]);

    useEffect(() => {
        setEnabled(readSoundPreference(storage, userId));
        const updatePreference = (event) => {
            if (event.key === null || event.key === soundPreferenceKey(userId)) {
                setEnabled(readSoundPreference(storage, userId));
            }
        };
        window.addEventListener('storage', updatePreference);
        return () => window.removeEventListener('storage', updatePreference);
    }, [storage, userId]);

    useEffect(() => {
        void gate.observe(items, enabled);
    }, [gate, items, enabled]);

    useEffect(() => () => gate.cancel(), [gate]);

    useEffect(() => {
        if (!enabled) return;
        const unlock = () => {
            prime();
            window.removeEventListener('pointerdown', unlock, true);
            window.removeEventListener('keydown', unlock, true);
        };
        window.addEventListener('pointerdown', unlock, true);
        window.addEventListener('keydown', unlock, true);
        return () => {
            window.removeEventListener('pointerdown', unlock, true);
            window.removeEventListener('keydown', unlock, true);
        };
    }, [enabled, prime]);

    useEffect(() => {
        const audio = audioRef.current;
        return () => { audio?.pause(); };
    }, [userId]);

    const toggle = () => {
        const next = !enabled;
        enabledRef.current = next;
        writeSoundPreference(storage, userId, next);
        setEnabled(next);
        if (next) prime();
        else audioRef.current?.pause();
    };

    return { audioRef, enabled, blocked, toggle, preview: play };
}
