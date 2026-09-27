<?php

namespace Modules\Pos\Services;

use App\Models\User;
use DomainException;
use Modules\Pos\Entities\PosActionApprovalRequest;

class PosCartTotalOverrideRequestService
{
    public function __construct(
        private readonly PosCartTotalAllocationService $allocationService,
        private readonly PosApprovalRequestService $approvalRequestService,
        private readonly PosCartActionAuthorizationService $authorizationService
    ) {
    }

    /**
     * Create a total-price override request or apply directly if user has permission.
     *
     * @param  int  $settingId
     * @param  int  $sessionId
     * @param  array<int, array{line_id:int, qty:int, unit_price:float, conversion_id?:int, bundle_id?:int, ...}>  $cartLines
     * @param  int  $targetTotalMinorUnits  Target total in minor units (Rp)
     * @param  string|null  $reason  Optional reason for the override
     * @param  User  $requester  User making the request
     * @return array{
     *     type: 'approved'|'request_created',
     *     request_id?: int,
     *     status?: string,
     *     allocation: array<int, array{line_id:int, allocated_line_total:int, effective_unit_price:float}>,
     *     fingerprint: string,
     *     source_total: int,
     *     target_total: int
     * }
     */
    public function createOrApplyOverride(
        int $settingId,
        int $sessionId,
        array $cartLines,
        int $targetTotalMinorUnits,
        ?string $reason,
        User $requester
    ): array {
        if (empty($cartLines)) {
            throw new DomainException('Cannot override total on an empty cart.');
        }

        if ($targetTotalMinorUnits < 0) {
            throw new DomainException('Target total must be non-negative.');
        }

        // Calculate source total
        $sourceTotal = 0;
        foreach ($cartLines as $line) {
            $lineTotal = isset($line['line_total']) ? (int) $line['line_total'] : (int) round($line['unit_price'] * $line['qty'] * 100);
            $sourceTotal += $lineTotal;
        }

        // Perform allocation
        $allocationResult = $this->allocationService->allocate($cartLines, $targetTotalMinorUnits);

        // Create request payload with full audit context
        $payload = [
            'source_total' => $sourceTotal,
            'target_total' => $targetTotalMinorUnits,
            'fingerprint' => $allocationResult['fingerprint'],
            'reason' => $reason,
            'allocation' => $allocationResult['allocated'],
        ];

        // Check authorization
        try {
            $auth = $this->authorizationService->authorize($requester, PosActionApprovalRequest::ACTION_TOTAL_PRICE_OVERRIDE);

            if ($auth['authorized']) {
                // User has direct permission - apply immediately
                return [
                    'type' => 'approved',
                    'allocation' => $allocationResult['allocated'],
                    'fingerprint' => $allocationResult['fingerprint'],
                    'source_total' => $sourceTotal,
                    'target_total' => $targetTotalMinorUnits,
                ];
            }
        } catch (DomainException $e) {
            // Only catch APPROVAL_REQUIRED exceptions; rethrow others
            if ($e->getMessage() !== 'APPROVAL_REQUIRED') {
                throw $e;
            }
            // Proceed to create request when APPROVAL_REQUIRED
        }

        // No direct permission - create approval request
        $request = $this->approvalRequestService->createRequest(
            $settingId,
            $sessionId,
            $requester,
            PosActionApprovalRequest::ACTION_TOTAL_PRICE_OVERRIDE,
            'pos_session',
            $sessionId,
            $payload
        );

        return [
            'type' => 'request_created',
            'request_id' => $request->id,
            'status' => $request->status,
            'allocation' => $allocationResult['allocated'],
            'fingerprint' => $allocationResult['fingerprint'],
            'source_total' => $sourceTotal,
            'target_total' => $targetTotalMinorUnits,
        ];
    }

    /**
     * Retrieve an override request by ID and validate fingerprint.
     *
     * @param  int  $requestId
     * @param  array<int, array{...}>  $currentCartLines
     * @return array{
     *     payload: array{source_total:int, target_total:int, fingerprint:string, reason:string|null, allocation:array},
     *     fingerprint_matches: bool
     * }
     */
    public function getRequest(int $requestId, array $currentCartLines): array
    {
        $request = PosActionApprovalRequest::query()
            ->where('id', $requestId)
            ->where('action_type', PosActionApprovalRequest::ACTION_TOTAL_PRICE_OVERRIDE)
            ->first();

        if (! $request) {
            throw new DomainException('Request not found or invalid action type.');
        }

        $payload = $request->request_payload;
        $currentFingerprint = $this->allocationService->generateFingerprint($currentCartLines);
        $fingerprintMatches = $currentFingerprint === $payload['fingerprint'];

        return [
            'payload' => $payload,
            'fingerprint_matches' => $fingerprintMatches,
        ];
    }
}
