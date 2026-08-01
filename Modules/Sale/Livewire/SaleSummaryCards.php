<?php

namespace Modules\Sale\Livewire;

use Carbon\Carbon;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;

class SaleSummaryCards extends Component
{
    private const PIUTANG_BELUM_TERTAGIH_PAYMENT_STATUSES = ['UNPAID', 'PARTIAL'];

    public $settingId;

    #[Locked]
    public bool $globalMode = false;

    // Global mode filters
    public ?int $globalBusinessFilter = null;
    public ?string $documentDateFrom = null;
    public ?string $documentDateTo = null;

    // Durable summary card selection (stored server-side, restored across refreshes)
    public ?string $selectedCardFilter = null;

    public function mount(bool $globalMode = false, ?int $globalBusinessFilter = null, ?string $documentDateFrom = null, ?string $documentDateTo = null, ?string $selectedCardFilter = null)
    {
        abort_if($globalMode && !\auth()->user()->can('salePayments.global.access'), 403);

        $this->globalMode = $globalMode;
        $this->settingId = $globalMode ? null : session('setting_id');
        $this->globalBusinessFilter = $globalBusinessFilter;
        $this->documentDateFrom = $documentDateFrom;
        $this->documentDateTo = $documentDateTo;
        $this->selectedCardFilter = $selectedCardFilter;
    }

    #[On('global-sale-filters-changed')]
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
        $this->dispatch('sale-filter', type: $this->selectedCardFilter);
    }

    public function render()
    {
        return view('sale::livewire.sale-summary-cards');
    }

    public function getPiutangBelumTertagihProperty()
    {
        $query = Sale::query()
            ->whereIn('status', [Sale::STATUS_APPROVED, Sale::STATUS_DISPATCHED_PARTIALLY, Sale::STATUS_DISPATCHED])
            ->whereLiveDueAmountGreaterThan(0);

        if ($this->globalMode) {
            $query->whereNull('archived_at');

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

            $sales = $query->get();
            $total = $sales->sum(function ($sale) {
                return $sale->live_due_amount;
            });

            return [
                'count' => $sales->count(),
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
            ->whereIn('status', [Sale::STATUS_APPROVED, Sale::STATUS_DISPATCHED_PARTIALLY, Sale::STATUS_DISPATCHED])
            ->where('due_date', '<', Carbon::today())
            ->whereLiveDueAmountGreaterThan(0);

        if ($this->globalMode) {
            $query->whereNull('archived_at');

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

            $sales = $query->get();
            $total = $sales->sum(function ($sale) {
                return $sale->live_due_amount;
            });

            return [
                'count' => $sales->count(),
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

        if ($this->globalMode) {
            // Global mode: fully paid sales with active payment in past 30 days
            // Count and sum active payment amounts
            $query = SalePayment::query()
                ->where('date', '>=', $thirtyDaysAgo)
                ->where('date', '<=', Carbon::today()->endOfDay())
                ->where('status', SalePayment::STATUS_ACTIVE)
                ->whereHas('sale', function ($sq) {
                    $sq->whereNull('archived_at')
                       ->whereIn('status', [Sale::STATUS_APPROVED, Sale::STATUS_DISPATCHED_PARTIALLY, Sale::STATUS_DISPATCHED])
                       ->whereLiveDueAmountLessThanOrEqual(0);
                });

            // Apply business filter if set
            if (!empty($this->globalBusinessFilter)) {
                $query->whereHas('sale', function ($sq) {
                    $sq->where('setting_id', $this->globalBusinessFilter);
                });
            }

            // Apply document date range filter if set
            if (!empty($this->documentDateFrom)) {
                $query->whereHas('sale', function ($sq) {
                    $sq->where('date', '>=', $this->documentDateFrom);
                });
            }
            if (!empty($this->documentDateTo)) {
                $query->whereHas('sale', function ($sq) {
                    $sq->where('date', '<=', $this->documentDateTo);
                });
            }

            $result = $query->selectRaw('COUNT(DISTINCT sale_id) as cnt, SUM(amount) as total')
                           ->first();

            return [
                'count' => (int) ($result->cnt ?? 0),
                'total' => (float) ($result->total ?? 0),
            ];
        }

        // Non-global mode: keep existing behavior
        $query = SalePayment::active()
            ->where('date', '>=', $thirtyDaysAgo)
            ->where('date', '<=', Carbon::today()->endOfDay())
            ->whereHas('sale', function ($q) {
                $q->whereIn('status', [Sale::STATUS_APPROVED, Sale::STATUS_DISPATCHED_PARTIALLY, Sale::STATUS_DISPATCHED])
                  ->where('setting_id', $this->settingId);
            });

        $result = $query->selectRaw('COUNT(DISTINCT sale_id) as cnt, SUM(amount) as total')
                        ->first();

        if ($result && $result->cnt > 0) {
            return [
                'count' => (int) ($result->cnt ?? 0),
                'total' => (float) ($result->total ?? 0),
            ];
        }

        $fallbackResult = Sale::query()
            ->where('setting_id', $this->settingId)
            ->whereBetween('date', [$thirtyDaysAgo, Carbon::today()->endOfDay()])
            ->where('payment_status', 'PAID')
            ->whereIn('status', [
                Sale::STATUS_APPROVED,
                Sale::STATUS_DISPATCHED_PARTIALLY,
                Sale::STATUS_DISPATCHED,
            ])
            ->selectRaw('COUNT(*) as cnt, SUM(paid_amount) as total')
            ->first();

        return [
            'count' => (int) ($fallbackResult->cnt ?? 0),
            'total' => (float) ($fallbackResult->total ?? 0),
        ];
    }
}
