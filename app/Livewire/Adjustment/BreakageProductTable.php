<?php

namespace App\Livewire\Adjustment;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Adjustment\Services\AdjustmentProductResolver;
use Modules\Adjustment\Services\BreakageSerialPolicy;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;

/**
 * Breakage-specific searchable/scannable entry editor (design.md "Use a
 * breakage-specific editor backed by shared entry services"). Reuses
 * AdjustmentProductResolver for scan resolution/product search/normalization,
 * but keeps its own single-quantity, single-location-good-to-broken state
 * rather than Stock Opname's good/bad absolute-count model.
 */
class BreakageProductTable extends Component
{
    protected $listeners = [
        'productSelected',
        'serialNumberSelected',
        'locationSelected',
        'locationDropdownSelected',
    ];

    public array $products = [];
    public bool $hasAdjustments = false;

    /**
     * Locked against direct client mutation: Livewire's wire protocol lets a
     * crafted request $set() any public property regardless of which method
     * it is bound to in the Blade template, so without #[Locked] a request
     * could set locationId straight to a foreign-setting location and skip
     * handleLocationChange()'s ownership check entirely. Every location
     * transition must go through locationSelected()/locationDropdownSelected()
     * or confirmLocationChange(), which revalidate via resolveOwnedLocation().
     */
    #[Locked]
    public ?int $locationId = null;
    public ?bool $isPkp = null;
    public array $quantities = [];
    public array $serialNumberErrors = [];

    public string $scanInput = '';
    public ?string $feedbackMessage = null;
    public string $feedbackType = 'info';

    public bool $showAmbiguityModal = false;
    public array $ambiguousCandidates = [];

    public bool $showSearchModal = false;
    public string $searchTerm = '';
    public array $searchResults = [];

    public bool $showLocationConfirmModal = false;

    /**
     * Locked for the same reason as locationId above: confirmLocationChange()
     * trusts this value to already be an owned location because
     * handleLocationChange() is the only path that sets it -- a mutable
     * pendingLocationId would let a crafted request replace it with a
     * foreign-setting location after the confirmation dialog is shown and
     * before confirm is called. resolveOwnedLocation() is still re-checked
     * in applyLocationChange() as defense in depth.
     */
    #[Locked]
    public ?int $pendingLocationId = null;

    public bool $showSerialModal = false;
    public ?int $selectedRowIndexForSerials = null;
    public string $rowSerialInput = '';
    public ?string $rowSerialError = null;

    public function mount(
        $adjustedProducts = null,
        $locationId = null,
        $serial_numbers = null,
        $product_ids = null,
        $quantities_tax = null,
        $quantities_non_tax = null
    ): void {
        $this->locationId = $locationId ? (int) $locationId : null;
        $this->products = [];
        $this->quantities = [];
        $this->refreshIsPkp();

        if ($adjustedProducts) {
            $this->hydrateFromExistingAdjustments($adjustedProducts);
            return;
        }

        if (!empty($product_ids)) {
            $this->hydrateFromOldInput(
                $product_ids,
                $serial_numbers,
                $quantities_tax,
                $quantities_non_tax
            );
        }
    }

    public function render(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        return view('livewire.adjustment.breakage-product-table');
    }

    protected function refreshIsPkp(): void
    {
        if (!$this->locationId) {
            $this->isPkp = null;
            return;
        }

        $location = $this->resolveOwnedLocation($this->locationId);
        $this->isPkp = $location && $location->setting ? (bool) $location->setting->is_pkp : null;
    }

    /**
     * Every Livewire action that accepts a location ID from the client must
     * revalidate it server-side against the active session setting and
     * exclude consignment locations -- the LocationSearchDropdown's rendered
     * options are a UI convenience, not a security boundary, so a crafted
     * request could otherwise read another setting's location/stock/PKP data.
     */
    protected function resolveOwnedLocation(?int $locationId): ?Location
    {
        if (!$locationId) {
            return null;
        }

        $activeSettingId = (int) session('setting_id');

        return Location::with('setting')
            ->where('id', $locationId)
            ->where('setting_id', $activeSettingId)
            ->where('is_consignment', false)
            ->first();
    }

    /**
     * Location dropdown event bridge (LocationSearchDropdown dispatches this).
     */
    public function locationDropdownSelected($name, $value): void
    {
        if ($name === 'location_id') {
            $this->handleLocationChange($value);
        }
    }

    public function locationSelected($locationId): void
    {
        $this->handleLocationChange($locationId);
    }

    protected function handleLocationChange($newLocationId): void
    {
        $newLocationId = !empty($newLocationId) ? (int) $newLocationId : null;

        if ($newLocationId !== null && !$this->resolveOwnedLocation($newLocationId)) {
            $this->feedbackMessage = 'Lokasi tidak ditemukan atau bukan milik pengaturan aktif.';
            $this->feedbackType = 'danger';
            $this->dispatch('setSelectedLocation', locationId: $this->locationId);
            return;
        }

        if ($this->locationId === $newLocationId) {
            return;
        }

        if (count($this->products) > 0) {
            $this->pendingLocationId = $newLocationId;
            $this->showLocationConfirmModal = true;
            return;
        }

        $this->applyLocationChange($newLocationId);
    }

    public function confirmLocationChange(): void
    {
        $this->applyLocationChange($this->pendingLocationId);
        $this->showLocationConfirmModal = false;
        $this->pendingLocationId = null;
    }

    public function cancelLocationChange(): void
    {
        $this->showLocationConfirmModal = false;
        $this->pendingLocationId = null;
        $this->dispatch('setSelectedLocation', locationId: $this->locationId);
    }

    /**
     * Single funnel that ever assigns $this->locationId (the only place that
     * writes to the #[Locked] property from PHP). Revalidates ownership again
     * here as defense in depth, since callers may pass a value derived from
     * pendingLocationId or other internal state rather than a value that has
     * just been through resolveOwnedLocation() itself.
     */
    protected function applyLocationChange($newLocationId): void
    {
        $newLocationId = !empty($newLocationId) ? (int) $newLocationId : null;

        if ($newLocationId !== null && !$this->resolveOwnedLocation($newLocationId)) {
            $newLocationId = null;
            $this->feedbackMessage = 'Lokasi tidak ditemukan atau bukan milik pengaturan aktif.';
            $this->feedbackType = 'danger';
        } else {
            $this->feedbackMessage = 'Lokasi telah diubah. Daftar barang rusak telah diatur ulang.';
            $this->feedbackType = 'info';
        }

        $this->locationId = $newLocationId;
        $this->products = [];
        $this->quantities = [];
        $this->serialNumberErrors = [];
        $this->hasAdjustments = false;
        $this->refreshIsPkp();
    }

    /**
     * Main scan input handler.
     */
    public function processScan(): void
    {
        $code = trim($this->scanInput);
        $this->feedbackMessage = null;

        if ($code === '') {
            return;
        }

        if (!$this->locationId) {
            $this->feedbackMessage = 'Pilih lokasi terlebih dahulu sebelum memindai.';
            $this->feedbackType = 'warning';
            $this->dispatch('select-scan-input');
            return;
        }

        $resolver = app(AdjustmentProductResolver::class);
        $result = $this->scopeScanResultToActiveSetting($resolver->resolveScan($code));

        if ($result['status'] === 'not_found') {
            $this->feedbackMessage = "Barcode atau nomor seri '{$code}' tidak ditemukan.";
            $this->feedbackType = 'danger';
            $this->dispatch('select-scan-input');
            return;
        }

        if ($result['status'] === 'ambiguous') {
            $this->ambiguousCandidates = $result['candidates'] ?? [];
            $this->showAmbiguityModal = true;
            return;
        }

        if ($result['status'] === 'resolved') {
            $applied = $this->applyResolvedCandidate($result['candidate']);
            if ($applied) {
                $this->scanInput = '';
                $this->dispatch('restore-scanner-focus');
            } else {
                $this->dispatch('select-scan-input');
            }
        }
    }

    /**
     * AdjustmentProductResolver::resolveScan() is shared with Stock Opname
     * and matches barcodes/serials globally by design; breakage must narrow
     * the result to products actually eligible under resolveBreakageProduct()
     * for the active session setting (active, stock-managed, no-setting or
     * matching setting_id) before ever exposing candidate data or applying a
     * match, so a crafted scan cannot read or select another setting's
     * product/stock.
     */
    protected function scopeScanResultToActiveSetting(array $result): array
    {
        if ($result['status'] === 'ambiguous') {
            $filtered = array_values(array_filter(
                $result['candidates'] ?? [],
                fn (array $candidate) => $this->isProductEligibleForActiveSetting((int) $candidate['product']['id'])
            ));

            if (empty($filtered)) {
                return ['status' => 'not_found'];
            }

            if (count($filtered) === 1) {
                return ['status' => 'resolved', 'match_type' => $filtered[0]['type'], 'candidate' => $filtered[0]];
            }

            return ['status' => 'ambiguous', 'candidates' => $filtered];
        }

        if ($result['status'] === 'resolved') {
            $productId = (int) ($result['candidate']['product']['id'] ?? 0);
            if (!$this->isProductEligibleForActiveSetting($productId)) {
                return ['status' => 'not_found'];
            }
        }

        return $result;
    }

    protected function isProductEligibleForActiveSetting(int $productId): bool
    {
        $activeSettingId = (int) session('setting_id');

        return Product::query()
            ->where('id', $productId)
            ->active()
            ->where('stock_managed', true)
            ->where(function ($q) use ($activeSettingId) {
                $q->whereNull('setting_id')->orWhere('setting_id', $activeSettingId);
            })
            ->exists();
    }

    public function selectAmbiguousCandidate(int $candidateIndex): void
    {
        if (isset($this->ambiguousCandidates[$candidateIndex])) {
            $candidate = $this->ambiguousCandidates[$candidateIndex];
            $this->showAmbiguityModal = false;
            $this->ambiguousCandidates = [];
            $applied = $this->applyResolvedCandidate($candidate);
            if ($applied) {
                $this->scanInput = '';
                $this->dispatch('restore-scanner-focus');
            } else {
                $this->dispatch('select-scan-input');
            }
        }
    }

    public function closeAmbiguityModal(): void
    {
        $this->showAmbiguityModal = false;
        $this->ambiguousCandidates = [];
        $this->dispatch('restore-scanner-focus');
    }

    /**
     * Apply a resolved scan candidate to the breakage table. Non-serialized
     * scans increment one base-unit breakage quantity (or the conversion
     * factor), capped by currently available good stock. Serialized scans
     * only focus/create the row; quantity is derived from selected serials.
     */
    protected function applyResolvedCandidate(array $candidate): bool
    {
        $type = $candidate['type'];
        $productData = $candidate['product'];
        $productId = (int) $productData['id'];

        $existingIndex = $this->findProductRowIndex($productId);

        if ($type === 'product') {
            if ($productData['serial_number_required']) {
                if ($existingIndex === null) {
                    $this->addProductRow($productData);
                    $this->feedbackMessage = "Produk berseri '{$productData['product_name']}' ditambahkan. Silakan pindai nomor seri barang bagus.";
                } else {
                    $this->feedbackMessage = "Produk berseri '{$productData['product_name']}' difokuskan (baris " . ($existingIndex + 1) . ").";
                }
                $this->feedbackType = 'info';
                return true;
            }

            if ($existingIndex === null) {
                $existingIndex = $this->addProductRow($productData);
            }

            return $this->incrementQuantity($existingIndex, 1);
        }

        if ($type === 'conversion') {
            $conversion = $candidate['conversion'];
            $factor = (float) $conversion['conversion_factor'];

            if (abs($factor - round($factor)) > 1e-6) {
                $this->feedbackMessage = "Faktor konversi {$factor} bukan bilangan bulat dan belum didukung untuk barang rusak.";
                $this->feedbackType = 'warning';
                return false;
            }

            $factorInt = (int) round($factor);

            if ($productData['serial_number_required']) {
                if ($existingIndex === null) {
                    $this->addProductRow($productData);
                    $this->feedbackMessage = "Produk berseri '{$productData['product_name']}' ditambahkan. Silakan pindai nomor seri barang bagus.";
                } else {
                    $this->feedbackMessage = "Produk berseri '{$productData['product_name']}' difokuskan.";
                }
                $this->feedbackType = 'info';
                return true;
            }

            if ($existingIndex === null) {
                $existingIndex = $this->addProductRow($productData);
            }

            return $this->incrementQuantity($existingIndex, $factorInt, $conversion['unit_name']);
        }

        if ($type === 'serial') {
            $serialData = $candidate['serial'];

            if ($existingIndex === null) {
                $existingIndex = $this->addProductRow($productData);
            }

            return $this->addSerialToRow($existingIndex, (int) $serialData['id']);
        }

        return false;
    }

    protected function incrementQuantity(int $index, int $amount, ?string $unitName = null): bool
    {
        $available = (int) ($this->products[$index]['available_good'] ?? 0);
        $current = (int) ($this->quantities[$index] ?? 0);

        if ($current + $amount > $available) {
            $this->feedbackMessage = "Stok baik tersedia untuk '{$this->products[$index]['product_name']}' (tersedia {$available}) tidak mencukupi.";
            $this->feedbackType = 'warning';
            return false;
        }

        $this->quantities[$index] = $current + $amount;
        $label = $unitName ? "+{$amount} satuan dasar ({$unitName})" : "+{$amount}";
        $this->feedbackMessage = "{$label} untuk '{$this->products[$index]['product_name']}'.";
        $this->feedbackType = 'success';
        return true;
    }

    protected function addSerialToRow(int $index, int $serialId): bool
    {
        if (!$this->locationId) {
            return false;
        }

        $productId = (int) $this->products[$index]['id'];
        $product = Product::find($productId);
        $location = $this->resolveOwnedLocation($this->locationId);
        $serial = ProductSerialNumber::find($serialId);

        if (!$product || !$location || !$location->setting || !$serial) {
            $this->feedbackMessage = 'Nomor seri tidak ditemukan.';
            $this->feedbackType = 'danger';
            return false;
        }

        $isPkp = (bool) $location->setting->is_pkp;
        $existingIds = collect($this->products[$index]['serial_numbers'] ?? [])->pluck('id')->all();

        if (in_array($serialId, $existingIds, true)) {
            $this->feedbackMessage = 'Nomor seri sudah ada pada produk ini.';
            $this->feedbackType = 'warning';
            return false;
        }

        $policy = app(BreakageSerialPolicy::class);

        if (!$policy->isEligibleForScan($serial, $product, $location, $isPkp)) {
            $this->feedbackMessage = "Nomor seri '{$serial->serial_number}' tidak memenuhi syarat untuk barang rusak (tidak tersedia, bukan produk/lokasi ini, atau klasifikasi pajak tidak sesuai).";
            $this->feedbackType = 'danger';
            return false;
        }

        $this->products[$index]['serial_numbers'][] = [
            'id' => (int) $serial->id,
            'serial_number' => $serial->serial_number,
        ];

        $this->quantities[$index] = count($this->products[$index]['serial_numbers']);
        unset($this->serialNumberErrors[$index]);

        $this->feedbackMessage = "Nomor seri '{$serial->serial_number}' ditambahkan untuk '{$this->products[$index]['product_name']}'.";
        $this->feedbackType = 'success';
        return true;
    }

    /**
     * Add a product row initialized at zero quantity, with current available
     * good stock captured for entry-time limiting (approval always revalidates).
     */
    protected function addProductRow(array $productData): int
    {
        $productId = (int) $productData['id'];
        $isSerialized = (bool) ($productData['serial_number_required'] ?? false);

        [$availableGood, $goodTax, $goodNonTax, $badTax, $badNonTax] = $this->currentStockBuckets($productId);

        $row = [
            'id' => $productId,
            'product_name' => $productData['product_name'],
            'product_code' => $productData['product_code'],
            'serial_number_required' => $isSerialized,
            'serial_numbers' => [],
            'unit' => $productData['base_unit'] ?? '',
            'available_good' => $availableGood,
            'quantity_tax' => $goodTax,
            'quantity_non_tax' => $goodNonTax,
            'broken_quantity_tax' => $badTax,
            'broken_quantity_non_tax' => $badNonTax,
        ];

        $newIndex = count($this->products);
        $this->products[] = $row;
        $this->quantities[$newIndex] = 0;
        $this->serialNumberErrors[$newIndex] = null;
        $this->hasAdjustments = true;

        return $newIndex;
    }

    /**
     * Defense in depth: locationId is #[Locked] and every write path already
     * revalidates ownership, but this is the point that actually discloses
     * stock figures -- it must never trust locationId without re-checking it
     * belongs to the active setting, in case a future write path is added
     * that forgets to.
     */
    protected function currentStockBuckets(int $productId): array
    {
        if (!$this->resolveOwnedLocation($this->locationId)) {
            return [0, 0, 0, 0, 0];
        }

        $stock = ProductStock::where('product_id', $productId)
            ->where('location_id', $this->locationId)
            ->first();

        $goodTax = (int) ($stock->quantity_tax ?? 0);
        $goodNonTax = (int) ($stock->quantity_non_tax ?? 0);
        $badTax = (int) ($stock->broken_quantity_tax ?? 0);
        $badNonTax = (int) ($stock->broken_quantity_non_tax ?? 0);

        $availableGood = $this->isPkp ? $goodTax : $goodNonTax;

        return [$availableGood, $goodTax, $goodNonTax, $badTax, $badNonTax];
    }

    protected function findProductRowIndex(int $productId): ?int
    {
        foreach ($this->products as $index => $row) {
            if ((int) ($row['id'] ?? 0) === $productId) {
                return $index;
            }
        }
        return null;
    }

    public function removeProduct($index): void
    {
        unset($this->products[$index], $this->quantities[$index], $this->serialNumberErrors[$index]);

        $this->products = array_values($this->products);
        $this->quantities = array_values($this->quantities);
        $this->serialNumberErrors = array_values($this->serialNumberErrors);

        if (count($this->products) === 0) {
            $this->hasAdjustments = false;
        }
    }

    public function removeSerialNumber($index, $serialIndex): void
    {
        if (!isset($this->products[$index]['serial_numbers'][$serialIndex])) {
            return;
        }

        unset($this->products[$index]['serial_numbers'][$serialIndex]);
        $this->products[$index]['serial_numbers'] = array_values($this->products[$index]['serial_numbers']);
        $this->quantities[$index] = count($this->products[$index]['serial_numbers']);
    }

    /**
     * Product search modal.
     */
    public function openSearchModal(): void
    {
        $this->searchTerm = '';
        $this->searchResults = [];
        $this->showSearchModal = true;
    }

    public function closeSearchModal(): void
    {
        $this->showSearchModal = false;
        $this->searchResults = [];
        $this->dispatch('restore-scanner-focus');
    }

    public function searchProducts(): void
    {
        $term = trim($this->searchTerm);
        if ($term === '') {
            $this->searchResults = [];
            return;
        }

        $resolver = app(AdjustmentProductResolver::class);

        // AdjustmentProductResolver::searchProducts() is shared with Stock
        // Opname and searches globally by design; breakage must narrow
        // results to the active setting's eligible products before they are
        // ever returned to the client.
        $this->searchResults = collect($resolver->searchProducts($term, 30))
            ->filter(fn (array $result) => $this->isProductEligibleForActiveSetting((int) $result['id']))
            ->values()
            ->take(10)
            ->all();
    }

    public function updatedSearchTerm(): void
    {
        $this->searchProducts();
    }

    public function productSelected($product): void
    {
        if (!$this->locationId) {
            $this->feedbackMessage = 'Pilih lokasi terlebih dahulu sebelum menambahkan produk.';
            $this->feedbackType = 'warning';
            return;
        }

        $productId = (int) ($product['id'] ?? ($product['product']['id'] ?? 0));
        $existingIndex = $this->findProductRowIndex($productId);

        if ($existingIndex !== null) {
            $this->feedbackMessage = "Produk '{$this->products[$existingIndex]['product_name']}' sudah ada di daftar (baris " . ($existingIndex + 1) . ").";
            $this->feedbackType = 'info';
            $this->closeSearchModal();
            return;
        }

        if (!$this->isProductEligibleForActiveSetting($productId)) {
            $this->feedbackMessage = 'Produk tidak ditemukan, tidak aktif, bukan produk yang stoknya dikelola, atau bukan milik pengaturan aktif.';
            $this->feedbackType = 'danger';
            $this->closeSearchModal();
            return;
        }

        $productEntity = Product::with(['baseUnit', 'conversions.unit'])->find($productId);
        if ($productEntity) {
            $resolver = app(AdjustmentProductResolver::class);
            $normalized = $resolver->normalizeProductData($productEntity);
            $this->addProductRow($normalized);
            $this->feedbackMessage = "Produk '{$normalized['product_name']}' berhasil ditambahkan ke daftar.";
            $this->feedbackType = 'success';
        }

        $this->closeSearchModal();
    }

    /**
     * Row Serial Dialog: open/close/add-by-text for a serialized row.
     */
    public function openSerialModal(int $rowIndex): void
    {
        if (!isset($this->products[$rowIndex])) {
            return;
        }

        $this->selectedRowIndexForSerials = $rowIndex;
        $this->rowSerialInput = '';
        $this->rowSerialError = null;
        $this->showSerialModal = true;
    }

    public function closeSerialModal(): void
    {
        $this->showSerialModal = false;
        $this->selectedRowIndexForSerials = null;
        $this->rowSerialInput = '';
        $this->rowSerialError = null;
        $this->dispatch('restore-scanner-focus');
    }

    public function addRowSerial(): void
    {
        $index = $this->selectedRowIndexForSerials;
        if ($index === null || !isset($this->products[$index])) {
            return;
        }

        $rawText = trim($this->rowSerialInput);
        if ($rawText === '') {
            $this->rowSerialError = 'Nomor seri tidak boleh kosong.';
            return;
        }

        $normalizedText = ProductSerialNumber::normalize($rawText);
        $productId = (int) $this->products[$index]['id'];

        $serial = ProductSerialNumber::query()
            ->where('product_id', $productId)
            ->where('serial_number', $normalizedText)
            ->first();

        if (!$serial) {
            $this->rowSerialError = "Nomor seri '{$normalizedText}' tidak ditemukan untuk produk ini.";
            return;
        }

        $applied = $this->addSerialToRow($index, (int) $serial->id);

        if ($applied) {
            $this->rowSerialInput = '';
            $this->rowSerialError = null;
        } else {
            $this->rowSerialError = $this->feedbackMessage;
        }
    }

    public function removeRowSerial(int $serialIndex): void
    {
        $rowIndex = $this->selectedRowIndexForSerials;
        if ($rowIndex === null || !isset($this->products[$rowIndex]['serial_numbers'][$serialIndex])) {
            return;
        }

        $this->removeSerialNumber($rowIndex, $serialIndex);
    }

    protected function hydrateFromExistingAdjustments($adjustedProducts): void
    {
        $this->hasAdjustments = true;

        foreach ($adjustedProducts as $adjustedProduct) {
            $productId = $adjustedProduct['product']['id'] ?? null;
            if (!$productId) {
                continue;
            }

            $product = Product::with('baseUnit')->find($productId);
            if (!$product) {
                continue;
            }

            [$availableGood, $goodTax, $goodNonTax, $badTax, $badNonTax] = $this->currentStockBuckets($productId);

            $isSerialized = (bool) $product->serial_number_required;
            $serialIds = $adjustedProduct['serial_number_ids'] ?? [];
            $serials = $this->getSerialNumberByIds($serialIds);

            $quantity = $isSerialized
                ? count($serials)
                : ((int) ($adjustedProduct['quantity_tax'] ?? 0) + (int) ($adjustedProduct['quantity_non_tax'] ?? 0));

            $baseUnit = optional($product->baseUnit);

            $index = count($this->products);
            $this->products[] = [
                'id' => $product->id,
                'product_name' => $product->product_name,
                'product_code' => $product->product_code,
                'serial_number_required' => $isSerialized,
                'serial_numbers' => $serials,
                'unit' => $baseUnit->unit_name ?? $baseUnit->name ?? $baseUnit->short_name ?? '',
                'available_good' => $availableGood,
                'quantity_tax' => $goodTax,
                'quantity_non_tax' => $goodNonTax,
                'broken_quantity_tax' => $badTax,
                'broken_quantity_non_tax' => $badNonTax,
            ];

            $this->quantities[$index] = $quantity;
            $this->serialNumberErrors[$index] = null;
        }
    }

    protected function hydrateFromOldInput($productIds, $serialNumbers, $quantitiesTax, $quantitiesNonTax): void
    {
        foreach ($productIds as $key => $productId) {
            $product = Product::with('baseUnit')->find($productId);
            if (!$product) {
                continue;
            }

            [$availableGood, $goodTax, $goodNonTax, $badTax, $badNonTax] = $this->currentStockBuckets($productId);

            $isSerialized = (bool) $product->serial_number_required;
            $serialIds = $serialNumbers[$key] ?? [];
            $serials = $this->getSerialNumberByIds($serialIds);

            $quantity = $isSerialized
                ? count($serials)
                : ((int) ($quantitiesTax[$key] ?? 0) + (int) ($quantitiesNonTax[$key] ?? 0));

            $baseUnit = optional($product->baseUnit);

            $index = count($this->products);
            $this->products[] = [
                'id' => $product->id,
                'product_name' => $product->product_name,
                'product_code' => $product->product_code,
                'serial_number_required' => $isSerialized,
                'serial_numbers' => $serials,
                'unit' => $baseUnit->unit_name ?? $baseUnit->name ?? $baseUnit->short_name ?? '',
                'available_good' => $availableGood,
                'quantity_tax' => $goodTax,
                'quantity_non_tax' => $goodNonTax,
                'broken_quantity_tax' => $badTax,
                'broken_quantity_non_tax' => $badNonTax,
            ];

            $this->quantities[$index] = $quantity;
            $this->serialNumberErrors[$index] = null;
        }

        $this->hasAdjustments = count($this->products) > 0;
    }

    protected function getSerialNumberByIds($serialNumberIds): array
    {
        if (empty($serialNumberIds)) {
            return [];
        }

        return ProductSerialNumber::whereIn('id', $serialNumberIds)
            ->get(['id', 'serial_number'])
            ->map(fn ($serial) => [
                'id' => $serial->id,
                'serial_number' => $serial->serial_number,
            ])
            ->toArray();
    }

    /**
     * Compute the wire-format quantity arrays for form submission: the
     * displayed base-unit quantity is allocated exclusively to the bucket
     * dictated by the selected location's PKP setting (design.md "Use one
     * displayed breakage quantity and derive its storage bucket").
     */
    public function getWireQuantitiesTaxProperty(): array
    {
        return collect($this->quantities)->map(fn ($qty) => $this->isPkp ? (int) $qty : 0)->toArray();
    }

    public function getWireQuantitiesNonTaxProperty(): array
    {
        return collect($this->quantities)->map(fn ($qty) => $this->isPkp ? 0 : (int) $qty)->toArray();
    }
}
