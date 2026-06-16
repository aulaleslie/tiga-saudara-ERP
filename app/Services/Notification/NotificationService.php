<?php

namespace App\Services\Notification;

use App\Models\Notification;
use Illuminate\Support\Carbon;

class NotificationService
{
    /**
     * Create or update an unresolved notification row by its deterministic fingerprint.
     * Prevents duplicating active notifications.
     */
    public function write(array $data): Notification
    {
        // Unresolved means resolved_at is null.
        // We look for an existing unresolved notification with the same fingerprint.
        $existing = Notification::where('fingerprint', $data['fingerprint'])
            ->unresolved()
            ->first();

        if ($existing) {
            // Update the existing notification with new message/title/metadata if needed,
            // and optionally mark it unread again if it was read.
            $existing->update([
                'title' => $data['title'] ?? $existing->title,
                'message' => $data['message'] ?? $existing->message,
                'action_url' => $data['action_url'] ?? $existing->action_url,
                'metadata' => isset($data['metadata']) ? array_merge($existing->metadata ?? [], $data['metadata']) : $existing->metadata,
                'read_at' => null, // Bring it back to unread to alert the user again
            ]);

            return $existing;
        }

        // Create a new notification
        return Notification::create([
            'user_id' => $data['user_id'],
            'setting_id' => $data['setting_id'],
            'location_id' => $data['location_id'] ?? null,
            'category' => $data['category'],
            'type' => $data['type'],
            'title' => $data['title'],
            'message' => $data['message'],
            'source_type' => $data['source_type'] ?? null,
            'source_id' => $data['source_id'] ?? null,
            'fingerprint' => $data['fingerprint'],
            'action_url' => $data['action_url'] ?? null,
            'metadata' => $data['metadata'] ?? null,
            'read_at' => null,
            'resolved_at' => null,
        ]);
    }

    /**
     * Resolve notifications by given conditions.
     */
    public function resolveBy(array $conditions): int
    {
        if (empty($conditions)) {
            return 0; // Prevent accidental mass resolution
        }

        $query = Notification::unresolved();

        foreach ($conditions as $column => $value) {
            $query->where($column, $value);
        }

        return $query->update(['resolved_at' => Carbon::now()]);
    }

    /**
     * Helper to resolve by category, source type, and source id.
     */
    public function resolveBySource(string $category, string $sourceType, $sourceId, ?int $settingId = null, ?int $locationId = null): int
    {
        $conditions = [
            'category' => $category,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
        ];

        if ($settingId !== null) {
            $conditions['setting_id'] = $settingId;
        }

        if ($locationId !== null) {
            $conditions['location_id'] = $locationId;
        }

        return $this->resolveBy($conditions);
    }

    /**
     * Mark a single notification as read for a specific user.
     */
    public function markAsRead(int $notificationId, int $userId): bool
    {
        $notification = Notification::where('id', $notificationId)
            ->where('user_id', $userId)
            ->first();

        if ($notification && is_null($notification->read_at)) {
            $notification->update(['read_at' => Carbon::now()]);
            return true;
        }

        return false;
    }

    /**
     * Mark all visible unresolved notifications as read for a user.
     * Often scoped to the user's accessible businesses, but here we just
     * mark all unresolved for this user as read.
     */
    public function markAllAsRead(int $userId): int
    {
        return Notification::where('user_id', $userId)
            ->unresolved()
            ->whereNull('read_at')
            ->update(['read_at' => Carbon::now()]);
    }
}
