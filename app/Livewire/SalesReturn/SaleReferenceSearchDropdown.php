<?php

namespace App\Livewire\SalesReturn;

use App\Support\SalesReturn\SaleReturnEligibilityService;
use Livewire\Component;
use Livewire\Attributes\Modelable;
use Modules\Sale\Entities\Sale;

class SaleReferenceSearchDropdown extends Component
{
    #[Modelable]
    public int|string|null $selected = null;

    public string $name = 'sale_reference_search';
    public string $placeholder = 'Cari No. Penjualan / Scan Barcode';
    public string $search = '';
    public bool $open = false;

    /** @var array<int, array{id: int, reference: string, customer_name: string, date: string}> */
    public array $options = [];
    
    public ?string $selectedLabel = null;

    protected SaleReturnEligibilityService $eligibilityService;

    public function boot(SaleReturnEligibilityService $eligibilityService): void
    {
        $this->eligibilityService = $eligibilityService;
    }

    public function mount(?int $saleId = null, ?string $saleReference = null): void
    {
        if ($saleId) {
            $this->selected = $saleId;
            $this->resolveSelectedLabel($saleId, $saleReference);
        }
    }

    public function updatedSearch(): void
    {
        if (trim($this->search) === '') {
            $this->options = [];
            return;
        }

        $this->open = true;
        
        $this->options = Sale::query()
            ->where('reference', 'like', '%' . $this->search . '%')
            ->whereIn('status', SaleReturnEligibilityService::ELIGIBLE_STATUSES)
            ->orderByDesc('date')
            ->limit(10)
            ->get()
            ->filter(fn (Sale $sale) => $this->eligibilityService->isSaleEligible($sale))
            ->map(fn (Sale $sale) => [
                'id' => $sale->id,
                'reference' => $sale->reference,
                'customer_name' => $sale->customer_name,
                'date' => $sale->date ? $sale->date->format('d/m/Y') : '-',
            ])
            ->values()
            ->all();
    }

    public function select(int $id): void
    {
        $this->selected = $id;
        $this->open = false;
        
        $option = collect($this->options)->firstWhere('id', $id);
        
        if ($option) {
            $this->selectedLabel = $option['reference'];
            $this->search = '';
            
            // Replicate the payload structure expected by SaleReturnCreateForm
            // We need to fetch the full details now
            $payload = $this->buildSalePayload($id);
            if ($payload) {
                $this->dispatch('saleReferenceSelected', $payload);
            }
        }
    }

    public function clearSelection(): void
    {
        $this->selected = null;
        $this->selectedLabel = null;
        $this->search = '';
        $this->options = [];
        $this->dispatch('saleReferenceSelected', ['id' => null]);
    }
    
    public function closeDropdown(): void
    {
        $this->open = false;
    }

    protected function resolveSelectedLabel(int $id, ?string $reference = null): void
    {
        if ($reference) {
            $this->selectedLabel = $reference;
            return;
        }
        
        $sale = Sale::find($id);
        if ($sale) {
            $this->selectedLabel = $sale->reference;
        }
    }

    protected function buildSalePayload(int $saleId): ?array
    {
        $sale = Sale::find($saleId);

        if (! $sale || ! $this->eligibilityService->isSaleEligible($sale)) {
            return null;
        }

        $summary = $this->eligibilityService->summariseSale($sale);

        if ($summary['returnable_lines'] === 0) {
            // Even if eligible status, if nothing to return, we might want to alert or just return null
             return null;
        }

        $saleDate = $sale->getAttribute('date');

        return [
            'id' => $sale->id,
            'reference' => $sale->reference,
            'customer_name' => $sale->customer_name,
            'status' => $sale->status,
            'date' => $saleDate ? (string) $saleDate : null,
            'returnable_lines' => $summary['returnable_lines'],
            'total_available_quantity' => $summary['total_available_quantity'],
            'requires_serials' => $summary['requires_serials'],
            'bundle_lines' => $summary['bundle_lines'],
            'rows' => $summary['rows']->map(fn ($row) => $row)->all(),
        ];
    }

    public function render()
    {
        return view('livewire.sales-return.sale-reference-search-dropdown');
    }
}
