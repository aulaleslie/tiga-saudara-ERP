<?php

namespace App\Livewire\Product;

use Livewire\Component;
use Livewire\WithPagination;
use Modules\Product\Entities\Transaction;
use Modules\Setting\Entities\Setting;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;

class ProductTransactionsTable extends Component
{
    use WithPagination;

    public $productId;
    public $searchQuery = '';
    public int $perPage = 10;

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

    public function getTransactionsProperty()
    {
        $settingId = $this->getActiveSettingId();

        $query = Transaction::where('product_id', $this->productId)
            ->whereHas('location', function ($q) use ($settingId) {
                $q->where('setting_id', $settingId);
            })
            ->with(['location' => function ($q) {
                $q->select('id', 'name', 'setting_id');
            }]);

        if (!empty($this->searchQuery)) {
            $query->where(function ($q) {
                $q->where('type', 'like', '%' . $this->searchQuery . '%')
                    ->orWhere('reason', 'like', '%' . $this->searchQuery . '%')
                    ->orWhereHas('location', function ($locationQuery) {
                        $locationQuery->where('name', 'like', '%' . $this->searchQuery . '%');
                    });
            });
        }

        return $query->orderBy('created_at', 'desc')->paginate($this->perPage);
    }

    public function render(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        return view('livewire.product.product-transactions-table', [
            'transactions' => $this->transactions,
        ]);
    }
}
