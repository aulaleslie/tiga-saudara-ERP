<?php

namespace App\Livewire\Product;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Setting\Entities\Setting;

class ProductSerialNumbersTable extends Component
{
    use WithPagination;

    public $productId;
    public $searchQuery = '';
    public $editingId = null;
    public $editingValue = '';
    public $errorMessage = '';
    public int $perPage = 10;
    public string $currentTab = 'sellable';

    protected string $paginationTheme = 'bootstrap';

    public function mount($productId): void
    {
        $this->productId = $productId;
    }

    protected function getActiveSettingId(): int
    {
        $user = auth()->user();

        return (int) (
            session('setting_id')
            ?? optional($user?->settings()->select('settings.id')->first())?->id
            ?? Setting::query()->min('id')
        );
    }

    public function updatedSearchQuery(): void
    {
        $this->resetPage();
    }

    public function setTab($tab): void
    {
        $this->currentTab = $tab;
        $this->resetPage();
    }

    public function clearSearch(): void
    {
        $this->searchQuery = '';
        $this->currentTab = 'sellable';
        $this->resetPage();
    }

    public function startEdit($id, $currentValue): void
    {
        $this->editingId = $id;
        $this->editingValue = $currentValue;
        $this->errorMessage = '';
    }

    public function cancelEdit(): void
    {
        $this->editingId = null;
        $this->editingValue = '';
        $this->errorMessage = '';
    }

    public function saveEdit(): void
    {
        $this->errorMessage = '';

        $trimmedValue = trim($this->editingValue);

        if (empty($trimmedValue)) {
            $this->errorMessage = 'Serial number tidak boleh kosong.';
            return;
        }

        // Check if the new serial number already exists for the same product (excluding current)
        $exists = ProductSerialNumber::where('serial_number', $trimmedValue)
            ->where('product_id', $this->productId)
            ->where('id', '!=', $this->editingId)
            ->exists();

        if ($exists) {
            $this->errorMessage = 'Serial number "' . $trimmedValue . '" sudah digunakan untuk produk ini.';
            return;
        }

        // Check if serial number is pending in a PENDING receiving for the same product
        $existsPending = \Modules\Purchase\Entities\ReceivedNoteDetail::whereHas('receivedNote', function ($q) {
            $q->where('status', \Modules\Purchase\Entities\ReceivedNote::STATUS_PENDING);
        })
            ->whereHas('purchaseDetail', function ($q) {
                $q->where('product_id', $this->productId);
            })
            ->whereNotNull('pending_serial_numbers')
            ->get()
            ->contains(function ($detail) use ($trimmedValue) {
                $pendingSerials = $detail->pending_serial_numbers ?? [];
                return in_array($trimmedValue, $pendingSerials);
            });

        if ($existsPending) {
            $this->errorMessage = 'Serial number "' . $trimmedValue . '" sedang dalam proses penerimaan yang menunggu persetujuan.';
            return;
        }

        $serial = ProductSerialNumber::find($this->editingId);
        if ($serial) {
            $serial->update([
                'serial_number' => $trimmedValue,
            ]);
        }

        $this->editingId = null;
        $this->editingValue = '';
    }

    public function getSerialNumbersProperty()
    {
        $settingId = $this->getActiveSettingId();

        $query = ProductSerialNumber::where('product_id', $this->productId)
            ->whereNull('dispatch_detail_id')
            ->where(function ($q) {
                $q->whereNull('status')
                    ->orWhereRaw('LOWER(status) != ?', ['returned']);
            })
            ->whereHas('location', function ($q) use ($settingId) {
                $q->where('setting_id', $settingId);
            })
            ->with(['location', 'tax']);

        if (!empty($this->searchQuery)) {
            $query->where('serial_number', 'like', '%' . $this->searchQuery . '%');
        }

        if ($this->currentTab === 'sellable') {
            $query->where('is_broken', false)
                ->where('is_in_return_process', false);
        } elseif ($this->currentTab === 'broken') {
            $query->where('is_broken', true);
        } elseif ($this->currentTab === 'returning') {
            $query->where('is_in_return_process', true);
        }

        return $query->orderBy('serial_number')->paginate($this->perPage);
    }

    public function render(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        return view('livewire.product.product-serial-numbers-table', [
            'serialNumbers' => $this->serialNumbers,
        ]);
    }
}
