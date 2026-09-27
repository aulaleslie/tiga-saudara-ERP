<?php

namespace Modules\Pos\Services;

use App\Models\User;
use DomainException;
use Modules\Pos\Entities\PosActionApprovalRequest;

class PosCartActionAuthorizationService
{
    public function __construct(
        private readonly PosApprovalTokenService $tokenService
    ) {
    }

    /**
     * Authorize action WITHOUT consuming the approval token.
     *
     * @return array{authorized: bool, reason: string|null, request: PosActionApprovalRequest|null, token_record: \Modules\Pos\Entities\PosActionApprovalToken|null}
     */
    public function authorizeWithoutConsuming(User $user, string $actionType, ?string $approvalToken = null, array $expected = []): array
    {
        // Super Admin users bypass all cart action restrictions
        if ($user->hasRole('Super Admin')) {
            return [
                'authorized' => true,
                'reason' => null,
                'request' => null,
                'token_record' => null,
            ];
        }

        $permission = $this->permissionForAction($actionType);

        $hasDirectPermission = $user->can($permission);

        if ($hasDirectPermission) {
            return [
                'authorized' => true,
                'reason' => null,
                'request' => null,
                'token_record' => null,
            ];
        }

        if ($approvalToken !== null && $approvalToken !== '') {
            $expectedContext = array_merge(['action_type' => $actionType], $expected);
            $tokenRecord = $this->tokenService->validateToken($approvalToken, $user->id, $expectedContext);

            return [
                'authorized' => true,
                'reason' => null,
                'request' => $tokenRecord->approvalRequest,
                'token_record' => $tokenRecord,
            ];
        }

        throw new DomainException('APPROVAL_REQUIRED');
    }

    /**
     * @return array{authorized: bool, reason: string|null, request: PosActionApprovalRequest|null}
     */
    public function authorize(User $user, string $actionType, ?string $approvalToken = null): array
    {
        // Super Admin users bypass all cart action restrictions
        if ($user->hasRole('Super Admin')) {
            return [
                'authorized' => true,
                'reason' => null,
                'request' => null,
            ];
        }

        $permission = $this->permissionForAction($actionType);

        $hasDirectPermission = $user->can($permission);

        if ($hasDirectPermission) {
            return [
                'authorized' => true,
                'reason' => null,
                'request' => null,
            ];
        }

        if ($approvalToken !== null && $approvalToken !== '') {
            try {
                $request = $this->tokenService->validateAndConsume($approvalToken, $user->id, [
                    'action_type' => $actionType,
                ]);

                if ($request->action_type !== $actionType) {
                    throw new DomainException('TOKEN_INVALID_OR_EXPIRED');
                }

                return [
                    'authorized' => true,
                    'reason' => null,
                    'request' => $request,
                ];

            } catch (DomainException $e) {
                throw new DomainException($e->getMessage());
            }
        }

        throw new DomainException('APPROVAL_REQUIRED');
    }

    /**
     * Map an active action type to its direct permission.
     *
     * Retired action types are rejected outright: historical records remain
     * readable, but a legacy `PRICE_OVERRIDE` or `TOTAL_PRICE_OVERRIDE` must
     * never authorize a new operation.
     */
    private function permissionForAction(string $actionType): string
    {
        if (PosActionApprovalRequest::isRetiredAction($actionType)) {
            throw new DomainException('ACTION_RETIRED');
        }

        return match ($actionType) {
            PosActionApprovalRequest::ACTION_CART_CLEAR => 'pos.cart.clear',
            PosActionApprovalRequest::ACTION_LINE_REMOVE => 'pos.cart.line.remove',
            PosActionApprovalRequest::ACTION_QTY_REDUCE => 'pos.cart.line.reduce',
            // Both active row overrides share one direct permission.
            PosActionApprovalRequest::ACTION_LINE_UNIT_PRICE_OVERRIDE => 'pos.overrides.price',
            PosActionApprovalRequest::ACTION_LINE_TOTAL_OVERRIDE => 'pos.overrides.price',
            PosActionApprovalRequest::ACTION_TRANSACTION_CANCEL => 'pos.void',
            PosActionApprovalRequest::ACTION_CHECKOUT_AS_DEBT => 'pos.checkout.debt',
            default => throw new DomainException('Invalid action type.'),
        };
    }
}
