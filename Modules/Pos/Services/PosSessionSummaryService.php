<?php

namespace Modules\Pos\Services;

use DomainException;
use Modules\Pos\Entities\PosSession;

class PosSessionSummaryService
{
    public function __construct(private readonly PosSessionExpectedCashCalculator $expectedCashCalculator)
    {
    }

    /**
     * @return array{
     *     session_id:int,
     *     status:string,
     *     cashier_user_id:int,
     *     terminal_id:int,
     *     expected_cash_total:float,
     *     cached_expected_cash_total:float,
     *     threshold_value:float,
     *     threshold_source:string,
     *     is_threshold_breached:bool,
     *     events_count:int,
     *     calculated_at:string
     * }
     */
    public function getSummary(int $sessionId, int $actorUserId, int $settingId): array
    {
        if ($actorUserId <= 0) {
            throw new DomainException('Actor user is required.');
        }

        $session = PosSession::query()
            ->with('terminal.policy')
            ->where('id', $sessionId)
            ->where('setting_id', $settingId)
            ->first();

        if (! $session) {
            throw new DomainException('POS session not found for current setting.');
        }

        $calculation = $this->expectedCashCalculator->calculate($session->id);

        $thresholdValue = $session->terminal?->policy?->cash_threshold;
        $thresholdSource = 'terminal_policy';

        if ($thresholdValue === null) {
            $thresholdValue = (float) config('pos.default_cash_threshold', 10000000);
            $thresholdSource = 'default_config';
        }

        $expectedCashTotal = round((float) $calculation['expected_cash_total'], 2);
        $threshold = round((float) $thresholdValue, 2);

        return [
            'session_id' => (int) $session->id,
            'status' => (string) $session->status,
            'cashier_user_id' => (int) $session->cashier_user_id,
            'terminal_id' => (int) $session->terminal_id,
            'expected_cash_total' => $expectedCashTotal,
            'cached_expected_cash_total' => round((float) $calculation['cached_expected_cash_total'], 2),
            'threshold_value' => $threshold,
            'threshold_source' => $thresholdSource,
            'is_threshold_breached' => $expectedCashTotal > $threshold,
            'events_count' => (int) $calculation['events_count'],
            'calculated_at' => (string) $calculation['calculated_at'],
        ];
    }
}
