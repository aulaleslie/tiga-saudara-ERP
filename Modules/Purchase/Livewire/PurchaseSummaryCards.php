<?php

namespace Modules\Purchase\Livewire;

use Carbon\Carbon;
use Livewire\Component;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchasePayment;

class PurchaseSummaryCards extends Component
{
    public $settingId;

    public function mount()
    {
        $this->settingId = session('setting_id');
    }

    public function render()
    {
        return view('purchase::livewire.purchase-summary-cards');
    }

    public function getBelumDibayarProperty()
    {
        $query = Purchase::query()
            ->where('setting_id', $this->settingId)
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
            ->where('setting_id', $this->settingId)
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

        $query = PurchasePayment::active()
            ->whereHas('purchase', function ($q) {
                $q->where('setting_id', $this->settingId)
                  ->whereIn('status', [Purchase::STATUS_APPROVED, Purchase::STATUS_RECEIVED_PARTIALLY, Purchase::STATUS_RECEIVED]);
            })
            ->where('date', '>=', $thirtyDaysAgo);
            
        $count = $query->count();

        if ($count > 0) {
            return [
                'count' => $count,
                'total' => $query->sum('amount') / 100,
            ];
        }

        $fallbackQuery = Purchase::query()
            ->where('setting_id', $this->settingId)
            ->where('date', '>=', $thirtyDaysAgo)
            ->where('payment_status', 'PAID')
            ->whereIn('status', [Purchase::STATUS_APPROVED, Purchase::STATUS_RECEIVED_PARTIALLY, Purchase::STATUS_RECEIVED]);

        return [
            'count' => $fallbackQuery->count(),
            'total' => $fallbackQuery->sum('paid_amount'),
        ];
    }
}
