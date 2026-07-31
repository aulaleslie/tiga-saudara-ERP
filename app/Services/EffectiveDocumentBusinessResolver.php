<?php

namespace App\Services;

use Illuminate\Contracts\Auth\Guard;
use Modules\Setting\Entities\Setting;

class EffectiveDocumentBusinessResolver
{
    public function __construct(
        private Guard $auth,
    ) {}

    /**
     * Resolve the effective document business with authorization checks.
     *
     * @param int|null $targetSettingId The requested target setting ID (null means use session)
     * @return array{setting_id: int, is_pkp: bool} The resolved setting ID and PKP state
     * @throws \Illuminate\Auth\Access\AuthorizationException If the target business is inaccessible
     */
    public function resolve(?int $targetSettingId = null): array
    {
        $user = $this->auth->user();
        if (!$user) {
            throw new \Illuminate\Auth\AuthenticationException();
        }

        $currentSessionSettingId = session('setting_id');
        $requestedSettingId = $targetSettingId ?? $currentSessionSettingId;

        // If requesting the active setting, allow it regardless of override permission
        if ($requestedSettingId === $currentSessionSettingId) {
            $setting = Setting::findOrFail($requestedSettingId);
            return [
                'setting_id' => $setting->id,
                'is_pkp' => (bool) $setting->is_pkp,
            ];
        }

        // Super Admin has access to every business; other users require the
        // explicit document-business override permission.
        if (! $user->hasRole('Super Admin') && ! $user->hasPermissionTo('documents.business.override')) {
            throw new \Illuminate\Auth\Access\AuthorizationException(
                'User does not have permission to select an alternate business.'
            );
        }

        // Resolve accessible settings for the user
        $accessibleSettingIds = $this->getAccessibleSettingIds($user);
        if (!in_array($requestedSettingId, $accessibleSettingIds)) {
            throw new \Illuminate\Auth\Access\AuthorizationException(
                'The selected business is not accessible to the authenticated user.'
            );
        }

        // Load and return the resolved setting
        $setting = Setting::findOrFail($requestedSettingId);
        return [
            'setting_id' => $setting->id,
            'is_pkp' => (bool) $setting->is_pkp,
        ];
    }

    /**
     * Get all accessible setting IDs for the authenticated user.
     *
     * For Super Admin, returns all settings; otherwise returns user-assigned settings.
     *
     * @param \App\Models\User $user
     * @return array<int>
     */
    private function getAccessibleSettingIds($user): array
    {
        if ($user->hasRole('Super Admin')) {
            return Setting::pluck('id')->toArray();
        }

        return $user->settings()->pluck('setting_id')->toArray();
    }
}
