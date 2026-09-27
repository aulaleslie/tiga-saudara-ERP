<?php

namespace Modules\Pos\Services;

use App\Models\User;

class PosSupervisorSearchService
{
    /**
     * Search for eligible supervisors (active, TOTP enabled, correct permissions)
     * 
     * @return array{
     *     query:string,
     *     results:array<int, array{id:int, name:string, email:string}>,
     *     meta:array{limit:int,result_count:int}
     * }
     */
    public function search(int $settingId, string $query, int $limit = 10): array
    {
        $normalizedQuery = trim($query);
        $safeLimit = max(1, min(20, $limit));

        if ($normalizedQuery === '') {
            return [
                'query' => $query,
                'results' => [],
                'meta' => [
                    'limit' => $safeLimit,
                    'result_count' => 0,
                ],
            ];
        }

        $rows = User::query()
            ->where('is_active', true)
            ->where('two_factor_secret', '!=', null)
            ->whereNotNull('two_factor_confirmed_at')
            ->where(function ($queryBuilder) use ($normalizedQuery) {
                $queryBuilder->where('name', 'like', '%' . $normalizedQuery . '%')
                    ->orWhere('email', 'like', '%' . $normalizedQuery . '%');
            })
            ->orderBy('name')
            ->orderBy('email')
            ->orderBy('id')
            ->limit($safeLimit)
            ->get(['id', 'name', 'email']);

        // Filter by setting (Super Admin bypasses)
        $results = $rows->filter(function (User $user) use ($settingId) {
            if ($user->hasRole('Super Admin')) {
                return true;
            }
            
            return $user->settings()
                ->where('setting_id', $settingId)
                ->exists();
        })
        ->filter(function (User $user) {
            // Check permissions: must have both pos.safeDrops.approve and pos.supervisor.approval
            if ($user->hasRole('Super Admin')) {
                return true;
            }
            
            return $user->can('pos.safeDrops.approve') && $user->can('pos.supervisor.approval');
        })
        ->values()
        ->map(function (User $user) {
            return [
                'id' => (int) $user->id,
                'name' => (string) $user->name,
                'email' => (string) $user->email,
            ];
        })
        ->toArray();

        return [
            'query' => $query,
            'results' => array_slice($results, 0, $safeLimit),
            'meta' => [
                'limit' => $safeLimit,
                'result_count' => count(array_slice($results, 0, $safeLimit)),
            ],
        ];
    }
}
