<?php

namespace Modules\Purchase\Livewire;

use Carbon\Carbon;
use Livewire\Component;
use Livewire\Attributes\Locked;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchasePayment;
use Illuminate\Support\Facades\Gate;

class PurchaseSummaryCards extends Component
{
    private const BELUM_DIBAYAR_PAYMENT_STATUSES = ['UNPAID', 'PARTIAL'];

    public $settingId;
    
    #[Locked]
    public $globalMode = false;

    public function mount($globalMode = false)
    {
        $this->globalMode = $globalMode;
        
        if ($this->globalMode) {
            abort_if(Gate::denies('purchasePayments.global.access'), 403);
        }
        
        if (!$this->globalMode) {
            $this->settingId = session('setting_id');
        }
    }

    public function render()
    {
        return view('purchase::livewire.purchase-summary-cards');
    }

    public function getBelumDibayarProperty()
    {
        if ($this->globalMode) {
            $liveDueAmountSql = 'total_amount - COALESCE((SELECT SUM(amount/100.0) FROM purchase_payments WHERE purchase_payments.purchase_id = purchases.id AND purchase_payments.status = ?), 0)';
            
            $result = Purchase::query()
                ->where('status', Purchase::STATUS_RECEIVED)
                ->whereRaw($liveDueAmountSql . ' > 0', [\Modules\Purchase\Entities\PurchasePayment::STATUS_ACTIVE])
                ->selectRaw("COUNT(*) as cnt, SUM($liveDueAmountSql) as total", [\Modules\Purchase\Entities\PurchasePayment::STATUS_ACTIVE])
                ->first();
        } else {
            $result = Purchase::query()
                ->where('setting_id', $this->settingId)
                ->whereIn('payment_status', self::BELUM_DIBAYAR_PAYMENT_STATUSES)
                ->where('due_amount', '>', 0)
                ->whereIn('status', [Purchase::STATUS_APPROVED, Purchase::STATUS_RECEIVED_PARTIALLY, Purchase::STATUS_RECEIVED])
                ->selectRaw('COUNT(*) as cnt, SUM(due_amount) as total')
                ->first();
        }

        return [
            'count' => (int) ($result->cnt ?? 0),
            'total' => (float) ($result->total ?? 0),
        ];
    }

    public function getTelatBayarProperty()
    {
        if ($this->globalMode) {
            $liveDueAmountSql = 'total_amount - COALESCE((SELECT SUM(amount/100.0) FROM purchase_payments WHERE purchase_payments.purchase_id = purchases.id AND purchase_payments.status = ?), 0)';
            
            $result = Purchase::query()
                ->where('status', Purchase::STATUS_RECEIVED)
                ->where('due_date', '<', Carbon::today())
                ->whereRaw($liveDueAmountSql . ' > 0', [\Modules\Purchase\Entities\PurchasePayment::STATUS_ACTIVE])
                ->selectRaw("COUNT(*) as cnt, SUM($liveDueAmountSql) as total", [\Modules\Purchase\Entities\PurchasePayment::STATUS_ACTIVE])
                ->first();
        } else {
            $result = Purchase::query()
                ->where('setting_id', $this->settingId)
                ->whereIn('payment_status', self::BELUM_DIBAYAR_PAYMENT_STATUSES)
                ->where('due_amount', '>', 0)
                ->whereIn('status', [Purchase::STATUS_APPROVED, Purchase::STATUS_RECEIVED_PARTIALLY, Purchase::STATUS_RECEIVED])
                ->where('due_date', '<', Carbon::today())
                ->selectRaw('COUNT(*) as cnt, SUM(due_amount) as total')
                ->first();
        }

        return [
            'count' => (int) ($result->cnt ?? 0),
            'total' => (float) ($result->total ?? 0),
        ];
    }

    public function getPelunasanProperty()
    {
        $thirtyDaysAgo = Carbon::today()->subDays(30)->format('Y-m-d');

        if ($this->globalMode) {
            $liveDueAmountSql = 'total_amount - COALESCE((SELECT SUM(amount/100.0) FROM purchase_payments WHERE purchase_payments.purchase_id = purchases.id AND purchase_payments.status = ?), 0)';
            
            $result = PurchasePayment::active()
                ->where('date', '>=', $thirtyDaysAgo)
                ->where('date', '<=', Carbon::today()->endOfDay())
                ->whereHas('purchase', function ($q) use ($liveDueAmountSql) {
                    $q->where('status', Purchase::STATUS_RECEIVED)
                      ->whereRaw($liveDueAmountSql . ' <= 0', [\Modules\Purchase\Entities\PurchasePayment::STATUS_ACTIVE]);
                })
                ->selectRaw('COUNT(DISTINCT purchase_id) as cnt, SUM(amount) as total')
                ->first();
                
            return [
                'count' => (int) ($result->cnt ?? 0),
                'total' => (float) (($result->total ?? 0) / 100),
            ];
        }

        $result = PurchasePayment::active()
            ->whereHas('purchase', function ($q) {
                $q->where('setting_id', $this->settingId)
                  ->where('payment_status', 'PAID')
                  ->whereIn('status', [Purchase::STATUS_APPROVED, Purchase::STATUS_RECEIVED_PARTIALLY, Purchase::STATUS_RECEIVED]);
            })
            ->where('date', '>=', $thirtyDaysAgo)
            ->where('date', '<=', Carbon::today()->endOfDay())
            // Note: COUNT(DISTINCT purchase_id) counts unique invoices, 
            // while SUM(amount) intentionally sums all partial payment rows.
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
            ->where('date', '<=', Carbon::today()->endOfDay())
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
