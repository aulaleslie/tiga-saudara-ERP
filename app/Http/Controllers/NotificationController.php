<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Services\Notification\NotificationFeedService;
use App\Services\Notification\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class NotificationController extends Controller
{
    protected NotificationFeedService $feedService;
    protected NotificationService $notificationService;

    public function __construct(NotificationFeedService $feedService, NotificationService $notificationService)
    {
        $this->feedService = $feedService;
        $this->notificationService = $notificationService;
    }

    /**
     * Display a listing of the notifications.
     */
    public function index()
    {
        $notifications = $this->feedService->getPaginatedIndex(Auth::id());

        return view('notifications.index', compact('notifications'));
    }

    /**
     * Redirect to the action URL and mark as read.
     */
    public function read(Notification $notification)
    {
        if ($notification->user_id !== Auth::id()) {
            abort(403);
        }

        $this->notificationService->markAsRead($notification->id, Auth::id());

        if ($notification->action_url) {
            return redirect($notification->action_url);
        }

        return redirect()->back();
    }

    /**
     * Mark all visible unresolved notifications as read.
     */
    public function markAllAsRead()
    {
        $this->notificationService->markAllAsRead(Auth::id());

        return redirect()->back()->with('toast_success', 'Semua notifikasi telah ditandai sebagai dibaca.');
    }
}
