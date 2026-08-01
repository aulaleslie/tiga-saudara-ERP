<?php

namespace Modules\Purchase\Livewire;

use Carbon\Carbon;
use Livewire\Component;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchasePayment;
use Illuminate\Support\Facades\Gate;

class PurchaseSummaryCards extends Component
{
    private const BELUM_DIBAYAR_PAYMENT_STATUSES = ['UNPAID', 'PARTIAL'];

    public $settingId;

    #[Locked]
    public $globalMode = false;

    // Global mode filters
    public ?int $globalBusinessFilter = null;
    public ?string $documentDateFrom = null;
    public ?string $documentDateTo = null;

    // Durable summary card selection (stored server-side, restored across refreshes)
    public ?string $selectedCardFilter = null;

    public function mount($globalMode = false, ?int $globalBusinessFilter = null, ?string $documentDateFrom = null, ?string $documentDateTo = null, ?string $selectedCardFilter = null)
    {
        $this->globalMode = $globalMode;
        $this->globalBusinessFilter = $globalBusinessFilter;
        $this->documentDateFrom = $documentDateFrom;
        $this->documentDateTo = $documentDateTo;
        $this->selectedCardFilter = $selectedCardFilter;

        if ($this->globalMode) {
            abort_if(Gate::denies('purchasePayments.global.access'), 403);
        }

        if (!$this->globalMode) {
            $this->settingId = session('setting_id');
        }
    }

    #[On('global-purchase-filters-changed')]
    public function handleFiltersChanged($globalBusinessFilter = null, $documentDateFrom = null, $documentDateTo = null)
    {
        if ($this->globalMode) {
            $this->globalBusinessFilter = $globalBusinessFilter;
            $this->documentDateFrom = $documentDateFrom;
            $this->documentDateTo = $documentDateTo;
            // Preserve the selected card filter across refreshes
        }
    }

    public function toggleCardFilter(?string $type = null)
    {
        // Toggle: clicking the same card clears it, clicking different card selects it
        if ($this->selectedCardFilter === $type) {
            $this->selectedCardFilter = null;
        } else {
            $this->selectedCardFilter = $type;
        }

        // Dispatch to table to apply the card filter
        $this->dispatch('purchase-filter', type: $this->selectedCardFilter);
    }

    public function render()
    {
        return view('purchase::livewire.purchase-summary-cards');
    }

    public function getBelumDibayarProperty()
    {
        if ($this->globalMode) {
            $query = Purchase::query()
                ->whereNull('archived_at')
                ->where('status', Purchase::STATUS_RECEIVED)
                ->whereLiveDueAmountGreaterThan(0);

            // Apply business filter if set
            if (!empty($this->globalBusinessFilter)) {
                $query->where('setting_id', $this->globalBusinessFilter);
            }

            // Apply document date range filter if set
            if (!empty($this->documentDateFrom)) {
                $query->where('date', '>=', $this->documentDateFrom);
            }
            if (!empty($this->documentDateTo)) {
                $query->where('date', '<=', $this->documentDateTo);
            }

            $purchases = $query->get();
            $total = $purchases->sum(function ($purchase) {
                return $purchase->live_due_amount;
            });

            return [
                'count' => $purchases->count(),
                'total' => (float) $total,
            ];
        }

        $result = Purchase::query()
            ->where('setting_id', $this->settingId)
            ->whereIn('payment_status', self::BELUM_DIBAYAR_PAYMENT_STATUSES)
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
        if ($this->globalMode) {
            $query = Purchase::query()
                ->whereNull('archived_at')
                ->where('status', Purchase::STATUS_RECEIVED)
                ->where('due_date', '<', Carbon::today())
                ->whereLiveDueAmountGreaterThan(0);

            // Apply business filter if set
            if (!empty($this->globalBusinessFilter)) {
                $query->where('setting_id', $this->globalBusinessFilter);
            }

            // Apply document date range filter if set
            if (!empty($this->documentDateFrom)) {
                $query->where('date', '>=', $this->documentDateFrom);
            }
            if (!empty($this->documentDateTo)) {
                $query->where('date', '<=', $this->documentDateTo);
            }

            $purchases = $query->get();
            $total = $purchases->sum(function ($purchase) {
                return $purchase->live_due_amount;
            });

            return [
                'count' => $purchases->count(),
                'total' => (float) $total,
            ];
        }

        $result = Purchase::query()
            ->where('setting_id', $this->settingId)
            ->whereIn('payment_status', self::BELUM_DIBAYAR_PAYMENT_STATUSES)
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
        $thirtyDaysAgo = Carbon::today()->subDays(30)->format('Y-m-d');

        if ($this->globalMode) {
            // Global mode: fully paid purchases with active payment in past 30 days
            // Count and sum active payment amounts
            $query = PurchasePayment::query()
                ->where('date', '>=', $thirtyDaysAgo)
                ->where('date', '<=', Carbon::today()->endOfDay())
                ->where('status', PurchasePayment::STATUS_ACTIVE)
                ->whereHas('purchase', function ($pq) {
                    $pq->whereNull('archived_at')
                       ->where('status', Purchase::STATUS_RECEIVED)
                       ->whereLiveDueAmountLessThanOrEqual(0);
                });

            // Apply business filter if set
            if (!empty($this->globalBusinessFilter)) {
                $query->whereHas('purchase', function ($pq) {
                    $pq->where('setting_id', $this->globalBusinessFilter);
                });
            }

            // Apply document date range filter if set
            if (!empty($this->documentDateFrom)) {
                $query->whereHas('purchase', function ($pq) {
                    $pq->where('date', '>=', $this->documentDateFrom);
                });
            }
            if (!empty($this->documentDateTo)) {
                $query->whereHas('purchase', function ($pq) {
                    $pq->where('date', '<=', $this->documentDateTo);
                });
            }

            $result = $query->selectRaw('COUNT(DISTINCT purchase_id) as cnt, SUM(amount) as total')
                           ->first();

            return [
                'count' => (int) ($result->cnt ?? 0),
                'total' => (float) ($result->total ?? 0),
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
