<?php

namespace Modules\Purchase\Livewire;

use Carbon\Carbon;
use Livewire\Component;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchasePayment;

class PurchaseSummaryCards extends Component
{
    public function render()
    {
        return view('purchase::livewire.purchase-summary-cards');
    }

    public function getBelumDibayarProperty()
    {
        $query = Purchase::query()
            ->where('payment_status', 'UNPAID')
            ->where('due_amount', '>', 0)
            ->whereIn('status', [Purchase::STATUS_APPROVED, Purchase::STATUS_RECEIVED_PARTIALLY, Purchase::STATUS_RECEIVED]);

        return [
            'count' => $query->count(),
            'total' => $query->sum('due_amount'),
        ];
    }

    public function getTelatBayarProperty()
    {
        $query = Purchase::query()
            ->where('payment_status', 'UNPAID')
            ->where('due_amount', '>', 0)
            ->whereIn('status', [Purchase::STATUS_APPROVED, Purchase::STATUS_RECEIVED_PARTIALLY, Purchase::STATUS_RECEIVED])
            ->where('due_date', '<', Carbon::today());

        return [
            'count' => $query->count(),
            'total' => $query->sum('due_amount'),
        ];
    }

    public function getPelunasanProperty()
    {
        $thirtyDaysAgo = Carbon::today()->subDays(30);

        $hasPayments = PurchasePayment::active()
            ->where('date', '>=', $thirtyDaysAgo)
            ->exists();

        if ($hasPayments) {
            $query = PurchasePayment::active()
                ->where('date', '>=', $thirtyDaysAgo);
            
            return [
                'count' => $query->count(),
                'total' => $query->sum('amount') / 100,
            ];
        }

        $query = Purchase::query()
            ->where('date', '>=', $thirtyDaysAgo)
            ->where('payment_status', 'PAID');

        return [
            'count' => $query->count(),
            'total' => $query->sum('total_amount'),
        ];
    }
}
