<?php

namespace App\Livewire\SalesReturn;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Livewire\Component;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\SalesReturn\Entities\SaleReturnDetail;

class SaleSerialNumberLoader extends Component
{
    public string $query = '';
    public array $searchResults = [];
    public int $howMany = 10;
    public bool $isFocused = false;

    public int $index;
    public ?int $dispatch_detail_id = null;
    public ?int $product_id = null;
    public ?int $sale_return_id = null;

    protected $listeners = [
        'refreshSerialLoader' => 'refreshList',
    ];

    public function mount(int $index, ?int $dispatchDetailId = null, ?int $productId = null, ?int $saleReturnId = null): void
    {
        $this->index = $index;
        $this->dispatch_detail_id = $dispatchDetailId;
        $this->product_id = $productId;
        $this->sale_return_id = $saleReturnId;
    }

    public function render(): View|Factory|Application
    {
        return view('livewire.sales-return.sale-serial-number-loader');
    }

    public function updatedQuery(): void
    {
        if ($this->isFocused) {
            $this->searchSerialNumbers();
        }
    }

    public function refreshList(): void
    {
        if ($this->isFocused) {
            $this->searchSerialNumbers();
        }
    }

    public function resetQuery(): void
    {
        $this->query = '';
        $this->searchResults = [];
        $this->isFocused = false;
    }

    public function searchSerialNumbers(): void
    {
        if (! $this->dispatch_detail_id) {
            $this->searchResults = [];
            return;
        }

        $reserved = $this->reservedSerialIds();

        $query = ProductSerialNumber::query()
            ->where('dispatch_detail_id', $this->dispatch_detail_id)
            ->when($this->query, function ($builder) {
                $builder->where('serial_number', 'like', '%' . $this->query . '%');
            })
            ->when(! empty($reserved), function ($builder) use ($reserved) {
                $builder->whereNotIn('id', $reserved);
            })
            ->orderBy('serial_number')
            ->limit($this->howMany)
            ->get(['id', 'serial_number']);

        $this->searchResults = $query->map(function ($serial) {
            return [
                'id' => $serial->id,
                'serial_number' => $serial->serial_number,
            ];
        })->all();
    }

    public function loadMore(): void
    {
        $this->howMany += 10;
        $this->searchSerialNumbers();
    }

    public function selectSerial(int $serialId): void
    {
        $serial = ProductSerialNumber::query()
            ->where('dispatch_detail_id', $this->dispatch_detail_id)
            ->find($serialId);

        if (! $serial) {
            return;
        }

        $this->dispatch('serialNumberSelected', $this->index, [
            'id' => $serial->id,
            'serial_number' => $serial->serial_number,
        ]);

        $this->query = '';
        $this->searchResults = [];
        $this->isFocused = false;
    }

    public function resetFocusAfterDelay(): void
    {
        usleep(200000);
        $this->isFocused = false;
    }

    protected function reservedSerialIds(): array
    {
        if (! $this->dispatch_detail_id) {
            return [];
        }

        return SaleReturnDetail::query()
            ->where('dispatch_detail_id', $this->dispatch_detail_id)
            ->when($this->sale_return_id, function ($query) {
                $query->where('sale_return_id', '!=', $this->sale_return_id);
            })
            ->whereHas('saleReturn', function ($query) {
                $query->whereNotIn('approval_status', ['rejected']);
            })
            ->get(['serial_number_ids'])
            ->flatMap(function ($detail) {
                return collect($detail->serial_number_ids ?? []);
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
