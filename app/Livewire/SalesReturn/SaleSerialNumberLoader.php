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
    public string $error_message = '';
    public array $existingSerials = [];
    public bool $approvalLocked = false;

    protected $listeners = [
        'refreshSerialLoader' => 'refreshList',
    ];

    public function mount(int $index, ?int $dispatchDetailId = null, ?int $productId = null, ?int $saleReturnId = null, array $existingSerials = [], bool $approvalLocked = false): void
    {
        $this->index = $index;
        $this->dispatch_detail_id = $dispatchDetailId;
        $this->product_id = $productId;
        $this->sale_return_id = $saleReturnId;
        $this->existingSerials = $existingSerials;
        $this->approvalLocked = $approvalLocked;
    }

    public function render(): View|Factory|Application
    {
        return view('livewire.sales-return.sale-serial-number-loader');
    }

    public function addSerial(): void
    {
        if ($this->approvalLocked) {
            return;
        }

        $this->error_message = '';
        $query = trim($this->query);

        if (empty($query)) {
            return;
        }

        $serial = ProductSerialNumber::query()
            ->where('product_id', $this->product_id)
            ->where('serial_number', $query)
            ->first();

        if (! $serial) {
            $this->error_message = 'Nomor seri tidak ditemukan.';
            return;
        }

        if ($this->dispatch_detail_id && $serial->dispatch_detail_id != $this->dispatch_detail_id) {
            $this->error_message = 'Nomor seri ini tidak berasal dari pengiriman ini.';
            return;
        }

        $alreadyAdded = collect($this->existingSerials)->contains('id', $serial->id);
        if ($alreadyAdded) {
            $this->error_message = 'Nomor seri ini sudah ditambahkan.';
            return;
        }

        $reserved = $this->reservedSerialIds();
        if (in_array($serial->id, $reserved)) {
            $this->error_message = 'Nomor seri ini sedang dalam proses retur lain.';
            return;
        }

        $this->dispatch('serialNumberSelected', $this->index, [
            'id' => $serial->id,
            'serial_number' => $serial->serial_number,
        ]);

        $this->resetQuery();
    }

    public function updatedQuery(): void
    {
        $this->error_message = '';
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
        $this->error_message = '';
    }

    public function searchSerialNumbers(): void
    {
        if (! $this->dispatch_detail_id) {
            $this->searchResults = [];
            return;
        }

        $reserved = $this->reservedSerialIds();
        $excluded = array_merge($reserved, collect($this->existingSerials)->pluck('id')->all());

        $query = ProductSerialNumber::query()
            ->where('dispatch_detail_id', $this->dispatch_detail_id)
            ->when($this->query, function ($builder) {
                $builder->where('serial_number', 'like', '%' . $this->query . '%');
            })
            ->when(! empty($excluded), function ($builder) use ($excluded) {
                $builder->whereNotIn('id', $excluded);
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
        if ($this->approvalLocked) {
            return;
        }

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
