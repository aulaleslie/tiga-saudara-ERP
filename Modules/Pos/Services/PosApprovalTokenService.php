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

    public function validateToken(string $token, int $actingUserId, array $expected = []): PosActionApprovalToken
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

        $request = $approvalToken->approvalRequest;
        if (! $request || $request->status !== PosActionApprovalRequest::STATUS_APPROVED) {
            throw new DomainException('TOKEN_INVALID_OR_EXPIRED');
        }

        if (isset($expected['action_type']) && strcasecmp($request->action_type, $expected['action_type']) !== 0) {
            throw new DomainException('TOKEN_INVALID_OR_EXPIRED');
        }

        if (isset($expected['requested_by']) && (int) $request->requested_by !== (int) $expected['requested_by']) {
            throw new DomainException('TOKEN_INVALID_OR_EXPIRED');
        }

        if (isset($expected['pos_session_id']) && (int) $request->pos_session_id !== (int) $expected['pos_session_id']) {
            throw new DomainException('TOKEN_INVALID_OR_EXPIRED');
        }

        if (isset($expected['target_type']) && strcasecmp((string) $request->target_type, (string) $expected['target_type']) !== 0) {
            throw new DomainException('TOKEN_INVALID_OR_EXPIRED');
        }

        if (isset($expected['target_id']) && (int) $request->target_id !== (int) $expected['target_id']) {
            throw new DomainException('TOKEN_INVALID_OR_EXPIRED');
        }

        return $approvalToken;
    }

    /**
     * Consume a token exactly once.
     *
     * Consumption is a conditional update guarded by `consumed_at IS NULL` with
     * an affected-row check, never an unconditional write on a possibly stale
     * model. Two concurrent callers therefore cannot both succeed: the database
     * decides the winner, and the loser sees zero affected rows and fails. This
     * guard holds even when a caller reaches this method without first taking a
     * row lock.
     */
    public function consumeToken(PosActionApprovalToken|string $token, int $consumedBy, array $context = []): PosActionApprovalRequest
    {
        $approvalToken = $token instanceof PosActionApprovalToken
            ? $token
            : PosActionApprovalToken::query()->where('token_hash', $token)->with('approvalRequest')->first();

        if (! $approvalToken) {
            throw new DomainException('TOKEN_INVALID_OR_EXPIRED');
        }

        // Fail fast on an already-consumed token, but never rely on this read
        // for correctness — the conditional update below is the real guard.
        if ($approvalToken->consumed_at !== null) {
            throw new DomainException('TOKEN_ALREADY_USED');
        }

        $affected = PosActionApprovalToken::query()
            ->whereKey($approvalToken->getKey())
            ->whereNull('consumed_at')
            ->update([
                'consumed_at' => now(),
                'consumed_by' => $consumedBy,
                'consumed_context' => json_encode($context),
                'updated_at' => now(),
            ]);

        if ($affected !== 1) {
            // Another request consumed it between our read and this write.
            throw new DomainException('TOKEN_ALREADY_USED');
        }

        $approvalToken->refresh();

        $request = $approvalToken->approvalRequest;

        if (! $request) {
            throw new DomainException('TOKEN_INVALID_OR_EXPIRED');
        }

        $request->update(['status' => PosActionApprovalRequest::STATUS_CONSUMED]);

        return $request;
    }

    /**
     * Reload a token by its plaintext value and hold a row lock on it.
     *
     * Callers running inside a database transaction use this to serialize
     * competing executions of the same token before revalidating it.
     */
    public function lockTokenForUpdate(string $token): ?PosActionApprovalToken
    {
        $locked = PosActionApprovalToken::query()
            ->where('token_hash', $token)
            ->lockForUpdate()
            ->first();

        if ($locked) {
            // Load the request separately: eager loading alongside lockForUpdate
            // is not guaranteed to lock the related row.
            $locked->setRelation(
                'approvalRequest',
                PosActionApprovalRequest::query()
                    ->whereKey($locked->approval_request_id)
                    ->lockForUpdate()
                    ->first()
            );
        }

        return $locked;
    }

    public function validateAndConsume(string $token, int $consumedBy, array $context): PosActionApprovalRequest
    {
        $approvalToken = $this->validateToken($token, $consumedBy, $context);

        return $this->consumeToken($approvalToken, $consumedBy, $context);
    }
}
