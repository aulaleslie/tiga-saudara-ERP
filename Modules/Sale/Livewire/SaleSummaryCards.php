<?php

namespace Modules\Sale\Livewire;

use Carbon\Carbon;
use Livewire\Component;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;

class SaleSummaryCards extends Component
{
    private const PIUTANG_BELUM_TERTAGIH_PAYMENT_STATUSES = ['UNPAID', 'PARTIAL'];

    public $settingId;

    public function mount()
    {
        $this->settingId = session('setting_id');
    }

    public function render()
    {
        return view('sale::livewire.sale-summary-cards');
    }

    public function getPiutangBelumTertagihProperty()
    {
        $result = Sale::query()
            ->where('setting_id', $this->settingId)
            ->whereIn('payment_status', self::PIUTANG_BELUM_TERTAGIH_PAYMENT_STATUSES)
            ->where('due_amount', '>', 0)
            ->whereIn('status', [Sale::STATUS_APPROVED, Sale::STATUS_DISPATCHED_PARTIALLY, Sale::STATUS_DISPATCHED])
            ->selectRaw('COUNT(*) as cnt, SUM(due_amount) as total')
            ->first();

        return [
            'count' => (int) ($result->cnt ?? 0),
            'total' => (float) ($result->total ?? 0),
        ];
    }

    public function getPiutangTelatProperty()
    {
        $result = Sale::query()
            ->where('setting_id', $this->settingId)
            ->whereIn('payment_status', self::PIUTANG_BELUM_TERTAGIH_PAYMENT_STATUSES)
            ->where('due_amount', '>', 0)
            ->whereIn('status', [Sale::STATUS_APPROVED, Sale::STATUS_DISPATCHED_PARTIALLY, Sale::STATUS_DISPATCHED])
            ->where('due_date', '<', Carbon::today())
            ->selectRaw('COUNT(*) as cnt, SUM(due_amount) as total')
            ->first();

        return [
            'count' => (int) ($result->cnt ?? 0),
            'total' => (float) ($result->total ?? 0),
        ];
    }

    public function getPenerimaanProperty()
    {
        $thirtyDaysAgo = Carbon::today()->subDays(30)->format('Y-m-d');

        $result = SalePayment::active()
            ->whereHas('sale', function ($q) {
                $q->where('setting_id', $this->settingId)
                  ->where('payment_status', 'PAID')
                  ->whereIn('status', [Sale::STATUS_APPROVED, Sale::STATUS_DISPATCHED_PARTIALLY, Sale::STATUS_DISPATCHED]);
            })
            ->where('date', '>=', $thirtyDaysAgo)
            ->selectRaw('COUNT(DISTINCT sale_id) as cnt, SUM(amount) as total')
            ->first();
            
        if ($result && $result->cnt > 0) {
            return [
                'count' => (int) $result->cnt,
                // Do NOT divide by 100 because SalePayment.amount is decimal:2 (rupiah)
                'total' => (float) $result->total,
            ];
        }

        $fallbackResult = Sale::query()
            ->where('setting_id', $this->settingId)
            ->where('date', '>=', $thirtyDaysAgo)
            ->where('payment_status', 'PAID')
            ->whereIn('status', [Sale::STATUS_APPROVED, Sale::STATUS_DISPATCHED_PARTIALLY, Sale::STATUS_DISPATCHED])
            ->selectRaw('COUNT(*) as cnt, SUM(paid_amount) as total')
            ->first();

        return [
            'count' => (int) ($fallbackResult->cnt ?? 0),
            'total' => (float) ($fallbackResult->total ?? 0),
        ];
    }
}
