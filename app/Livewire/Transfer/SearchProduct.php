<?php

namespace App\Livewire\Transfer;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Reactive;
use Livewire\Component;
use Modules\Adjustment\Entities\Transfer;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Adjustment\Services\TransferScanResolverService;
use Modules\Adjustment\Services\TransferStockVisibility;

class SearchProduct extends Component
{
    public string $query = '';
    public $search_results;
    public int $how_many = 5;

    #[Locked]
    public $locationId;  // Add locationId as a public property

    /**
     * Transfer mode is owned by the parent TransferStockForm and passed down
     * reactively; this component cannot independently change it. Locked
     * additionally rejects any crafted direct client update.
     */
    #[Reactive]
    #[Locked]
    public ?string $stockCondition = null;

    public function mount($locationId = null, ?string $stockCondition = null): void
    {
        $this->search_results = Collection::empty();
        Log::info("locationSelectedMounted: " . $locationId);
        $this->locationId = $locationId;
        $this->stockCondition = $stockCondition;

        $this->search_results = Collection::empty();
    }

    private function isBrokenMode(): bool
    {
        return $this->stockCondition === Transfer::CONDITION_BREAKAGE;
    }

    /**
     * A valid tenant-owned origin location must be selected before any
     * search, scan, or selection is permitted; this rejects direct Livewire
     * calls that bypass the disabled UI state.
     */
    private function hasValidOrigin(): bool
    {
        if (!$this->locationId) {
            return false;
        }

        $settingId = session('setting_id');

        return \Modules\Setting\Entities\Location::query()
            ->whereKey($this->locationId)
            ->active()
            ->when($settingId, fn ($q) => $q->where('setting_id', $settingId))
            ->exists();
    }

    public function render(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        return view('livewire.transfer.search-product');
    }

    /**
     * Minimal explicit product projection dispatched to the product table.
     * Privileged users keep the full product attribute set (existing
     * behavior); blind users receive only what is needed to render and
     * select the row: identity fields and no stock/pricing/cost data.
     */
    private function projectProduct(Product $product): array
    {
        if (TransferStockVisibility::canView()) {
            return $product->toArray();
        }

        return [
            'id'                      => $product->id,
            'product_name'            => $product->product_name,
            'product_code'            => $product->product_code,
            'barcode'                 => $product->barcode,
            'serial_number_required'  => (bool) $product->serial_number_required,
        ];
    }

    public function updatedQuery(): void
    {
        if (!$this->hasValidOrigin()) {
            $this->search_results = Collection::empty();
            $this->dispatch('scanFailed', 'Silakan pilih Lokasi Asal terlebih dahulu.');
            return;
        }

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
                if ($this->isBrokenMode()) {
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

        $canViewSystemStock = TransferStockVisibility::canView();

        $this->search_results = $query
            ->limit($this->how_many)
            ->get()
            ->map(function ($product) use ($canViewSystemStock) {
                $product->product_quantity = $this->calculateStockQuantity($product);
                return $product;
            })
            ->filter(function ($product) {
                return $product->product_quantity > 0;
            })
            ->map(function ($product) use ($canViewSystemStock) {
                // Livewire serializes every public property into wire state,
                // so a raw Eloquent Product (with its eager-loaded
                // productStocks relation carrying exact bucket quantities)
                // must never be stored here for a blind user. Privileged
                // users keep the existing full-model behavior; blind users
                // get a minimal identity-only projection.
                if ($canViewSystemStock) {
                    return $product;
                }

                // Plain array (not the raw Eloquent Product, which would
                // still carry the eager-loaded productStocks relation with
                // exact bucket quantities into Livewire's public wire
                // state): identity fields only, safe for a blind user.
                return [
                    'id'                      => $product->id,
                    'product_name'            => $product->product_name,
                    'product_code'            => $product->product_code,
                    'barcode'                 => $product->barcode,
                    'serial_number_required'  => (bool) $product->serial_number_required,
                ];
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

        if ($this->isBrokenMode()) {
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

    public function selectProduct($product, ?string $operationToken = null): void
    {
        if (!$this->hasValidOrigin()) {
            if ($operationToken !== null && $operationToken !== '') {
                $this->dispatch('transfer:scan-processed', ['token' => $operationToken]);
            }
            $this->dispatch('scanFailed', 'Silakan pilih Lokasi Asal terlebih dahulu.');
            $this->dispatch('select-scan-input');
            return;
        }

        $productId = is_array($product) ? ($product['id'] ?? null) : ($product->id ?? null);
        if (!$productId) {
            if ($operationToken !== null && $operationToken !== '') {
                $this->dispatch('transfer:scan-processed', ['token' => $operationToken]);
            }
            return;
        }

        $productPayload = [
            'id' => (int) $productId,
            'is_broken_mode' => $this->isBrokenMode(),
        ];
        if ($operationToken !== null && $operationToken !== '') {
            $productPayload['operation_token'] = $operationToken;
        }

        $this->dispatch('productSelected', $productPayload);
        $this->resetQuery();
        $this->dispatch('restore-scanner-focus');
    }

    public function scanBarcode(string $query, TransferScanResolverService $scanService, ?string $operationToken = null)
    {
        $query = trim($query);

        if (!$this->hasValidOrigin()) {
            if ($operationToken !== null && $operationToken !== '') {
                $this->dispatch('transfer:scan-processed', ['token' => $operationToken]);
            }
            $this->dispatch('scanFailed', 'Silakan masukkan barcode dan pilih lokasi asal.');
            $this->dispatch('select-scan-input');
            return;
        }

        if (empty($query)) {
            if ($operationToken !== null && $operationToken !== '') {
                $this->dispatch('transfer:scan-processed', ['token' => $operationToken]);
            }
            $this->dispatch('scanFailed', 'Silakan masukkan barcode dan pilih lokasi asal.');
            $this->dispatch('select-scan-input');
            return;
        }

        $settingId = session('setting_id');
        if (!$settingId) {
            if ($operationToken !== null && $operationToken !== '') {
                $this->dispatch('transfer:scan-processed', ['token' => $operationToken]);
            }
            $this->dispatch('scanFailed', 'Pengaturan (Setting) belum dipilih.');
            $this->dispatch('select-scan-input');
            return;
        }

        $result = $scanService->resolve($settingId, $query, $this->locationId, $this->isBrokenMode());

        if ($result['type'] === 'serial_rejected') {
            if ($operationToken !== null && $operationToken !== '') {
                $this->dispatch('transfer:scan-processed', ['token' => $operationToken]);
            }
            $this->dispatch('scanFailed', $result['message']);
            $this->dispatch('select-scan-input');
            return;
        }

        if ($result['type'] === 'none') {
            // Also try to fallback to normal search if scan fails
            $this->query = $query;
            $this->updatedQuery();
            if ($this->search_results->count() === 1) {
                // selectProduct carries the operation token through to the
                // productSelected dispatch and only the sibling table's
                // acknowledgment of that mutation advances the scan queue --
                // acknowledging here too, before the table confirms, would
                // let the queue advance ahead of the mutation it is meant to
                // gate.
                $this->selectProduct($this->search_results->first(), $operationToken);
                return;
            }

            if ($this->search_results->isEmpty()) {
                $this->dispatch('scanFailed', 'Produk/Serial tidak ditemukan atau stok kosong di lokasi asal.');
                $this->dispatch('select-scan-input');
            }
            if ($operationToken !== null && $operationToken !== '') {
                $this->dispatch('transfer:scan-processed', ['token' => $operationToken]);
            }
            return;
        }

        if ($result['type'] === 'product_exact') {
            $productData = $result['product'];
            $conversionId = null;
            $scanMultiplier = 1;

            if (($productData['resolved_via'] ?? '') === 'conversion_barcode' && !empty($productData['conversion'])) {
                $conversionId = (int) ($productData['conversion']['id'] ?? 0);
                $factor = (float) ($productData['conversion']['conversion_factor'] ?? 1);
                if ($factor <= 0 || abs($factor - round($factor)) > 1e-6) {
                    if ($operationToken !== null && $operationToken !== '') {
                        $this->dispatch('transfer:scan-processed', ['token' => $operationToken]);
                    }
                    $this->dispatch('scanFailed', 'Faktor konversi tidak valid.');
                    $this->dispatch('select-scan-input');
                    return;
                }
                $scanMultiplier = (int) round($factor);
            }

            $productPayload = [
                'id' => (int) $productData['id'],
                'conversion_id' => $conversionId,
                'scan_quantity_multiplier' => $scanMultiplier,
                'is_broken_mode' => $this->isBrokenMode(),
            ];
            if ($operationToken !== null && $operationToken !== '') {
                $productPayload['operation_token'] = $operationToken;
            }

            $this->dispatch('productSelected', $productPayload);

            $this->resetQuery();
            $this->dispatch('restore-scanner-focus');
        } elseif ($result['type'] === 'serial_exact') {
            $serialData = $result['serial'];

            // Check if serial is at the selected origin location
            if ($serialData['location_id'] != $this->locationId) {
                if ($operationToken !== null && $operationToken !== '') {
                    $this->dispatch('transfer:scan-processed', ['token' => $operationToken]);
                }
                $this->dispatch('scanFailed', 'Nomor Seri tidak berada di lokasi asal terpilih.');
                $this->dispatch('select-scan-input');
                return;
            }

            $isSerialBroken = (bool) ($serialData['is_broken'] ?? false);
            if ($isSerialBroken !== $this->isBrokenMode()) {
                if ($operationToken !== null && $operationToken !== '') {
                    $this->dispatch('transfer:scan-processed', ['token' => $operationToken]);
                }
                $modeStr = $this->isBrokenMode() ? 'Rusak' : 'Normal';
                $this->dispatch('scanFailed', "Nomor Seri status rusak tidak cocok dengan mode $modeStr.");
                $this->dispatch('select-scan-input');
                return;
            }

            // Minimal intent payload for product selection
            $this->dispatch('productSelected', [
                'id' => (int) $serialData['product_id'],
                'is_broken_mode' => $this->isBrokenMode(),
            ]);

            // Minimal intent payload for serial scan
            $serialPayload = [
                'id' => (int) $serialData['id'],
                'product_id' => (int) $serialData['product_id'],
                'serial_number' => (string) $serialData['serial_number'],
            ];
            if ($operationToken !== null && $operationToken !== '') {
                $serialPayload['operation_token'] = $operationToken;
            }

            $this->dispatch('serialScanned', $serialPayload);

            $this->resetQuery();
            $this->dispatch('restore-scanner-focus');
        }
    }
}
