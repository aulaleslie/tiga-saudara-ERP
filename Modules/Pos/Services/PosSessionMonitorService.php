<?php

namespace Modules\Pos\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosCheckout;

class PosSessionMonitorService
{
    /**
     * @return array<int, array{
     *     session_id:int,
     *     status:string,
     *     cashier_name:string,
     *     terminal_code:string,
     *     terminal_name:string,
     *     opened_at:string,
     *     opening_float_total:float,
     *     expected_cash_total:float,
     *     threshold_value:float,
     *     threshold_source:string,
     *     is_threshold_breached:bool,
     *     transactions_count:int,
     *     safe_drops_count:int,
     *     last_activity_at:string|null
     * }>
     */
    public function getActiveSessionsSummary(int $settingId): array
    {
        $sessions = tap(PosSession::query()
            ->with(['terminal.policy', 'cashier:id,name'])
            ->withCount([
                'cashEvents as transactions_count' => function ($query) {
                    $query->where('event_type', \Modules\Pos\Entities\PosSessionCashEvent::EVENT_CASH_SALE_IN)
                          ->where('direction', \Modules\Pos\Entities\PosSessionCashEvent::DIRECTION_IN);
                },
                'cashEvents as safe_drops_count' => function ($query) {
                    $query->where('event_type', \Modules\Pos\Entities\PosSessionCashEvent::EVENT_SAFE_DROP_OUT)
                          ->where('direction', \Modules\Pos\Entities\PosSessionCashEvent::DIRECTION_OUT);
                }
            ])
            ->withMax('cashEvents', 'occurred_at')
            ->where('setting_id', $settingId)
            ->active()
            ->orderBy('id', 'desc')
            ->get(), function ($sessions) {
                // We map this after because we want to transform the items into custom associative arrays
            });

        $summaries = [];
        $defaultThreshold = (float) config('pos.default_cash_threshold', 10000000);

        foreach ($sessions as $session) {
            $thresholdValue = $session->terminal?->policy?->cash_threshold;
            $thresholdSource = 'terminal_policy';

            if ($thresholdValue === null) {
                $thresholdValue = $defaultThreshold;
                $thresholdSource = 'default_config';
            }

            $expectedCashTotal = round((float) $session->expected_cash_total, 2);
            $threshold = round((float) $thresholdValue, 2);
            
            $lastActivity = $session->cash_events_max_occurred_at ? Carbon::parse($session->cash_events_max_occurred_at)->toISOString() : $session->opened_at->toISOString();

            $summaries[] = [
                'session_id' => (int) $session->id,
                'status' => (string) $session->status,
                'cashier_name' => (string) $session->cashier?->name,
                'terminal_code' => (string) $session->terminal?->code,
                'terminal_name' => (string) $session->terminal?->name,
                'opened_at' => $session->opened_at->toISOString(),
                'opening_float_total' => round((float) $session->opening_float_total, 2),
                'expected_cash_total' => $expectedCashTotal,
                'threshold_value' => $threshold,
                'threshold_source' => $thresholdSource,
                'is_threshold_breached' => $expectedCashTotal > $threshold,
                'transactions_count' => (int) $session->transactions_count,
                'safe_drops_count' => (int) $session->safe_drops_count,
                'last_activity_at' => $lastActivity,
            ];
        }

        return $summaries;
    }
}
