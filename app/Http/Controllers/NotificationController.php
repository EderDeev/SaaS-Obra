<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class NotificationController extends Controller
{
    public function read(Request $request, string $notification): Response
    {
        $userNotification = $request->user()
            ->notifications()
            ->whereKey($notification)
            ->firstOrFail();

        $userNotification->markAsRead();

        return response()->noContent();
    }

    public function readHidden(Request $request): JsonResponse
    {
        $data = $request->validate([
            'visible_ids' => ['present', 'array', 'max:100'],
            'visible_ids.*' => ['string', 'uuid'],
        ]);

        $query = $request->user()->unreadNotifications();

        if ($data['visible_ids'] !== []) {
            $query->whereNotIn('id', $data['visible_ids']);
        }

        $markedCount = $query->update([
            'read_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'marked_count' => $markedCount,
        ]);
    }
}
