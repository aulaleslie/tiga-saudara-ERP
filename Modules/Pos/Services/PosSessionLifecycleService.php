<?php

namespace Modules\Pos\Services;

use App\Models\User;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Setting\Entities\SettingSaleLocation;
use Modules\Setting\Entities\SettingPosPaymentMethod;
use Modules\Pos\Entities\PosSessionCashEvent;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Services\PosCashDrawerService;

class PosSessionLifecycleService
{
    public function __construct(
        private readonly PosTerminalRuntimeResolver $terminalResolver,
        private readonly PosCashDrawerService $cashDrawerService,
        private readonly PosRolePolicyService $rolePolicyService
    ) {
    }

    public function openSession(
        int $settingId,
        ?int $terminalId,
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
            $cashier = User::query()->find($cashierUserId);
            if (! $cashier) {
                throw new DomainException('Cashier user was not found.');
            }

            $normalizedTerminalId = $terminalId !== null && $terminalId > 0
                ? (int) $terminalId
                : null;

            $requiresTerminalSelection = $this->rolePolicyService->requiresTerminalSelection($cashier);
            if ($requiresTerminalSelection && $normalizedTerminalId === null) {
                throw new DomainException('Pilih terminal sebelum membuka sesi POS.');
            }

            $hasConfiguredSaleLocations = SettingSaleLocation::query()
                ->where('setting_id', $settingId)
                ->where('is_enabled', true)
                ->exists();

            if (! $hasConfiguredSaleLocations) {
                throw new DomainException('Configure at least one sales location before opening a POS session.');
            }

            $hasEnabledPayments = SettingPosPaymentMethod::query()
                ->where('setting_id', $settingId)
                ->where('is_enabled', true)
                ->exists();

            if (! $hasEnabledPayments) {
                throw new DomainException('Configure at least one payment method before opening a POS session.');
            }

            $openingTotal = $normalizedTerminalId !== null
                ? round($openingFloatTotal, 2)
                : 0;

            if ($normalizedTerminalId !== null && $openingTotal <= 0) {
                throw new DomainException('Opening float total must be greater than zero.');
            }

            $activeSessionForUser = PosSession::query()
                ->where('setting_id', $settingId)
                ->where('cashier_user_id', $cashierUserId)
                ->active()
                ->lockForUpdate()
                ->first();

            if ($activeSessionForUser) {
                $existingTerminalId = $activeSessionForUser->terminal_id !== null
                    ? (int) $activeSessionForUser->terminal_id
                    : null;

                if ($existingTerminalId === $normalizedTerminalId) {
                    return $activeSessionForUser;
                }

                $activeSessionForUser->loadMissing('terminal:id,code,name');
                $terminalContext = $activeSessionForUser->terminal
                    ? ($activeSessionForUser->terminal->code . ' (' . $activeSessionForUser->terminal->name . ')')
                    : 'tanpa terminal';

                throw new DomainException(
                    "Anda sudah memiliki sesi POS aktif #{$activeSessionForUser->id} pada {$terminalContext}. " .
                    'Lanjutkan sesi aktif atau tutup sesi tersebut terlebih dahulu.'
                );
            }

            $terminal = null;

            if ($normalizedTerminalId !== null) {
                $terminal = $this->terminalResolver->resolveForSessionOpen($settingId, $normalizedTerminalId);
                $terminalPolicy = $terminal->policy;

                if ((bool) ($terminalPolicy?->require_opening_float ?? false)) {
                    $thresholdValue = $terminalPolicy?->cash_threshold;

                    if ($thresholdValue === null) {
                        throw new DomainException(
                            'Terminal cash threshold must be configured before opening a POS session.'
                        );
                    }

                    if ($openingTotal <= (float) $thresholdValue) {
                        throw new DomainException(
                            'Opening float total must be greater than terminal cash threshold.'
                        );
                    }
                }

                $activeSessionForTerminal = PosSession::query()
                    ->where('setting_id', $settingId)
                    ->where('terminal_id', $normalizedTerminalId)
                    ->active()
                    ->lockForUpdate()
                    ->first();

                if ($activeSessionForTerminal) {
                    $activeSessionForTerminal->loadMissing('cashier:id,name');
                    $ownerName = $activeSessionForTerminal->cashier?->name ?? 'pengguna lain';
                    $terminalLabel = $terminal->code . ' (' . $terminal->name . ')';

                    throw new DomainException(
                        "Terminal {$terminalLabel} sedang digunakan oleh {$ownerName} " .
                        "(sesi #{$activeSessionForTerminal->id}). Pilih terminal lain atau tutup sesi tersebut."
                    );
                }
            }

            $openedByUserId = $openedBy ?: $cashierUserId;

            try {
                $session = PosSession::query()->create([
                    'setting_id' => $settingId,
                    'terminal_id' => $normalizedTerminalId,
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
                    'denominations' => null,
                    'performed_by' => $openedByUserId,
                    'notes' => $notes,
                    'metadata' => null,
                    'occurred_at' => now(),
                ]);

                if ($normalizedTerminalId !== null) {
                    $this->cashDrawerService->triggerDrawerOpen(
                        PosCashDrawerService::TRIGGER_SESSION_OPEN,
                        $normalizedTerminalId,
                        $settingId,
                        [
                            'pos_session_id' => $session->id,
                            'cash_event_id' => $cashEvent->id,
                        ],
                        $terminal
                    );
                }

                return $session;
            } catch (QueryException $exception) {
                if ($this->isUniqueConstraintViolation($exception)) {
                    throw new DomainException('Konflik sesi POS aktif terdeteksi. Muat ulang halaman lalu coba lagi.');
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


}
