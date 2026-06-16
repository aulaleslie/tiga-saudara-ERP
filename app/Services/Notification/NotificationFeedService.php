<?php

namespace App\Services\Notification;

use App\Models\Notification;

class NotificationFeedService
{
    /**
     * Get the unread, unresolved count for a user across all businesses.
     */
    public function getUnreadCount(int $userId): int
    {
        return Notification::where('user_id', $userId)
            ->unresolved()
            ->unread()
            ->count();
    }

    /**
     * Get header dropdown items:
     * Max 10 unresolved notifications.
     * Order by: unread first, then read, with newest first within each group.
     */
    public function getHeaderDropdownItems(int $userId, int $limit = 10)
    {
        return Notification::where('user_id', $userId)
            ->unresolved()
            ->orderByRaw('read_at IS NOT NULL') // MySQL/SQLite treats boolean expression (NULL is false, NOT NULL is true). Unread (read_at is null) comes first.
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get paginated items for the notification index page:
     * All visible notifications (unresolved + resolved).
     * Order by: unread first, then read, with newest first within each group.
     */
    public function getPaginatedIndex(int $userId, int $perPage = 15)
    {
        return Notification::where('user_id', $userId)
            ->orderByRaw('read_at IS NOT NULL')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }
}
