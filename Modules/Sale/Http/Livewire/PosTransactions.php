<?php

namespace Modules\Sale\Http\Livewire;

use App\Models\PosReceipt;
use App\Models\PosSession;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Livewire\WithPagination;
use App\Events\PrintJobEvent;
use Illuminate\Support\Facades\Auth;

class PosTransactions extends Component
{
    use WithPagination;

    public string $search = '';
    public string $status = '';
    public ?int $sessionId = null;
    public string $dateFrom = '';
    public string $dateTo = '';
    public int $perPage = 20;

    protected $queryString = [
        'search' => ['except' => ''],
        'status' => ['except' => ''],
        'sessionId' => ['except' => null],
        'dateFrom' => ['except' => ''],
        'dateTo' => ['except' => ''],
    ];

    public function mount()
    {
        abort_if(Gate::denies('pos.transactions.access'), 403);
    }

    public function updatingSearch()
    {
        $this->resetPage();
    }

    public function updatingStatus()
    {
        $this->resetPage();
    }

    public function updatingSessionId()
    {
        $this->resetPage();
    }

    public function updatingDateFrom()
    {
        $this->resetPage();
    }

    public function updatingDateTo()
    {
        $this->resetPage();
    }

    public function clearFilters()
    {
        $this->search = '';
        $this->status = '';
        $this->sessionId = null;
        $this->dateFrom = '';
        $this->dateTo = '';
        $this->resetPage();
    }

    public function reprintReceipt($receiptId)
    {
        $receipt = PosReceipt::with(['sales.saleDetails.product.conversions.unit', 'sales.saleDetails.product.conversions.prices', 'sales.saleDetails.product.baseUnit', 'sales.saleDetails.product.prices', 'sales.tenantSetting', 'sales.customer'])
            ->findOrFail($receiptId);

        // Check if receipt belongs to current tenant
        if ($receipt->sales->first()?->setting_id !== session('setting_id')) {
            $this->addError('receipt', 'Unauthorized access to receipt');
            return;
        }

        try {
            $htmlContent = view('sale::print-pos', [
                'receipt' => $receipt,
            ])->render();

            // Broadcast for legacy Echo listeners
            event(new PrintJobEvent($htmlContent, 'pos-sale', Auth::id()));

            // Dispatch browser event for direct printing (kiosk mode)
            $this->dispatch('pos-print-receipt', content: $htmlContent);

            session()->flash('success', 'Receipt reprint job sent successfully');
        } catch (\Exception $e) {
            session()->flash('error', 'Failed to reprint receipt: ' . $e->getMessage());
        }
    }

    public function getPosSessionsProperty()
    {
        return PosSession::where('user_id', Auth::id())
            ->whereHas('location', function (Builder $query) {
                $query->where('setting_id', session('setting_id'));
            })
            ->orderByDesc('created_at')
            ->get();
    }

    public function render()
    {
        $query = PosReceipt::with(['sales.customer', 'posSession.location', 'sales.tenantSetting'])
            ->whereHas('sales', function (Builder $query) {
                $query->where('setting_id', session('setting_id'));
            });

        // Search filter
        if (!empty($this->search)) {
            $query->where(function (Builder $q) {
                $q->where('receipt_number', 'like', "%{$this->search}%")
                  ->orWhere('customer_name', 'like', "%{$this->search}%")
                  ->orWhereHas('sales.customer', function (Builder $customerQuery) {
                      $customerQuery->where('customer_name', 'like', "%{$this->search}%");
                  });
            });
        }

        // Status filter
        if (!empty($this->status)) {
            $query->where('payment_status', $this->status);
        }

        // Session filter
        if ($this->sessionId) {
            $query->where('pos_session_id', $this->sessionId);
        }

        // Date filters
        if (!empty($this->dateFrom)) {
            $query->whereDate('created_at', '>=', $this->dateFrom);
        }
        if (!empty($this->dateTo)) {
            $query->whereDate('created_at', '<=', $this->dateTo);
        }

        $receipts = $query->orderByDesc('created_at')
                          ->paginate($this->perPage);

        return view('sale::livewire.pos-transactions', [
            'receipts' => $receipts,
        ]);
    }

    public function getStatusBadgeClass($status): string
    {
        return match(strtolower($status)) {
            'paid' => 'success',
            'partial' => 'warning',
            'unpaid' => 'danger',
            default => 'secondary'
        };
    }
}