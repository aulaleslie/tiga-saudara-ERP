<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Services\Notification\NotificationFeedService;
use App\Services\Notification\NotificationService;
use App\Services\Notification\PermissionResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class NotificationController extends Controller
{
    protected NotificationFeedService $feedService;
    protected NotificationService $notificationService;
    protected PermissionResolver $permissionResolver;

    public function __construct(
        NotificationFeedService $feedService,
        NotificationService $notificationService,
        PermissionResolver $permissionResolver
    ) {
        $this->feedService = $feedService;
        $this->notificationService = $notificationService;
        $this->permissionResolver = $permissionResolver;
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
        $user = Auth::user();

        if ($notification->user_id !== $user->id) {
            abort(403);
        }

        if (!$this->permissionResolver->hasPermissionInSetting($user, $notification->setting_id, 'notifications.access')) {
            abort(403, 'Anda tidak memiliki akses ke notifikasi di bisnis ini.');
        }

        session(['setting_id' => (int) $notification->setting_id]);

        $userSettings = session('user_settings');
        if (!$userSettings || !$userSettings->contains('id', $notification->setting_id)) {
            if ($user->hasRole('Super Admin')) {
                session(['user_settings' => \Modules\Setting\Entities\Setting::orderBy('id')->get()]);
            } else {
                session(['user_settings' => $user->settings()->orderBy('settings.id')->get()]);
            }
        }

        $this->notificationService->markAsRead($notification->id, $user->id);

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
