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
    public bool $globalMode = false;

    public function mount(bool $globalMode = false)
    {
        abort_if($globalMode && !\auth()->user()->can('salePayments.global.access'), 403);

        $this->globalMode = $globalMode;
        $this->settingId = $globalMode ? null : session('setting_id');
    }

    public function render()
    {
        return view('sale::livewire.sale-summary-cards');
    }

    public function getPiutangBelumTertagihProperty()
    {
        $query = Sale::query()
            ->whereIn('payment_status', self::PIUTANG_BELUM_TERTAGIH_PAYMENT_STATUSES)
            ->whereIn('status', [Sale::STATUS_APPROVED, Sale::STATUS_DISPATCHED_PARTIALLY, Sale::STATUS_DISPATCHED]);

        if ($this->globalMode) {
            $query->whereNull('archived_at')
                  ->whereLiveDueAmountGreaterThan(0);
            // Use canonical live due formula for global mode
            $result = $query->selectRaw('COUNT(*) as cnt')
                            ->first();

            // Fetch sales to calculate canonical live due in PHP
            $sales = $query->get();
            $total = $sales->sum(function ($sale) {
                return $sale->live_due_amount;
            });

            return [
                'count' => (int) ($result->cnt ?? 0),
                'total' => (float) $total,
            ];
        } else {
            $query->where('setting_id', $this->settingId)
                  ->where('due_amount', '>', 0);

            $result = $query->selectRaw('COUNT(*) as cnt, SUM(due_amount) as total')
                            ->first();

            return [
                'count' => (int) ($result->cnt ?? 0),
                'total' => (float) ($result->total ?? 0),
            ];
        }
    }

    public function getPiutangTelatProperty()
    {
        $query = Sale::query()
            ->whereIn('payment_status', self::PIUTANG_BELUM_TERTAGIH_PAYMENT_STATUSES)
            ->whereIn('status', [Sale::STATUS_APPROVED, Sale::STATUS_DISPATCHED_PARTIALLY, Sale::STATUS_DISPATCHED])
            ->where('due_date', '<', Carbon::today());

        if ($this->globalMode) {
            $query->whereNull('archived_at')
                  ->whereLiveDueAmountGreaterThan(0);
            // Use canonical live due formula for global mode
            $result = $query->selectRaw('COUNT(*) as cnt')
                            ->first();

            // Fetch sales to calculate canonical live due in PHP
            $sales = $query->get();
            $total = $sales->sum(function ($sale) {
                return $sale->live_due_amount;
            });

            return [
                'count' => (int) ($result->cnt ?? 0),
                'total' => (float) $total,
            ];
        } else {
            $query->where('setting_id', $this->settingId)
                  ->where('due_amount', '>', 0);

            $result = $query->selectRaw('COUNT(*) as cnt, SUM(due_amount) as total')
                            ->first();

            return [
                'count' => (int) ($result->cnt ?? 0),
                'total' => (float) ($result->total ?? 0),
            ];
        }
    }

    public function getPenerimaanProperty()
    {
        $thirtyDaysAgo = Carbon::today()->subDays(30)->format('Y-m-d');

        $query = SalePayment::active()
            ->where('date', '>=', $thirtyDaysAgo)
            ->whereHas('sale', function ($q) {
                $q->whereIn('status', [Sale::STATUS_APPROVED, Sale::STATUS_DISPATCHED_PARTIALLY, Sale::STATUS_DISPATCHED]);

                if (!$this->globalMode) {
                    $q->where('setting_id', $this->settingId);
                } else {
                    $q->whereNull('archived_at');
                }
            });

        $result = $query->selectRaw('COUNT(DISTINCT sale_id) as cnt, SUM(amount) as total')
                        ->first();

        return [
            'count' => (int) ($result->cnt ?? 0),
            'total' => (float) ($result->total ?? 0),
        ];
    }
}
