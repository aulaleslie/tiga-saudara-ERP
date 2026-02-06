<?php

namespace App\Livewire\Product;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Setting\Entities\Setting;

class ProductSerialHistoryTable extends Component
{
    use WithPagination;

    public $productId;
    public $searchQuery = '';
    public int $perPage = 10;
    public array $expandedSerials = [];

    protected string $paginationTheme = 'bootstrap';

    public const EVENT_LABELS = [
        'RECEIVED'          => 'Diterima dari Pembelian',
        'SOLD'              => 'Terjual',
        'SALE_RETURNED'     => 'Retur dari Pelanggan',
        'PURCHASE_RETURNED' => 'Retur ke Supplier',
        'REPAIR_RECEIVED'   => 'Diterima dari Perbaikan',
        'LOCATION_TRANSFER' => 'Pindah Lokasi',
        'MARKED_BROKEN'     => 'Ditandai Rusak',
        'STATUS_CHANGED'    => 'Perubahan Status',
    ];

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

    public function toggleExpand($serialId): void
    {
        if (in_array($serialId, $this->expandedSerials)) {
            $this->expandedSerials = array_diff($this->expandedSerials, [$serialId]);
        } else {
            $this->expandedSerials[] = $serialId;
        }
    }

    public function getSerialNumbersProperty()
    {
        $settingId = $this->getActiveSettingId();

        $query = ProductSerialNumber::where('product_id', $this->productId)
            ->with(['location', 'histories' => function ($q) {
                $q->orderBy('created_at', 'desc')
                  ->with(['location', 'user', 'reference']);
            }])
            ->withCount('histories');

        if (!empty($this->searchQuery)) {
            $query->where('serial_number', 'like', '%' . $this->searchQuery . '%');
        }

        return $query->orderBy('serial_number')->paginate($this->perPage);
    }

    public function render(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        return view('livewire.product.product-serial-history-table', [
            'serialNumbers' => $this->serialNumbers,
        ]);
    }
}
