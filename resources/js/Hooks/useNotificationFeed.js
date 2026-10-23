import axios from 'axios';
import { useEffect, useState } from 'react';
import { startNotificationPolling } from '@/Support/notificationFeed';

const empty = { unread_count: 0, items: [] };

export default function useNotificationFeed(userId, initial = empty) {
    const [feed, setFeed] = useState(initial);
    useEffect(() => { setFeed(initial); }, [initial, userId]);
    useEffect(() => startNotificationPolling({
        document,
        fetchNotifications: async (signal) => (await axios.get(route('notifications.index'), { signal })).data,
        onData: setFeed,
    }), [userId]);
    return feed;
}
