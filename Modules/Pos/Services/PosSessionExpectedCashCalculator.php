<?php

namespace Modules\Pos\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosSessionCashEvent;

class PosSessionExpectedCashCalculator
{
    /**
     * Calculate expected cash from ledger events and sync session cache.
     *
     * @return array{
     *     session_id:int,
     *     setting_id:int,
     *     expected_cash_total:float,
     *     cached_expected_cash_total:float,
     *     events_count:int,
     *     calculated_at:string
     * }
     */
    public function calculate(int $sessionId): array
    {
        return DB::transaction(function () use ($sessionId) {
            $session = PosSession::query()
                ->lockForUpdate()
                ->find($sessionId);

            if (! $session) {
                throw new DomainException('POS session not found.');
            }

            $events = PosSessionCashEvent::query()
                ->select(['id', 'direction', 'amount', 'occurred_at'])
                ->where('setting_id', $session->setting_id)
                ->where('pos_session_id', $session->id)
                ->orderBy('occurred_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $expectedCashTotal = 0.0;

            foreach ($events as $event) {
                $amount = round((float) $event->amount, 2);
                $direction = (string) $event->direction;

                if ($direction === PosSessionCashEvent::DIRECTION_IN) {
                    $expectedCashTotal += $amount;

                    continue;
                }

                if ($direction === PosSessionCashEvent::DIRECTION_OUT) {
                    $expectedCashTotal -= $amount;

                    continue;
                }

                if ($direction === PosSessionCashEvent::DIRECTION_NEUTRAL) {
                    continue;
                }

                throw new DomainException(
                    sprintf(
                        'Unknown cash event direction [%s] for session event [%d].',
                        $direction,
                        (int) $event->id
                    )
                );
            }

            $expectedCashTotal = round($expectedCashTotal, 2);

            $session->expected_cash_total = $expectedCashTotal;
            $session->save();
            $session->refresh();

            return [
                'session_id' => (int) $session->id,
                'setting_id' => (int) $session->setting_id,
                'expected_cash_total' => $expectedCashTotal,
                'cached_expected_cash_total' => round((float) $session->expected_cash_total, 2),
                'events_count' => $events->count(),
                'calculated_at' => now()->toISOString(),
            ];
        });
    }
}
