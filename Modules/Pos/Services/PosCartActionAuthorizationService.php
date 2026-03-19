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
     * @return array{authorized: bool, reason: string|null, request: PosActionApprovalRequest|null}
     */
    public function authorize(User $user, string $actionType, ?string $approvalToken = null): array
    {
        $permission = match ($actionType) {
            PosActionApprovalRequest::ACTION_CART_CLEAR => 'pos.cart.clear',
            PosActionApprovalRequest::ACTION_LINE_REMOVE => 'pos.cart.line.remove',
            PosActionApprovalRequest::ACTION_QTY_REDUCE => 'pos.cart.line.reduce',
            PosActionApprovalRequest::ACTION_PRICE_OVERRIDE => 'pos.overrides.price',
            default => throw new DomainException('Invalid action type.'),
        };

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
}
