<?php

namespace Modules\Pos\Services;

use DomainException;
use Illuminate\Support\Str;
use Modules\Pos\Entities\PosActionApprovalRequest;
use Modules\Pos\Entities\PosActionApprovalToken;

class PosApprovalTokenService
{
    public function issueToken(PosActionApprovalRequest $request, int $ttlMinutes = 10): string
    {
        $token = Str::random(32);
        
        PosActionApprovalToken::create([
            'approval_request_id' => $request->id,
            'token_hash' => $token, // Stored in plain text since cashier polls for it
            'expires_at' => now()->addMinutes($ttlMinutes),
        ]);

        return $token;
    }

    public function validateAndConsume(string $token, int $consumedBy, array $context): PosActionApprovalRequest
    {
        $approvalToken = PosActionApprovalToken::query()
            ->where('token_hash', $token)
            ->with('approvalRequest')
            ->first();

        if (! $approvalToken) {
            throw new DomainException('TOKEN_INVALID_OR_EXPIRED');
        }

        if ($approvalToken->consumed_at !== null) {
            throw new DomainException('TOKEN_ALREADY_USED');
        }

        if ($approvalToken->expires_at->isPast()) {
            $approvalToken->approvalRequest->update(['status' => PosActionApprovalRequest::STATUS_EXPIRED]);
            throw new DomainException('TOKEN_INVALID_OR_EXPIRED');
        }

        $approvalToken->update([
            'consumed_at' => now(),
            'consumed_by' => $consumedBy,
            'consumed_context' => $context,
        ]);

        $request = $approvalToken->approvalRequest;
        $request->update(['status' => PosActionApprovalRequest::STATUS_CONSUMED]);

        return $request;
    }
}
