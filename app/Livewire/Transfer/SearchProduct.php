<?php

namespace App\Livewire\Transfer;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Adjustment\Services\TransferScanResolverService;

class SearchProduct extends Component
{
    public string $query = '';
    public $search_results;
    public int $how_many = 5;

    public $locationId;  // Add locationId as a public property
    public bool $is_broken_mode = false; // Add explicit broken mode toggle

    public function mount($locationId = null): void
    {
        $this->search_results = Collection::empty();
        Log::info("locationSelectedMounted: " . $locationId);
        $this->locationId = $locationId;
        $this->is_broken_mode = false;

        $this->search_results = Collection::empty();
    }

    public function render(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        return view('livewire.transfer.search-product');
    }

    public function updatedQuery(): void
    {
        if (empty($this->query) || strlen($this->query) < 2) {
            $this->search_results = Collection::empty();
            return;
        }

        $settingId = session('setting_id');

        $query = Product::where('stock_managed', true)
            ->globalSearch($this->query);

        if ($this->locationId) {
            // Eager load only the specific stock for this location
            $query->with(['productStocks' => function ($q) use ($settingId) {
                $q->where('location_id', $this->locationId);
                if ($settingId) {
                    $q->whereHas('location', function ($q2) use ($settingId) {
                        $q2->where('setting_id', $settingId);
                    });
                }
            }]);

            // Filter to only products that have stock at this location
            $query->whereHas('productStocks', function ($q) use ($settingId) {
                $q->where('location_id', $this->locationId);
                if ($settingId) {
                    $q->whereHas('location', function ($q2) use ($settingId) {
                        $q2->where('setting_id', $settingId);
                    });
                }
                if ($this->is_broken_mode) {
                    $q->whereRaw('(COALESCE(broken_quantity_tax, 0) + COALESCE(broken_quantity_non_tax, 0)) > 0');
                } else {
                    // Support legacy stock structure fallback
                    $q->where(function ($sub) {
                        $sub->whereRaw('(COALESCE(quantity_tax, 0) + COALESCE(quantity_non_tax, 0)) > 0')
                            ->orWhereRaw('(COALESCE(quantity, 0) - COALESCE(broken_quantity_tax, 0) - COALESCE(broken_quantity_non_tax, 0)) > 0');
                    });
                }
            });
        }

        $this->search_results = $query
            ->limit($this->how_many)
            ->get()
            ->map(function ($product) {
                $product->product_quantity = $this->calculateStockQuantity($product);
                return $product;
            })
            ->filter(function ($product) {
                return $product->product_quantity > 0;
            });
    }

    private function calculateStockQuantity(Product $product): int
    {
        if (!$this->locationId) {
            return 0;
        }

        // Use eager loaded relation if available to avoid N+1
        if ($product->relationLoaded('productStocks') && $product->productStocks->isNotEmpty()) {
            $stock = $product->productStocks->first();
        } else {
            // Fallback just in case (should not happen with eager loading)
            $stock = $this->fetchStockFallback($product->id);
        }

        if (!$stock) {
            return 0;
        }

        if ($this->is_broken_mode) {
            return (int) ($stock->broken_quantity_tax ?? 0) + (int) ($stock->broken_quantity_non_tax ?? 0);
        }

        $availableQuantity = (int) ($stock->quantity_tax ?? 0) + (int) ($stock->quantity_non_tax ?? 0);

        if ($availableQuantity === 0 && !is_null($stock->quantity)) {
            $brokenQuantity = (int) ($stock->broken_quantity_tax ?? 0) + (int) ($stock->broken_quantity_non_tax ?? 0);
            $availableQuantity = max(0, (int) $stock->quantity - $brokenQuantity);
        }

        return max(0, $availableQuantity);
    }

    private function fetchStockFallback($productId)
    {
        $settingId = session('setting_id');
        return ProductStock::query()
            ->where('product_id', $productId)
            ->where('location_id', $this->locationId)
            ->when($settingId, function ($query) use ($settingId) {
                $query->whereHas('location', function ($q) use ($settingId) {
                    $q->where('setting_id', $settingId);
                });
            })
            ->first();
    }

    public function loadMore(): void
    {
        $this->how_many += 5;
        $this->updatedQuery();
    }

    public function resetQuery(): void
    {
        $this->query = '';
        $this->how_many = 5;
        $this->search_results = Collection::empty();
    }

    public function selectProduct($product): void
    {
        $payload = is_array($product) ? $product : $product->toArray();
        $payload['is_broken_mode'] = $this->is_broken_mode;
        $this->dispatch('productSelected', $payload);
        $this->resetQuery();
    }

    public function scanBarcode(string $query, TransferScanResolverService $scanService)
    {
        $query = trim($query);
        if (empty($query) || empty($this->locationId)) {
            $this->dispatch('scanFailed', 'Silakan masukkan barcode dan pilih lokasi asal.');
            return;
        }

        $settingId = session('setting_id');
        if (!$settingId) {
            $this->dispatch('scanFailed', 'Pengaturan (Setting) belum dipilih.');
            return;
        }

        $result = $scanService->resolve($settingId, $query, $this->locationId);

        if ($result['type'] === 'none') {
            // Also try to fallback to normal search if scan fails
            $this->query = $query;
            $this->updatedQuery();
            if ($this->search_results->count() === 1) {
                $this->selectProduct($this->search_results->first());
            } else if ($this->search_results->isEmpty()) {
                $this->dispatch('scanFailed', 'Produk/Serial tidak ditemukan atau stok kosong di lokasi asal.');
            }
            return;
        }

        if ($result['type'] === 'product_exact') {
            $productData = $result['product'];
            $quantity = $this->getProductQuantityAtLocation($productData['id'], $this->locationId);
            
            if ($quantity > 0) {
                // Fetch the eloquent model to dispatch
                $product = Product::find($productData['id'])->toArray();
                
                // Apply base-unit normalization if scanned via a conversion barcode
                $scanQuantity = 1;
                if (($productData['resolved_via'] ?? '') === 'conversion_barcode' && !empty($productData['conversion'])) {
                    $scanQuantity = (int) $productData['conversion']['conversion_factor'];
                }
                
                $product['scan_quantity_multiplier'] = $scanQuantity;
                $product['is_broken_mode'] = $this->is_broken_mode;
                
                $this->dispatch('productSelected', $product);
                $this->resetQuery();
            } else {
                $this->dispatch('scanFailed', 'Stok kosong di lokasi asal.');
            }
        } elseif ($result['type'] === 'serial_exact') {
            $serialData = $result['serial'];
            
            // Check if serial is at the selected origin location
            if ($serialData['location_id'] != $this->locationId) {
                $this->dispatch('scanFailed', 'Nomor Seri tidak berada di lokasi asal terpilih.');
                return;
            }

            $product = Product::find($serialData['product_id']);
            $payload = $product->toArray();
            $payload['is_broken_mode'] = $this->is_broken_mode;
            
            $isSerialBroken = (bool) ($serialData['is_broken'] ?? false);
            if ($isSerialBroken !== $this->is_broken_mode) {
                $modeStr = $this->is_broken_mode ? 'Rusak' : 'Normal';
                $this->dispatch('scanFailed', "Nomor Seri status rusak tidak cocok dengan mode $modeStr.");
                return;
            }

            $this->dispatch('productSelected', $payload);
            
            // Need to notify the table to add this serial
            $this->dispatch('serialScanned', [
                'id' => $serialData['id'],
                'product_id' => $serialData['product_id'],
                'serial_number' => $serialData['serial_number'],
                'tax_id' => $serialData['tax_id'],
                'is_broken' => $isSerialBroken
            ]);
            
            $this->resetQuery();
        }
    }
}
