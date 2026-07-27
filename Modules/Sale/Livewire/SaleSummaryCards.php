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
        } else {
            $query->where('setting_id', $this->settingId)
                  ->where('due_amount', '>', 0);
        }

        $result = $query->selectRaw('COUNT(*) as cnt, SUM(due_amount) as total')
                        ->first();

        return [
            'count' => (int) ($result->cnt ?? 0),
            'total' => (float) ($result->total ?? 0),
        ];
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
        } else {
            $query->where('setting_id', $this->settingId)
                  ->where('due_amount', '>', 0);
        }

        $result = $query->selectRaw('COUNT(*) as cnt, SUM(due_amount) as total')
                        ->first();

        return [
            'count' => (int) ($result->cnt ?? 0),
            'total' => (float) ($result->total ?? 0),
        ];
    }

    public function getPenerimaanProperty()
    {
        $thirtyDaysAgo = Carbon::today()->subDays(30)->format('Y-m-d');

        $query = SalePayment::active()
            ->where('date', '>=', $thirtyDaysAgo)
            ->whereHas('sale', function ($q) {
                $q->where('payment_status', 'PAID')
                  ->whereIn('status', [Sale::STATUS_APPROVED, Sale::STATUS_DISPATCHED_PARTIALLY, Sale::STATUS_DISPATCHED]);

                if (!$this->globalMode) {
                    $q->where('setting_id', $this->settingId);
                } else {
                    $q->whereNull('archived_at');
                }
            });

        $result = $query->selectRaw('COUNT(DISTINCT sale_id) as cnt, SUM(amount) as total')
                        ->first();

        if ($result && $result->cnt > 0) {
            return [
                'count' => (int) $result->cnt,
                'total' => (float) $result->total,
            ];
        }

        $fallbackQuery = Sale::query()
            ->where('date', '>=', $thirtyDaysAgo)
            ->where('payment_status', 'PAID')
            ->whereIn('status', [Sale::STATUS_APPROVED, Sale::STATUS_DISPATCHED_PARTIALLY, Sale::STATUS_DISPATCHED]);

        if (!$this->globalMode) {
            $fallbackQuery->where('setting_id', $this->settingId);
        } else {
            $fallbackQuery->whereNull('archived_at');
        }

        $fallbackResult = $fallbackQuery->selectRaw('COUNT(*) as cnt, SUM(paid_amount) as total')
                                       ->first();

        return [
            'count' => (int) ($fallbackResult->cnt ?? 0),
            'total' => (float) ($fallbackResult->total ?? 0),
        ];
    }
}
