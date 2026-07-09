<?php

namespace App\Livewire\PricePoint;

use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Product;
use Modules\Setting\Entities\Setting;
use Livewire\Attributes\Url;

class Browser extends Component
{
    use WithPagination;

    // If you have other Livewire components on the page, give this paginator its own name
    protected string $pageName = 'pp';         // <- custom page param
    protected string $paginationTheme = 'tailwind';

    public Setting $setting;

    #[Url]                     // just bind q to the URL (no except)
    public string $q = '';

    #[Url(as: 'pp')]           // page -> ?pp=
    public int $page = 1;

    public int $perPage = 12;

    // Customer selection state
    public ?int $selectedCustomerId = null;
    public ?string $selectedCustomerLabel = null;
    public ?string $selectedCustomerTier = null;

    // Customer search
    public string $customerSearchText = '';
    public array $customerSearchResults = [];
    public bool $showCustomerDropdown = false;

    public function updatedCustomerSearchText(string $value): void
    {
        $this->searchCustomers($value);
    }

    private function customerDisplayName(Customer $customer): string
    {
        $contactName = trim((string) $customer->contact_name);
        $customerName = trim((string) $customer->customer_name);

        return $contactName !== ''
            ? $contactName
            : ($customerName !== '' ? $customerName : 'Unnamed');
    }

    public function mount(Setting $setting): void
    {
        $this->setting = $setting;
    }

    public function updatingQ(): void
    {
        // whenever search changes, reset to page 1
        $this->resetPage(pageName: $this->pageName);
    }

    public function searchNow(): void
    {
        $clean = preg_replace("/[\r\n]+/", '', (string) $this->q);
        $this->q = trim($clean);

        $this->resetPage(pageName: $this->pageName);
        $this->dispatch('refocus-search');
    }

    public function searchCustomers(string $term = ''): void
    {
        $this->customerSearchText = trim($term);

        if (strlen($this->customerSearchText) < 2) {
            $this->customerSearchResults = [];
            $this->showCustomerDropdown = false;
            return;
        }

        $like = "%{$this->customerSearchText}%";

        $this->customerSearchResults = Customer::query()
            ->where('customer_name', 'like', $like)
            ->orWhere('contact_name', 'like', $like)
            ->limit(10)
            ->get(['id', 'customer_name', 'contact_name', 'tier'])
            ->map(fn (Customer $c) => [
                'id' => $c->id,
                'label' => $this->customerDisplayName($c),
                'tier' => $c->tier,
            ])
            ->toArray();

        $this->showCustomerDropdown = count($this->customerSearchResults) > 0;
    }

    public function selectCustomer(int $customerId): void
    {
        $customer = Customer::find($customerId);

        if ($customer) {
            $this->selectedCustomerId = $customer->id;
            $this->selectedCustomerLabel = $this->customerDisplayName($customer);
            $this->selectedCustomerTier = $customer->tier;
            $this->customerSearchText = '';
            $this->customerSearchResults = [];
            $this->showCustomerDropdown = false;

            // Reset pagination when customer changes
            $this->resetPage(pageName: $this->pageName);
        }
    }

    public function clearCustomer(): void
    {
        $this->selectedCustomerId = null;
        $this->selectedCustomerLabel = null;
        $this->selectedCustomerTier = null;
        $this->customerSearchText = '';
        $this->customerSearchResults = [];
        $this->showCustomerDropdown = false;

        // Reset pagination when customer is cleared
        $this->resetPage(pageName: $this->pageName);
    }

    private function resolveContextualPrice(?int $salePrice, ?int $tier1Price, ?int $tier2Price): array
    {
        // No customer selected or no recognized tier
        if (!$this->selectedCustomerTier) {
            return ['price' => $salePrice, 'label' => 'Umum'];
        }

        // WHOLESALER tier: use tier_1_price with fallback to sale_price
        if ($this->selectedCustomerTier === 'WHOLESALER') {
            if ($tier1Price > 0) {
                return ['price' => $tier1Price, 'label' => 'Grosir'];
            }
            return ['price' => $salePrice, 'label' => 'Umum'];
        }

        // RESELLER tier: use tier_2_price with fallback to sale_price
        if ($this->selectedCustomerTier === 'RESELLER') {
            if ($tier2Price > 0) {
                return ['price' => $tier2Price, 'label' => 'Reseller'];
            }
            return ['price' => $salePrice, 'label' => 'Umum'];
        }

        // Unknown tier: default to sale_price
        return ['price' => $salePrice, 'label' => 'Umum'];
    }

    public function render()
    {
        $term = trim($this->q);

        $settingId = $this->setting->id;

        $products = Product::query()
            ->select('products.*')
            ->selectSub(function ($q) {
                $q->from('product_prices as pp')
                    ->select('pp.sale_price')
                    ->whereColumn('pp.product_id', 'products.id')
                    ->where('pp.setting_id', $this->setting->id)
                    ->limit(1);
            }, 'display_sale_price')
            ->selectSub(function ($q) {
                $q->from('product_prices as pp')
                    ->select('pp.tier_1_price')
                    ->whereColumn('pp.product_id', 'products.id')
                    ->where('pp.setting_id', $this->setting->id)
                    ->limit(1);
            }, 'display_tier_1_price')
            ->selectSub(function ($q) {
                $q->from('product_prices as pp')
                    ->select('pp.tier_2_price')
                    ->whereColumn('pp.product_id', 'products.id')
                    ->where('pp.setting_id', $this->setting->id)
                    ->limit(1);
            }, 'display_tier_2_price')
            ->whereExists(function ($q) {
                $q->from('product_prices as pp')
                    ->select(DB::raw(1))
                    ->whereColumn('pp.product_id', 'products.id')
                    ->where('pp.setting_id', $this->setting->id);
            })
            ->with([
                'brand:id,name',
                'category:id,category_name',
                'conversions' => function ($q) use ($settingId) {
                    $q->with([
                        'unit:id,name',
                        'prices' => fn ($priceQuery) => $priceQuery->where('setting_id', $settingId),
                    ]);
                },
            ])
            ->when($term !== '', function ($q) use ($term) {
                $like = "%{$term}%";
                $tokens = array_filter(explode(' ', $term), 'strlen');

                $q->where(function ($qq) use ($like, $tokens) {
                    // 1. Scanner-code matching (whole input)
                    $qq->where('products.barcode', 'like', $like)
                        ->orWhereHas('conversions', fn ($u) => $u->where('barcode', 'like', $like))
                        ->orWhereHas('serialNumbers', fn ($s) => $s->where('serial_number', 'like', $like));

                    // 2. Free-text matching (tokenized)
                    if (!empty($tokens)) {
                        $qq->orWhere(function ($ft) use ($tokens) {
                            foreach ($tokens as $token) {
                                $ft->where(function ($sub) use ($token) {
                                    $sub->where('products.product_name', 'like', '%' . $token . '%')
                                        ->orWhere('products.product_code', 'like', '%' . $token . '%')
                                        ->orWhereHas('category', function ($cat) use ($token) {
                                            $cat->where('category_name', 'like', '%' . $token . '%');
                                        })
                                        ->orWhereHas('brand', function ($brand) use ($token) {
                                            $brand->where('name', 'like', '%' . $token . '%');
                                        });
                                });
                            }
                        });
                    }
                });
            })
            ->orderBy('products.product_name')
            ->paginate(
                perPage: $this->perPage,
                pageName: $this->pageName // <- IMPORTANT
            );

        // Add contextual prices to each product to avoid N+1 queries in the view
        $products->transform(function ($product) {
            $product->contextual_price = $this->resolveContextualPrice(
                $product->display_sale_price,
                $product->display_tier_1_price,
                $product->display_tier_2_price
            );
            return $product;
        });

        return view('livewire.price-point.browser', compact('products'));
    }
}
