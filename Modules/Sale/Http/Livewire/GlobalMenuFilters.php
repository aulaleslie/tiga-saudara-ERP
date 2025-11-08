<?php

namespace Modules\Sale\Http\Livewire;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Livewire\Component;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\Category;
use Modules\Setting\Entities\Location;
use App\Models\User;

class GlobalMenuFilters extends Component
{
    public array $filters = [];
    public bool $isOpen = false;

    // Filter options
    public $customers;
    public $products;
    public $categories;
    public $locations;
    public $sellers;
    public $statuses;

    protected $settingId;

    public function mount(): void
    {
        $this->settingId = session('setting_id');

        // Initialize filters
        $this->filters = [
            'serial_number' => '',
            'sale_reference' => '',
            'customer_id' => '',
            'customer_name' => '',
            'status' => '',
            'date_from' => '',
            'date_to' => '',
            'location_id' => '',
            'product_id' => '',
            'product_category_id' => '',
            'serial_number_status' => '',
            'seller_id' => '',
        ];

        // Load filter options
        $this->loadFilterOptions();

        // Initialize statuses
        $this->statuses = [
            'DRAFTED' => 'Drafted',
            'APPROVED' => 'Approved',
            'DISPATCHED' => 'Dispatched',
            'RETURNED' => 'Returned',
        ];
    }

    public function render(): Factory|View|Application
    {
        return view('sale::livewire.global-menu-filters');
    }

    protected function loadFilterOptions(): void
    {
        if (!$this->settingId) {
            return;
        }

        try {
            // Load customers (limit to recent/active ones)
            $this->customers = Customer::query()
                ->where('setting_id', $this->settingId)
                ->orderBy('customer_name')
                ->limit(100)
                ->get(['id', 'customer_name']);

            // Load products
            $this->products = Product::query()
                ->orderBy('product_name')
                ->limit(200)
                ->get(['id', 'product_name', 'product_code']);

            // Load product categories
            $this->categories = Category::query()
                ->orderBy('category_name')
                ->get(['id', 'category_name']);

            // Load locations for this setting
            $this->locations = Location::query()
                ->where('setting_id', $this->settingId)
                ->orderBy('name')
                ->get(['id', 'name']);

            // Load sellers (users associated with this setting)
            $this->sellers = User::query()
                ->whereHas('settings', function($query) {
                    $query->where('setting_id', $this->settingId);
                })
                ->orderBy('name')
                ->get(['id', 'name']);
        } catch (\Exception $e) {
            \Log::warning('Failed to load filter options: ' . $e->getMessage());
        }
    }

    public function applyFilters(): void
    {
        // Validate date range
        if (!empty($this->filters['date_from']) && !empty($this->filters['date_to'])) {
            if ($this->filters['date_from'] > $this->filters['date_to']) {
                session()->flash('error', 'Date from cannot be later than date to');
                return;
            }
        }

        // Emit filters to parent component
        $this->dispatch('filtersApplied', $this->filters);
    }

    public function clearFilters(): void
    {
        $this->filters = array_fill_keys(array_keys($this->filters), '');
        $this->dispatch('filtersCleared');
    }

    public function toggleFilters(): void
    {
        $this->isOpen = !$this->isOpen;
    }

    public function updatedFiltersDateFrom(): void
    {
        // Auto-adjust date_to if it's before date_from
        if (!empty($this->filters['date_from']) && !empty($this->filters['date_to'])) {
            if ($this->filters['date_from'] > $this->filters['date_to']) {
                $this->filters['date_to'] = $this->filters['date_from'];
            }
        }
    }

    public function updatedFiltersDateTo(): void
    {
        // Auto-adjust date_from if it's after date_to
        if (!empty($this->filters['date_from']) && !empty($this->filters['date_to'])) {
            if ($this->filters['date_from'] > $this->filters['date_to']) {
                $this->filters['date_from'] = $this->filters['date_to'];
            }
        }
    }

    public function presetDateRange($range): void
    {
        $now = now();

        switch ($range) {
            case 'today':
                $this->filters['date_from'] = $now->format('Y-m-d');
                $this->filters['date_to'] = $now->format('Y-m-d');
                break;
            case 'yesterday':
                $yesterday = $now->subDay();
                $this->filters['date_from'] = $yesterday->format('Y-m-d');
                $this->filters['date_to'] = $yesterday->format('Y-m-d');
                break;
            case 'this_week':
                $this->filters['date_from'] = $now->startOfWeek()->format('Y-m-d');
                $this->filters['date_to'] = $now->endOfWeek()->format('Y-m-d');
                break;
            case 'last_week':
                $this->filters['date_from'] = $now->subWeek()->startOfWeek()->format('Y-m-d');
                $this->filters['date_to'] = $now->subWeek()->endOfWeek()->format('Y-m-d');
                break;
            case 'this_month':
                $this->filters['date_from'] = $now->startOfMonth()->format('Y-m-d');
                $this->filters['date_to'] = $now->endOfMonth()->format('Y-m-d');
                break;
            case 'last_month':
                $this->filters['date_from'] = $now->subMonth()->startOfMonth()->format('Y-m-d');
                $this->filters['date_to'] = $now->subMonth()->endOfMonth()->format('Y-m-d');
                break;
        }
    }

    public function quickFilter($type, $value): void
    {
        switch ($type) {
            case 'status':
                $this->filters['status'] = $value;
                break;
            case 'seller':
                $this->filters['seller_id'] = $value;
                break;
            case 'location':
                $this->filters['location_id'] = $value;
                break;
        }

        $this->applyFilters();
    }
}