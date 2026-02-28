<?php

namespace Modules\Pos\Services;

use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Setting\Entities\SettingSaleLocation;
use Modules\Pos\Entities\PosSessionCashEvent;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Services\PosCashDrawerService;

class PosSessionLifecycleService
{
    public function __construct(
        private readonly PosTerminalRuntimeResolver $terminalResolver,
        private readonly PosCashDrawerService $cashDrawerService
    ) {
    }

    public function openSession(
        int $settingId,
        int $terminalId,
        int $cashierUserId,
        float $openingFloatTotal,
        ?array $openingDenominations = null,
        ?int $openedBy = null,
        ?string $notes = null,
        ?array $metadata = null
    ): PosSession {
        return DB::transaction(function () use (
            $settingId,
            $terminalId,
            $cashierUserId,
            $openingFloatTotal,
            $openingDenominations,
            $openedBy,
            $notes,
            $metadata
        ) {
            $hasConfiguredSaleLocations = SettingSaleLocation::query()
                ->where('setting_id', $settingId)
                ->exists();

            if (! $hasConfiguredSaleLocations) {
                throw new DomainException('Configure at least one sales location before opening a POS session.');
            }

            $terminal = $this->terminalResolver->resolveForSessionOpen($settingId, $terminalId);

            $openingTotal = round($openingFloatTotal, 2);

            if ($openingTotal <= 0) {
                throw new DomainException('Opening float total must be greater than zero.');
            }

            $normalizedDenominations = $this->normalizeDenominations($openingDenominations);

            $allowTotalOnly = (bool) $terminal->policy->allow_total_only_float_input;

            if (! $allowTotalOnly && empty($normalizedDenominations)) {
                throw new DomainException('Opening denominations are required for this terminal.');
            }

            if (! empty($normalizedDenominations)) {
                $denominationTotal = $this->calculateDenominationTotal($normalizedDenominations);

                if (abs($denominationTotal - $openingTotal) > 0.0001) {
                    throw new DomainException('Opening denomination total must match opening float total.');
                }
            }

            $existingSession = PosSession::query()
                ->where('setting_id', $settingId)
                ->where('terminal_id', $terminalId)
                ->where('cashier_user_id', $cashierUserId)
                ->active()
                ->lockForUpdate()
                ->first();

            if ($existingSession) {
                throw new DomainException('An active POS session already exists for this cashier and terminal.');
            }

            $openedByUserId = $openedBy ?: $cashierUserId;

            try {
                $session = PosSession::query()->create([
                    'setting_id' => $settingId,
                    'terminal_id' => $terminalId,
                    'cashier_user_id' => $cashierUserId,
                    'status' => PosSession::STATUS_OPEN,
                    'opened_at' => now(),
                    'opened_by' => $openedByUserId,
                    'opening_float_total' => $openingTotal,
                    'expected_cash_total' => $openingTotal,
                    'metadata' => $metadata,
                    'active_marker' => PosSession::activeMarkerForStatus(PosSession::STATUS_OPEN),
                ]);

                $cashEvent = PosSessionCashEvent::query()->create([
                    'setting_id' => $settingId,
                    'pos_session_id' => $session->id,
                    'event_type' => PosSessionCashEvent::EVENT_OPEN_FLOAT,
                    'direction' => PosSessionCashEvent::DIRECTION_IN,
                    'amount' => $openingTotal,
                    'denominations' => empty($normalizedDenominations) ? null : $normalizedDenominations,
                    'performed_by' => $openedByUserId,
                    'notes' => $notes,
                    'metadata' => null,
                    'occurred_at' => now(),
                ]);

                $this->cashDrawerService->triggerDrawerOpen(
                    PosCashDrawerService::TRIGGER_SESSION_OPEN,
                    $terminalId,
                    $settingId,
                    [
                        'pos_session_id' => $session->id,
                        'cash_event_id' => $cashEvent->id,
                    ]
                );

                return $session;
            } catch (QueryException $exception) {
                if ($this->isUniqueConstraintViolation($exception)) {
                    throw new DomainException('An active POS session already exists for this cashier and terminal.');
                }

                throw $exception;
            }
        });
    }

    public function startClosing(int $sessionId, int $actingUserId): PosSession
    {
        return DB::transaction(function () use ($sessionId, $actingUserId) {
            $session = PosSession::query()
                ->lockForUpdate()
                ->find($sessionId);

            if (! $session) {
                throw new DomainException('POS session not found.');
            }

            if ($session->status !== PosSession::STATUS_OPEN) {
                throw new DomainException('POS session can only move to CLOSING from OPEN status.');
            }

            $session->status = PosSession::STATUS_CLOSING;
            $session->active_marker = PosSession::activeMarkerForStatus(PosSession::STATUS_CLOSING);
            $session->save();

            return $session->refresh();
        });
    }

    public function finalizeClosing(
        int $sessionId,
        int $actingUserId,
        ?float $countedCashTotal = null,
        ?string $closeNotes = null,
        ?int $closeApprovedBy = null
    ): PosSession {
        return DB::transaction(function () use ($sessionId, $actingUserId, $countedCashTotal, $closeNotes, $closeApprovedBy) {
            $session = PosSession::query()
                ->lockForUpdate()
                ->find($sessionId);

            if (! $session) {
                throw new DomainException('POS session not found.');
            }

            if ($session->status !== PosSession::STATUS_CLOSING) {
                throw new DomainException('POS session can only move to CLOSED from CLOSING status.');
            }

            $normalizedCounted = $countedCashTotal !== null ? round($countedCashTotal, 2) : null;
            $variance = $normalizedCounted !== null
                ? round($normalizedCounted - (float) $session->expected_cash_total, 2)
                : null;

            $session->status = PosSession::STATUS_CLOSED;
            $session->closed_at = now();
            $session->closed_by = $actingUserId;
            $session->counted_cash_total = $normalizedCounted;
            $session->variance_total = $variance;
            $session->active_marker = PosSession::activeMarkerForStatus(PosSession::STATUS_CLOSED);

            if ($closeNotes !== null) {
                $session->close_notes = $closeNotes;
            }

            if ($closeApprovedBy !== null) {
                $session->close_approved_by = $closeApprovedBy;
                $session->close_approved_at = now();
            }

            $session->save();

            return $session->refresh();
        });
    }

    public function getActiveSessionForCashier(int $settingId, int $cashierUserId): ?PosSession
    {
        return PosSession::query()
            ->forCashier($settingId, $cashierUserId)
            ->active()
            ->orderByDesc('opened_at')
            ->orderByDesc('id')
            ->first();
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $message = strtolower($exception->getMessage());

        return in_array($sqlState, ['23000', '23505'], true)
            || str_contains($message, 'unique')
            || str_contains($message, 'duplicate');
    }

    private function normalizeDenominations(?array $openingDenominations): array
    {
        if (! is_array($openingDenominations) || empty($openingDenominations)) {
            return [];
        }

        $normalized = [];

        foreach ($openingDenominations as $denomination => $quantity) {
            if (! is_numeric($denomination)) {
                continue;
            }

            if (! is_numeric($quantity)) {
                continue;
            }

            $denominationValue = (int) $denomination;
            $quantityValue = (int) $quantity;

            if ($denominationValue <= 0 || $quantityValue <= 0) {
                continue;
            }

            $normalized[(string) $denominationValue] = $quantityValue;
        }

        krsort($normalized, SORT_NUMERIC);

        return $normalized;
    }

    private function calculateDenominationTotal(array $normalizedDenominations): float
    {
        $total = 0.0;

        foreach ($normalizedDenominations as $denomination => $quantity) {
            $total += ((float) $denomination) * ((int) $quantity);
        }

        return round($total, 2);
    }
}
