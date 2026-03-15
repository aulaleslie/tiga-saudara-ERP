<?php

namespace Modules\Pos\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Modules\Pos\Entities\PosSupervisorApproval;

class PosSupervisorApprovalService
{
    /**
     * @return array{
     *     approval_id:int,
     *     approved:bool,
     *     approved_by:int|null,
     *     approval_result:string,
     *     reason:string|null
     * }
     */
    public function approvePriceOverride(
        int $settingId,
        int $targetSessionId,
        int $requestedBy,
        string $supervisorIdentifier,
        string $supervisorPin,
        int $lineId,
        float $fromUnitPrice,
        float $toUnitPrice
    ): array {
        return $this->approveSessionAction(
            $settingId,
            $targetSessionId,
            $requestedBy,
            $supervisorIdentifier,
            $supervisorPin,
            PosSupervisorApproval::ACTION_PRICE_OVERRIDE,
            ['pos.overrides.price', 'pos.supervisor.approval'],
            [
                'line_id' => $lineId,
                'from_unit_price' => round($fromUnitPrice, 2),
                'to_unit_price' => round($toUnitPrice, 2),
            ]
        );
    }

    /**
     * @return array{
     *     approval_id:int,
     *     approved:bool,
     *     approved_by:int|null,
     *     approval_result:string,
     *     reason:string|null
     * }
     */
    public function approveSafeDrop(
        int $settingId,
        int $targetSessionId,
        int $requestedBy,
        string $supervisorIdentifier,
        string $supervisorPin
    ): array {
        return $this->approveSessionAction(
            $settingId,
            $targetSessionId,
            $requestedBy,
            $supervisorIdentifier,
            $supervisorPin,
            PosSupervisorApproval::ACTION_SAFE_DROP_APPROVAL,
            ['pos.safeDrops.approve', 'pos.supervisor.approval']
        );
    }

    /**
     * @return array{
     *     approval_id:int,
     *     approved:bool,
     *     approved_by:int|null,
     *     approval_result:string,
     *     reason:string|null
     * }
     */
    public function approveSessionCloseVariance(
        int $settingId,
        int $targetSessionId,
        int $requestedBy,
        string $supervisorIdentifier,
        string $supervisorPin
    ): array {
        return $this->approveSessionAction(
            $settingId,
            $targetSessionId,
            $requestedBy,
            $supervisorIdentifier,
            $supervisorPin,
            PosSupervisorApproval::ACTION_SESSION_CLOSE_VARIANCE_APPROVAL,
            ['pos.sessions.close', 'pos.supervisor.approval']
        );
    }

    /**
     * @return array{
     *     approval_id:int,
     *     approved:bool,
     *     approved_by:int|null,
     *     approval_result:string,
     *     reason:string
     * }
     */
    private function recordRejected(
        string $actionType,
        int $settingId,
        int $targetSessionId,
        int $requestedBy,
        string $reason,
        string $supervisorIdentifier,
        array $context = []
    ): array {
        $approval = PosSupervisorApproval::query()->create([
            'setting_id' => $settingId,
            'action_type' => $actionType,
            'target_type' => 'pos_session',
            'target_id' => $targetSessionId,
            'requested_by' => $requestedBy,
            'approved_by' => null,
            'approval_result' => PosSupervisorApproval::RESULT_REJECTED,
            'reason' => $reason,
            'context_snapshot' => array_merge([
                'supervisor_identifier' => $supervisorIdentifier,
            ], $context),
            'occurred_at' => now(),
        ]);

        return [
            'approval_id' => (int) $approval->id,
            'approved' => false,
            'approved_by' => null,
            'approval_result' => PosSupervisorApproval::RESULT_REJECTED,
            'reason' => $reason,
        ];
    }

    /**
     * @param  array<int, string>  $requiredPermissions
     * @return array{
     *     approval_id:int,
     *     approved:bool,
     *     approved_by:int|null,
     *     approval_result:string,
     *     reason:string|null
     * }
     */
    private function approveSessionAction(
        int $settingId,
        int $targetSessionId,
        int $requestedBy,
        string $supervisorIdentifier,
        string $supervisorPin,
        string $actionType,
        array $requiredPermissions,
        array $context = []
    ): array {
        $supervisor = User::query()
            ->where('email', $supervisorIdentifier)
            ->first();

        if (! $supervisor || ! (bool) $supervisor->is_active) {
            return $this->recordRejected(
                $actionType,
                $settingId,
                $targetSessionId,
                $requestedBy,
                'INVALID_CREDENTIALS',
                $supervisorIdentifier,
                $context
            );
        }

        // Super Admin can access any setting, otherwise verify setting membership
        if (! $supervisor->hasRole('Super Admin')) {
            $belongsToSetting = $supervisor->settings()
                ->where('setting_id', $settingId)
                ->exists();

            if (! $belongsToSetting) {
                return $this->recordRejected(
                    $actionType,
                    $settingId,
                    $targetSessionId,
                    $requestedBy,
                    'INVALID_CREDENTIALS',
                    $supervisorIdentifier,
                    $context
                );
            }
        }

        if (! Hash::check($supervisorPin, (string) $supervisor->password)) {
            return $this->recordRejected(
                $actionType,
                $settingId,
                $targetSessionId,
                $requestedBy,
                'INVALID_CREDENTIALS',
                $supervisorIdentifier,
                $context
            );
        }

        // Super Admin can do anything
        if (! $supervisor->hasRole('Super Admin')) {
            foreach ($requiredPermissions as $permission) {
                if (! $supervisor->can($permission)) {
                    return $this->recordRejected(
                        $actionType,
                        $settingId,
                        $targetSessionId,
                        $requestedBy,
                        'MISSING_PERMISSION',
                        $supervisorIdentifier,
                        $context
                    );
                }
            }
        }

        $approval = PosSupervisorApproval::query()->create([
            'setting_id' => $settingId,
            'action_type' => $actionType,
            'target_type' => 'pos_session',
            'target_id' => $targetSessionId,
            'requested_by' => $requestedBy,
            'approved_by' => $supervisor->id,
            'approval_result' => PosSupervisorApproval::RESULT_APPROVED,
            'reason' => null,
            'context_snapshot' => array_merge([
                'supervisor_identifier' => $supervisorIdentifier,
            ], $context),
            'occurred_at' => now(),
        ]);

        return [
            'approval_id' => (int) $approval->id,
            'approved' => true,
            'approved_by' => (int) $supervisor->id,
            'approval_result' => PosSupervisorApproval::RESULT_APPROVED,
            'reason' => null,
        ];
    }
}
