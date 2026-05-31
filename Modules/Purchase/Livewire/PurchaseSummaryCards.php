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
        $result = Purchase::query()
            ->where('setting_id', $this->settingId)
            ->where('payment_status', 'UNPAID')
            ->where('due_amount', '>', 0)
            ->whereIn('status', [Purchase::STATUS_APPROVED, Purchase::STATUS_RECEIVED_PARTIALLY, Purchase::STATUS_RECEIVED])
            ->selectRaw('COUNT(*) as cnt, SUM(due_amount) as total')
            ->first();

        return [
            'count' => (int) ($result->cnt ?? 0),
            'total' => (float) ($result->total ?? 0),
        ];
    }

    public function getTelatBayarProperty()
    {
        $result = Purchase::query()
            ->where('setting_id', $this->settingId)
            ->where('payment_status', 'UNPAID')
            ->where('due_amount', '>', 0)
            ->whereIn('status', [Purchase::STATUS_APPROVED, Purchase::STATUS_RECEIVED_PARTIALLY, Purchase::STATUS_RECEIVED])
            ->where('due_date', '<', Carbon::today())
            ->selectRaw('COUNT(*) as cnt, SUM(due_amount) as total')
            ->first();

        return [
            'count' => (int) ($result->cnt ?? 0),
            'total' => (float) ($result->total ?? 0),
        ];
    }

    public function getPelunasanProperty()
    {
        $thirtyDaysAgo = Carbon::today()->subDays(30);

        $result = PurchasePayment::active()
            ->whereHas('purchase', function ($q) {
                $q->where('setting_id', $this->settingId)
                  ->whereIn('status', [Purchase::STATUS_APPROVED, Purchase::STATUS_RECEIVED_PARTIALLY, Purchase::STATUS_RECEIVED]);
            })
            ->where('date', '>=', $thirtyDaysAgo)
            ->selectRaw('COUNT(DISTINCT purchase_id) as cnt, SUM(amount) as total')
            ->first();
            
        if ($result && $result->cnt > 0) {
            return [
                'count' => (int) $result->cnt,
                'total' => $result->total / 100,
            ];
        }

        $fallbackResult = Purchase::query()
            ->where('setting_id', $this->settingId)
            ->where('date', '>=', $thirtyDaysAgo)
            ->where('payment_status', 'PAID')
            ->whereIn('status', [Purchase::STATUS_APPROVED, Purchase::STATUS_RECEIVED_PARTIALLY, Purchase::STATUS_RECEIVED])
            ->selectRaw('COUNT(*) as cnt, SUM(paid_amount) as total')
            ->first();

        return [
            'count' => (int) ($fallbackResult->cnt ?? 0),
            'total' => (float) ($fallbackResult->total ?? 0),
        ];
    }
}
