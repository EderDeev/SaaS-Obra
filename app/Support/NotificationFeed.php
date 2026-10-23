<?php

namespace App\Support;

use App\Models\User;

class NotificationFeed
{
    public static function forUser(User $user): array
    {
        return [
            'unread_count' => $user->unreadNotifications()->count(),
            'items' => $user->notifications()->latest()->limit(50)->get()
                ->map(fn ($notification): array => [
                    'id' => $notification->id,
                    'title' => $notification->data['title'] ?? 'Notificação',
                    'body' => $notification->data['body'] ?? '',
                    'url' => $notification->data['url'] ?? null,
                    'contract' => $notification->data['contract'] ?? null,
                    'time' => $notification->created_at?->format('d/m H:i'),
                    'unread' => $notification->read_at === null,
                ])->values()->all(),
        ];
    }
}
