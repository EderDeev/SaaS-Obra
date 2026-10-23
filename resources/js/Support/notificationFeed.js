export function startNotificationPolling({ fetchNotifications, onData, document: page, interval = 30000, setIntervalFn = setInterval, clearIntervalFn = clearInterval }) {
    let stopped = false;
    let pending = null;
    const refresh = async () => {
        if (stopped || page.visibilityState === 'hidden' || pending) return;
        const controller = new AbortController();
        pending = controller;
        try {
            const data = await fetchNotifications(controller.signal);
            if (!stopped && !controller.signal.aborted && Array.isArray(data.items) && Number.isInteger(data.unread_count)) onData(data);
        } catch { /* Keep current alerts during temporary connection failures. */ }
        finally { if (pending === controller) pending = null; }
    };
    const timer = setIntervalFn(refresh, interval);
    const onVisibility = () => { if (page.visibilityState !== 'hidden') void refresh(); };
    page.addEventListener('visibilitychange', onVisibility);
    return () => {
        stopped = true;
        clearIntervalFn(timer);
        page.removeEventListener('visibilitychange', onVisibility);
        pending?.abort();
    };
}
