<?php

namespace App\Livewire\Transfer;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Services\TransferAllocationPreviewService;
use Modules\Adjustment\Services\TransferScanResolverService;
use Modules\Adjustment\Services\TransferStockVisibility;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\ProductUnitConversion;
use Modules\Sale\Support\PendingDispatchSerialGuard;
use Modules\Setting\Entities\Location;

class TransferProductTable extends Component
{
    protected $listeners = [
        'productSelected',
        'serialNumberSelected',
        'serialScanned',
        'removeSerialNumber',
        'locationsConfirmed'         => 'syncLocationIds',
        'transfer:rows-reset'        => 'resetRows',
        'transfer:condition-changed' => 'syncStockCondition',
        'tableValidationErrors'      => 'onTableValidationErrors',
        'collectTableErrors'         => 'onCollectTableErrors',
    ];

    public $products = [];

    #[Locked]
    public $originLocationId;

    #[Locked]
    public $destinationLocationId;

    #[Locked]
    public ?string $stockCondition = null;

    public $serialNumberErrors = [];

    // holds validation errors for table rows
    public $tableValidationErrors = [];

    // Entry Coordinator Interaction State
    public string $scanInput = '';
    public ?string $feedbackMessage = null;
    public string $feedbackType = 'info'; // 'success', 'warning', 'danger', 'info'

    // Ambiguity resolution state
    public bool $showAmbiguityModal = false;
    #[Locked]
    public array $ambiguousCandidates = [];

    // Product search modal state
    public bool $showSearchModal = false;
    public string $searchTerm = '';
    public array $searchResults = [];

    // Row serial dialog state
    public bool $showSerialModal = false;
    public ?int $selectedRowIndexForSerials = null;
    public string $rowSerialInput = '';
    public ?string $rowSerialError = null;
    public string $serialSearchTerm = '';
    public array $serialSearchResults = [];

    /**
     * Server-side-only, request-scoped record of the current computed
     * allocation breakdown for a blind row, keyed by row index. Never a
     * public property: Livewire only serializes public properties into the
     * browser-visible wire payload, so this is how the breakdown can be
     * computed on this request without ever reaching client state. It does
     * not persist across requests -- the accumulated per-mode total used to
     * gate repeated scans instead comes from the public `requested_quantity`
     * field, which does persist.
     *
     * @var array<int, array>
     */
    protected array $blindAllocationCache = [];

    /**
     * Server-side-only cache of a selected serial's tax/broken provenance
     * for a blind user, keyed by serial id. Recalculated (re-populated) on
     * each request from the currently selected serial ids so it survives
     * Livewire's per-request component reconstruction without ever being a
     * public property.
     *
     * @var array<int, array{taxable: bool, is_broken: bool}>
     */
    protected array $blindSerialProvenance = [];

    /**
     * Mount with the two location IDs passed in via wire:key
     */
    public function mount($originLocationId = null, $destinationLocationId = null, $existingProducts = [], $stockCondition = null): void
    {
        $this->originLocationId      = $originLocationId;
        $this->destinationLocationId = $destinationLocationId;
        $this->products              = $existingProducts;
        $this->stockCondition        = $stockCondition;
        $this->serialNumberErrors    = array_fill(0, count($existingProducts), null);
    }

    /**
     * The parent form bakes both originLocation and stockCondition into this
     * component's wire:key (see transfer-stock-form.blade.php), so a
     * legitimate change to either one always destroys this component
     * instance and mounts a fresh one with the new value already
     * authoritative via mount() -- it never reaches a live instance through
     * this listener.
     */
    public function syncStockCondition(string $condition): void
    {
        // Intentionally a no-op. Kept as a registered listener so the event
        // dispatch from the parent's condition-change flow (which always
        // remounts this component in the same request, before this listener
        // would run against the new instance) does not error as unhandled.
    }

    /**
     * Only destinationLocationId is synchronized live: it is deliberately
     * excluded from this component's wire:key because destination never
     * determines origin stock eligibility.
     */
    public function syncLocationIds(array $payload): void
    {
        $this->destinationLocationId = $payload['destinationLocationId'] ?? null;
    }

    /**
     * Clear rows/errors when the parent signals an origin or mode change.
     */
    public function resetRows(): void
    {
        $this->products              = [];
        $this->serialNumberErrors    = [];
        $this->tableValidationErrors = [];
        $this->showAmbiguityModal    = false;
        $this->ambiguousCandidates   = [];
        $this->showSearchModal       = false;
        $this->searchResults         = [];
        $this->showSerialModal       = false;
        $this->selectedRowIndexForSerials = null;

        // notify parent of reset
        $this->dispatch('rowsUpdated', $this->products);
    }

    /**
     * Whether the acting user may drive this table's stock-querying methods
     * at all (surface authorization) for the location currently set as
     * originLocationId (authoritative tenant scope).
     */
    private function isAuthorizedForOrigin(): bool
    {
        if (Gate::denies('stockTransfers.create') && Gate::denies('stockTransfers.edit')) {
            return false;
        }

        if (! $this->originLocationId) {
            return false;
        }

        $tenantSettingId = (int) session('setting_id');

        return Location::where('id', $this->originLocationId)
            ->where('setting_id', $tenantSettingId)
            ->exists();
    }

    // =========================================================================
    // Scanning & Ambiguity Handling
    // =========================================================================

    /**
     * Exact scanner handler. Called on Enter or via the FIFO controller.
     */
    public function processScan(?string $code = null, ?string $token = null): void
    {
        $code = trim($code ?? $this->scanInput);
        $this->feedbackMessage = null;

        if ($code === '') {
            if ($token !== null && $token !== '') {
                $this->dispatch('transfer:scan-processed', ['token' => $token]);
            }
            return;
        }

        if (! $this->isAuthorizedForOrigin()) {
            if ($token !== null && $token !== '') {
                $this->dispatch('transfer:scan-processed', ['token' => $token]);
            }
            $this->feedbackMessage = 'Silakan pilih Lokasi Asal terlebih dahulu.';
            $this->feedbackType = 'warning';
            $this->dispatch('select-scan-input');
            return;
        }

        if ($token !== null && $token !== '' && ! $this->claimOperation($token)) {
            $this->dispatch('transfer:scan-processed', ['token' => $token]);
            return;
        }

        $tenantSettingId = (int) session('setting_id');
        $isBrokenMode = ($this->stockCondition === Transfer::CONDITION_BREAKAGE);
        $canViewSystemStock = TransferStockVisibility::canView();

        $resolver = app(TransferScanResolverService::class);
        $result = $resolver->resolve($tenantSettingId, $code, (int) $this->originLocationId, $isBrokenMode);

        if ($result['status'] === 'not_found') {
            if ($token !== null && $token !== '') {
                $this->dispatch('transfer:scan-processed', ['token' => $token]);
            }
            $this->feedbackMessage = $canViewSystemStock
                ? "Barcode atau nomor seri '{$code}' tidak ditemukan atau stok kosong di lokasi asal."
                : "Barcode atau nomor seri '{$code}' tidak ditemukan.";
            $this->feedbackType = 'danger';
            $this->dispatch('select-scan-input');
            return;
        }

        if ($result['status'] === 'rejected') {
            if ($token !== null && $token !== '') {
                $this->dispatch('transfer:scan-processed', ['token' => $token]);
            }
            $this->feedbackMessage = $result['message'] ?? 'Nomor seri tidak dapat digunakan.';
            $this->feedbackType = 'danger';
            $this->dispatch('select-scan-input');
            return;
        }

        if ($result['status'] === 'ambiguous') {
            // Project candidates safely for blind operators
            $this->ambiguousCandidates = collect($result['candidates'] ?? [])->map(function ($candidate) use ($canViewSystemStock) {
                if ($canViewSystemStock) {
                    return $candidate;
                }
                // Mask detailed breakdown/provenance from descriptions and payloads
                $type = $candidate['type'] ?? 'product';
                $prod = $candidate['product'] ?? [];
                $desc = match($type) {
                    'product' => "Barcode Produk: {$prod['product_name']} ({$prod['product_code']})",
                    'conversion' => "Barcode Konversi: {$prod['product_name']}",
                    'serial' => "Nomor Seri: {$candidate['serial']['serial_number']} - {$prod['product_name']}",
                    default => $candidate['description'] ?? 'Item',
                };

                return [
                    'type' => $type,
                    'description' => $desc,
                    'product' => [
                        'id' => $prod['id'],
                        'product_name' => $prod['product_name'],
                        'product_code' => $prod['product_code'],
                        'barcode' => $prod['barcode'] ?? null,
                        'serial_number_required' => (bool) ($prod['serial_number_required'] ?? false),
                    ],
                    'conversion' => isset($candidate['conversion']) ? [
                        'id' => $candidate['conversion']['id'],
                        'unit_name' => $candidate['conversion']['unit_name'] ?? 'Unit',
                    ] : null,
                    'serial' => isset($candidate['serial']) ? [
                        'id' => $candidate['serial']['id'],
                        'serial_number' => $candidate['serial']['serial_number'],
                        'product_id' => $candidate['serial']['product_id'],
                    ] : null,
                ];
            })->toArray();

            $this->showAmbiguityModal = true;
            $this->dispatch('transfer-ambiguity-opened');
            return;
        }

        if ($result['status'] === 'resolved') {
            $applied = $this->applyResolvedCandidate($result['candidate'], $token);
            if ($token !== null && $token !== '') {
                $this->dispatch('transfer:scan-processed', ['token' => $token]);
            }

            if ($applied) {
                $this->scanInput = '';
                $this->dispatch('restore-scanner-focus');
            } else {
                $this->dispatch('select-scan-input');
            }
        }
    }

    /**
     * Select a candidate from the ambiguity dialog.
     */
    public function selectAmbiguousCandidate(int $candidateIndex, ?string $token = null): void
    {
        if (isset($this->ambiguousCandidates[$candidateIndex])) {
            $candidate = $this->ambiguousCandidates[$candidateIndex];
            $this->showAmbiguityModal = false;
            $this->ambiguousCandidates = [];
            $this->dispatch('transfer-ambiguity-closed');

            $applied = $this->applyResolvedCandidate($candidate, $token);
            if ($token !== null && $token !== '') {
                $this->dispatch('transfer:scan-processed', ['token' => $token]);
            }

            if ($applied) {
                $this->scanInput = '';
                $this->dispatch('restore-scanner-focus');
            } else {
                $this->dispatch('select-scan-input');
            }
        }
    }

    /**
     * Close the ambiguity dialog without applying an outcome.
     */
    public function closeAmbiguityModal(?string $token = null): void
    {
        $this->showAmbiguityModal = false;
        $this->ambiguousCandidates = [];
        $this->dispatch('transfer-ambiguity-closed');
        $this->dispatch('restore-scanner-focus');

        if ($token !== null && $token !== '') {
            $this->dispatch('transfer:scan-processed', ['token' => $token]);
        }
    }

    /**
     * Apply a resolved match candidate authoritatively.
     */
    protected function applyResolvedCandidate(array $candidate, ?string $token = null): bool
    {
        $type = $candidate['type'] ?? 'product';
        $productData = $candidate['product'] ?? [];
        $productId = (int) ($productData['id'] ?? 0);

        if ($productId <= 0 || ! $this->isAuthorizedForOrigin()) {
            return false;
        }

        $productModel = Product::query()
            ->whereKey($productId)
            ->where('stock_managed', true)
            ->active()
            ->first();

        if (! $productModel) {
            $this->feedbackMessage = 'Produk tidak ditemukan atau tidak aktif.';
            $this->feedbackType = 'danger';
            return false;
        }

        if (
            $this->stockCondition !== Transfer::CONDITION_GOOD &&
            $this->stockCondition !== Transfer::CONDITION_BREAKAGE
        ) {
            $this->feedbackMessage = 'Pilih kondisi transfer terlebih dahulu.';
            $this->feedbackType = 'warning';
            return false;
        }

        $isBrokenMode = ($this->stockCondition === Transfer::CONDITION_BREAKAGE);
        $isSerialized = (bool) $productModel->serial_number_required;
        $canViewSystemStock = TransferStockVisibility::canView();

        // Stock record at origin
        $stock = ProductStock::where('product_id', $productModel->id)
            ->where('location_id', $this->originLocationId)
            ->first();

        if (! $stock) {
            $this->flashInsufficientStock($canViewSystemStock, $isBrokenMode);
            $this->feedbackType = 'danger';
            return false;
        }

        // Available condition stock
        $availableStock = $isBrokenMode
            ? (int) ($stock->broken_quantity_tax ?? 0) + (int) ($stock->broken_quantity_non_tax ?? 0)
            : (int) ($stock->quantity_tax ?? 0) + (int) ($stock->quantity_non_tax ?? 0);

        if (!$isBrokenMode && $availableStock === 0 && !is_null($stock->quantity)) {
            $brokenQty = (int) ($stock->broken_quantity_tax ?? 0) + (int) ($stock->broken_quantity_non_tax ?? 0);
            $availableStock = max(0, (int) $stock->quantity - $brokenQty);
        }

        $existingKey = collect($this->products)->search(fn ($p) =>
            ($p['id'] ?? null) == $productModel->id &&
            ((bool)($p['is_broken_mode'] ?? false)) === $isBrokenMode
        );

        if ($type === 'product') {
            if ($isSerialized) {
                if ($existingKey === false) {
                    $this->addProductRow($productModel, 0, $stock, $isBrokenMode, $canViewSystemStock);
                    $this->feedbackMessage = "Produk berseri '{$productModel->product_name}' ditambahkan. Silakan masukkan nomor seri.";
                    $this->feedbackType = 'info';
                } else {
                    $this->feedbackMessage = "Produk berseri '{$productModel->product_name}' difokuskan (baris " . ($existingKey + 1) . ").";
                    $this->feedbackType = 'info';
                }
                $this->dispatch('rowsUpdated', $this->products);
                return true;
            }

            // Non-serialized barcode (+1 base unit)
            $currentRequested = ($existingKey !== false) ? (int) ($this->products[$existingKey]['requested_quantity'] ?? 0) : 0;
            if ($availableStock - $currentRequested < 1) {
                $this->flashInsufficientStock($canViewSystemStock, $isBrokenMode);
                $this->feedbackType = 'danger';
                return false;
            }

            if ($existingKey !== false) {
                $this->products[$existingKey]['requested_quantity'] = $currentRequested + 1;
                $this->recalculateAllocation($existingKey);
            } else {
                $this->addProductRow($productModel, 1, $stock, $isBrokenMode, $canViewSystemStock);
            }

            $this->feedbackMessage = "+1 untuk '{$productModel->product_name}'.";
            $this->feedbackType = 'success';
            $this->dispatch('rowsUpdated', $this->products);
            return true;
        }

        if ($type === 'conversion') {
            $conversionId = (int) ($candidate['conversion']['id'] ?? 0);
            $conversionRecord = ProductUnitConversion::query()
                ->whereKey($conversionId)
                ->where('product_id', $productModel->id)
                ->first();

            if (! $conversionRecord) {
                $this->feedbackMessage = 'Konversi satuan tidak ditemukan.';
                $this->feedbackType = 'danger';
                return false;
            }

            $factor = (float) $conversionRecord->conversion_factor;
            if ($factor <= 0 || abs($factor - round($factor)) > 1e-6) {
                $this->feedbackMessage = 'Faktor konversi tidak valid (harus bilangan bulat positif).';
                $this->feedbackType = 'warning';
                return false;
            }

            $factorInt = (int) round($factor);

            if ($isSerialized) {
                if ($existingKey === false) {
                    $this->addProductRow($productModel, 0, $stock, $isBrokenMode, $canViewSystemStock);
                    $this->feedbackMessage = "Produk berseri '{$productModel->product_name}' ditambahkan. Silakan masukkan nomor seri.";
                    $this->feedbackType = 'info';
                } else {
                    $this->feedbackMessage = "Produk berseri '{$productModel->product_name}' difokuskan.";
                    $this->feedbackType = 'info';
                }
                $this->dispatch('rowsUpdated', $this->products);
                return true;
            }

            $currentRequested = ($existingKey !== false) ? (int) ($this->products[$existingKey]['requested_quantity'] ?? 0) : 0;
            if ($availableStock - $currentRequested < $factorInt) {
                $this->flashInsufficientStock($canViewSystemStock, $isBrokenMode);
                $this->feedbackType = 'danger';
                return false;
            }

            if ($existingKey !== false) {
                $this->products[$existingKey]['requested_quantity'] = $currentRequested + $factorInt;
                $this->recalculateAllocation($existingKey);
            } else {
                $this->addProductRow($productModel, $factorInt, $stock, $isBrokenMode, $canViewSystemStock);
            }

            $unitName = $conversionRecord->unit ? $conversionRecord->unit->name : 'Unit';
            $this->feedbackMessage = "+{$factorInt} satuan dasar ({$unitName}) untuk '{$productModel->product_name}'.";
            $this->feedbackType = 'success';
            $this->dispatch('rowsUpdated', $this->products);
            return true;
        }

        if ($type === 'serial') {
            $serialId = (int) ($candidate['serial']['id'] ?? 0);
            $serial = ProductSerialNumber::query()
                ->whereKey($serialId)
                ->where('product_id', $productModel->id)
                ->where('location_id', $this->originLocationId)
                ->first();

            if (! $serial) {
                $this->feedbackMessage = $canViewSystemStock
                    ? 'Nomor seri tidak valid untuk produk atau lokasi asal terpilih.'
                    : 'Nomor seri tidak dapat digunakan.';
                $this->feedbackType = 'danger';
                return false;
            }

            if ($serial->dispatch_detail_id !== null || PendingDispatchSerialGuard::isReserved((string) $serial->serial_number)) {
                $this->feedbackMessage = $canViewSystemStock
                    ? 'Nomor seri sedang dalam proses pengiriman.'
                    : 'Nomor seri tidak dapat digunakan.';
                $this->feedbackType = 'danger';
                return false;
            }

            if ($serial->is_in_return_process) {
                $this->feedbackMessage = $canViewSystemStock
                    ? 'Nomor seri sedang dalam proses retur.'
                    : 'Nomor seri tidak dapat digunakan.';
                $this->feedbackType = 'danger';
                return false;
            }

            if ($isBrokenMode) {
                if (! $serial->isAvailableBroken()) {
                    $this->feedbackMessage = $canViewSystemStock
                        ? 'Nomor seri tidak berstatus rusak untuk transfer barang rusak.'
                        : 'Nomor seri tidak dapat digunakan untuk transfer ini.';
                    $this->feedbackType = 'danger';
                    return false;
                }
            } else {
                if (! $serial->isSellable()) {
                    $this->feedbackMessage = $canViewSystemStock
                        ? 'Nomor seri tidak aktif atau tidak siap jual.'
                        : 'Nomor seri tidak dapat digunakan untuk transfer ini.';
                    $this->feedbackType = 'danger';
                    return false;
                }
            }

            // Ensure product row exists
            if ($existingKey === false) {
                $this->addProductRow($productModel, 0, $stock, $isBrokenMode, $canViewSystemStock);
                $existingKey = array_key_last($this->products);
            }

            // Check if duplicate across rows
            if ($this->serialExistsInRows($serial->id, (int) $existingKey)) {
                $this->feedbackMessage = 'Nomor seri sudah dipilih.';
                $this->feedbackType = 'warning';
                return false;
            }

            $currentSerials = collect($this->products[$existingKey]['serial_numbers'] ?? []);
            if ($currentSerials->pluck('id')->contains($serial->id)) {
                $this->feedbackMessage = 'Nomor seri sudah dipilih.';
                $this->feedbackType = 'warning';
                return false;
            }

            $identity = [
                'id' => $serial->id,
                'serial_number' => $serial->serial_number,
            ];

            $this->products[$existingKey]['serial_numbers'][] = $canViewSystemStock
                ? array_merge($identity, [
                    'tax_id' => $serial->tax_id,
                    'taxable' => (bool) $serial->tax_id,
                    'is_broken' => (bool) $serial->is_broken,
                ])
                : $identity;

            $this->blindSerialProvenance[$serial->id] = [
                'taxable' => (bool) $serial->tax_id,
                'is_broken' => (bool) $serial->is_broken,
            ];

            $this->recalculateSerialQuantities($existingKey);
            $this->feedbackMessage = "Nomor seri '{$serial->serial_number}' ditambahkan untuk '{$productModel->product_name}'.";
            $this->feedbackType = 'success';
            $this->dispatch('rowsUpdated', $this->products);
            return true;
        }

        return false;
    }

    /**
     * Helper to initialize and append a product row.
     */
    protected function addProductRow(
        Product $productModel,
        int $initialRequestedQty,
        ProductStock $stock,
        bool $isBrokenMode,
        bool $canViewSystemStock
    ): int {
        $row = [
            'id'                      => $productModel->id,
            'product_name'            => $productModel->product_name,
            'product_code'            => $productModel->product_code,
            'barcode'                 => $productModel->barcode,
            'serial_number_required'  => (bool) $productModel->serial_number_required,
            'is_broken_mode'          => $isBrokenMode,
            'requested_quantity'      => $initialRequestedQty,
            'quantity_tax'            => 0,
            'quantity_non_tax'        => 0,
            'broken_quantity_tax'     => 0,
            'broken_quantity_non_tax' => 0,
            'serial_numbers'          => [],
        ];

        if ($canViewSystemStock) {
            $row['stock'] = [
                'total'                   => $stock->quantity                 ?? 0,
                'quantity_tax'            => $stock->quantity_tax             ?? 0,
                'quantity_non_tax'        => $stock->quantity_non_tax         ?? 0,
                'broken_quantity_tax'     => $stock->broken_quantity_tax      ?? 0,
                'broken_quantity_non_tax' => $stock->broken_quantity_non_tax  ?? 0,
            ];
        }

        $this->products[] = $row;
        $key = array_key_last($this->products);
        $this->serialNumberErrors[] = null;
        $this->tableValidationErrors = [];

        if (! $productModel->serial_number_required && $initialRequestedQty > 0) {
            $this->recalculateAllocation($key);
        }

        return $key;
    }

    // =========================================================================
    // Dedicated Product Search Modal
    // =========================================================================

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

    public function updatedSearchTerm(): void
    {
        $this->searchProducts();
    }

    public function searchProducts(): void
    {
        $term = trim($this->searchTerm);
        if ($term === '' || ! $this->isAuthorizedForOrigin()) {
            $this->searchResults = [];
            return;
        }

        $tokens = array_filter(explode(' ', $term), fn($t) => strlen($t) > 0);
        if (empty($tokens)) {
            $this->searchResults = [];
            return;
        }

        $isBrokenMode = ($this->stockCondition === Transfer::CONDITION_BREAKAGE);
        $canViewSystemStock = TransferStockVisibility::canView();

        $query = Product::query()
            ->active()
            ->where('stock_managed', true)
            ->with(['category', 'brand', 'baseUnit']);

        // Multi-token match: every token must match name, code, barcode, category, or brand
        foreach ($tokens as $token) {
            $tokenLower = '%' . strtolower($token) . '%';
            $query->where(function ($sub) use ($tokenLower) {
                $sub->whereRaw('LOWER(product_name) LIKE ?', [$tokenLower])
                    ->orWhereRaw('LOWER(product_code) LIKE ?', [$tokenLower])
                    ->orWhereRaw('LOWER(barcode) LIKE ?', [$tokenLower])
                    ->orWhereHas('category', fn($c) => $c->whereRaw('LOWER(category_name) LIKE ?', [$tokenLower]))
                    ->orWhereHas('brand', fn($b) => $b->whereRaw('LOWER(name) LIKE ?', [$tokenLower]));
            });
        }

        // Must have eligible stock at origin location
        $query->whereHas('productStocks', function ($stockQuery) use ($isBrokenMode) {
            $stockQuery->where('location_id', $this->originLocationId);
            if ($isBrokenMode) {
                $stockQuery->whereRaw('(COALESCE(broken_quantity_tax, 0) + COALESCE(broken_quantity_non_tax, 0)) > 0');
            } else {
                $stockQuery->where(function ($sub) {
                    $sub->whereRaw('(COALESCE(quantity_tax, 0) + COALESCE(quantity_non_tax, 0)) > 0')
                        ->orWhereRaw('(COALESCE(quantity, 0) - COALESCE(broken_quantity_tax, 0) - COALESCE(broken_quantity_non_tax, 0)) > 0');
                });
            }
        });

        $this->searchResults = $query->limit(10)->get()->map(function ($product) use ($canViewSystemStock) {
            $base = [
                'id' => $product->id,
                'product_name' => $product->product_name,
                'product_code' => $product->product_code,
                'barcode' => $product->barcode,
                'base_unit' => $product->baseUnit?->unit_name ?? $product->baseUnit?->name ?? 'Unit',
                'serial_number_required' => (bool) $product->serial_number_required,
                'category_name' => $product->category?->category_name,
                'brand_name' => $product->brand?->name,
            ];

            if ($canViewSystemStock) {
                $stock = ProductStock::where('product_id', $product->id)
                    ->where('location_id', $this->originLocationId)
                    ->first();
                $base['stock_quantity'] = (int) ($stock?->quantity ?? 0);
            }

            return $base;
        })->toArray();
    }

    public function selectSearchProduct(array $product): void
    {
        $productId = (int) ($product['id'] ?? 0);
        if ($productId <= 0 || ! $this->isAuthorizedForOrigin()) {
            $this->closeSearchModal();
            return;
        }

        $productModel = Product::query()
            ->whereKey($productId)
            ->where('stock_managed', true)
            ->active()
            ->first();

        if (! $productModel) {
            $this->feedbackMessage = 'Produk tidak valid.';
            $this->feedbackType = 'danger';
            $this->closeSearchModal();
            return;
        }

        $isBrokenMode = ($this->stockCondition === Transfer::CONDITION_BREAKAGE);
        $isSerialized = (bool) $productModel->serial_number_required;
        $canViewSystemStock = TransferStockVisibility::canView();

        $stock = ProductStock::where('product_id', $productModel->id)
            ->where('location_id', $this->originLocationId)
            ->first();

        if (! $stock) {
            $this->flashInsufficientStock($canViewSystemStock, $isBrokenMode);
            $this->feedbackType = 'danger';
            $this->closeSearchModal();
            return;
        }

        $existingKey = collect($this->products)->search(fn ($p) =>
            ($p['id'] ?? null) == $productModel->id &&
            ((bool)($p['is_broken_mode'] ?? false)) === $isBrokenMode
        );

        if ($isSerialized) {
            if ($existingKey === false) {
                $this->addProductRow($productModel, 0, $stock, $isBrokenMode, $canViewSystemStock);
                $this->feedbackMessage = "Produk berseri '{$productModel->product_name}' ditambahkan. Silakan masukkan nomor seri.";
                $this->feedbackType = 'info';
            } else {
                $this->feedbackMessage = "Produk berseri '{$productModel->product_name}' sudah ada di daftar.";
                $this->feedbackType = 'info';
            }
        } else {
            if ($existingKey === false) {
                // Initial 1 base unit
                $availableStock = $isBrokenMode
                    ? (int) ($stock->broken_quantity_tax ?? 0) + (int) ($stock->broken_quantity_non_tax ?? 0)
                    : (int) ($stock->quantity_tax ?? 0) + (int) ($stock->quantity_non_tax ?? 0);

                if (!$isBrokenMode && $availableStock === 0 && !is_null($stock->quantity)) {
                    $brokenQty = (int) ($stock->broken_quantity_tax ?? 0) + (int) ($stock->broken_quantity_non_tax ?? 0);
                    $availableStock = max(0, (int) $stock->quantity - $brokenQty);
                }

                if ($availableStock < 1) {
                    $this->flashInsufficientStock($canViewSystemStock, $isBrokenMode);
                    $this->feedbackType = 'danger';
                    $this->closeSearchModal();
                    return;
                }

                $this->addProductRow($productModel, 1, $stock, $isBrokenMode, $canViewSystemStock);
                $this->feedbackMessage = "Produk '{$productModel->product_name}' berhasil ditambahkan ke daftar.";
                $this->feedbackType = 'success';
            } else {
                // Focus existing row without incrementing
                $this->feedbackMessage = "Produk '{$productModel->product_name}' sudah ada di daftar (baris " . ($existingKey + 1) . ").";
                $this->feedbackType = 'info';
            }
        }

        $this->closeSearchModal();
        $this->dispatch('rowsUpdated', $this->products);
    }

    // =========================================================================
    // Row-Level Serial Management Modal
    // =========================================================================

    public function openSerialModal(int $rowIndex): void
    {
        if (! $this->isAuthorizedForOrigin()) {
            return;
        }

        if (!isset($this->products[$rowIndex])) {
            return;
        }

        $productId = (int) ($this->products[$rowIndex]['id'] ?? 0);
        $productModel = Product::query()
            ->whereKey($productId)
            ->where('stock_managed', true)
            ->where('serial_number_required', true)
            ->active()
            ->first();

        if (! $productModel) {
            return;
        }

        $this->selectedRowIndexForSerials = $rowIndex;
        $this->rowSerialInput = '';
        $this->rowSerialError = null;
        $this->serialSearchTerm = '';
        $this->serialSearchResults = [];
        $this->showSerialModal = true;
    }

    public function closeSerialModal(): void
    {
        $this->showSerialModal = false;
        $this->selectedRowIndexForSerials = null;
        $this->rowSerialInput = '';
        $this->rowSerialError = null;
        $this->serialSearchTerm = '';
        $this->serialSearchResults = [];
        $this->dispatch('restore-scanner-focus');
    }

    public function addRowSerial(?string $serialText = null): void
    {
        if (! $this->isAuthorizedForOrigin()) {
            return;
        }

        $index = $this->selectedRowIndexForSerials;
        if ($index === null || !isset($this->products[$index])) {
            return;
        }

        $rawText = trim($serialText ?? $this->rowSerialInput);
        if ($rawText === '') {
            $this->rowSerialError = 'Nomor seri tidak boleh kosong.';
            return;
        }

        if (
            $this->stockCondition !== Transfer::CONDITION_GOOD &&
            $this->stockCondition !== Transfer::CONDITION_BREAKAGE
        ) {
            return;
        }

        $productId = (int) ($this->products[$index]['id'] ?? 0);

        $productModel = Product::query()
            ->whereKey($productId)
            ->where('stock_managed', true)
            ->where('serial_number_required', true)
            ->active()
            ->first();

        if (! $productModel) {
            $this->rowSerialError = 'Produk tidak valid atau tidak aktif.';
            return;
        }

        $normalizedText = ProductSerialNumber::normalize($rawText);
        $isBrokenMode = ($this->stockCondition === Transfer::CONDITION_BREAKAGE);
        $canViewSystemStock = TransferStockVisibility::canView();

        // Check duplicate within current row
        $currentSerials = $this->products[$index]['serial_numbers'] ?? [];
        if (collect($currentSerials)->contains(fn($s) => ProductSerialNumber::normalize($s['serial_number'] ?? '') === $normalizedText)) {
            $this->rowSerialError = 'Nomor seri sudah dipilih.';
            return;
        }

        // Check if serial exists in database at origin location
        $serial = ProductSerialNumber::query()
            ->where('product_id', $productModel->id)
            ->where('location_id', $this->originLocationId)
            ->where('serial_number', $normalizedText)
            ->first();

        if (! $serial) {
            $this->rowSerialError = $canViewSystemStock
                ? 'Nomor seri tidak ditemukan pada lokasi asal untuk produk ini.'
                : 'Nomor seri tidak dapat digunakan.';
            return;
        }

        // Check duplicate across other rows
        if ($this->serialExistsInRows($serial->id, $index)) {
            $this->rowSerialError = 'Nomor seri sudah dipilih.';
            return;
        }

        if ($serial->dispatch_detail_id !== null || PendingDispatchSerialGuard::isReserved((string) $serial->serial_number)) {
            $this->rowSerialError = $canViewSystemStock
                ? 'Nomor seri sedang dalam proses pengiriman.'
                : 'Nomor seri tidak dapat digunakan.';
            return;
        }

        if ($serial->is_in_return_process) {
            $this->rowSerialError = $canViewSystemStock
                ? 'Nomor seri sedang dalam proses retur.'
                : 'Nomor seri tidak dapat digunakan.';
            return;
        }

        if ($isBrokenMode) {
            if (! $serial->isAvailableBroken()) {
                $this->rowSerialError = $canViewSystemStock
                    ? 'Nomor seri tidak berstatus rusak untuk transfer barang rusak.'
                    : 'Nomor seri tidak dapat digunakan untuk transfer ini.';
                return;
            }
        } else {
            if (! $serial->isSellable()) {
                $this->rowSerialError = $canViewSystemStock
                    ? 'Nomor seri tidak aktif atau tidak siap jual.'
                    : 'Nomor seri tidak dapat digunakan untuk transfer ini.';
                return;
            }
        }

        $identity = [
            'id' => $serial->id,
            'serial_number' => $serial->serial_number,
        ];

        $this->products[$index]['serial_numbers'][] = $canViewSystemStock
            ? array_merge($identity, [
                'tax_id' => $serial->tax_id,
                'taxable' => (bool) $serial->tax_id,
                'is_broken' => (bool) $serial->is_broken,
            ])
            : $identity;

        $this->blindSerialProvenance[$serial->id] = [
            'taxable' => (bool) $serial->tax_id,
            'is_broken' => (bool) $serial->is_broken,
        ];

        $this->recalculateSerialQuantities($index);
        $this->rowSerialInput = '';
        $this->rowSerialError = null;

        $this->dispatch('rowsUpdated', $this->products);
    }

    public function removeRowSerial(int $serialIndex): void
    {
        if (! $this->isAuthorizedForOrigin()) {
            return;
        }

        $rowIndex = $this->selectedRowIndexForSerials;
        if ($rowIndex === null || !isset($this->products[$rowIndex]['serial_numbers'][$serialIndex])) {
            return;
        }

        unset($this->products[$rowIndex]['serial_numbers'][$serialIndex]);
        $this->products[$rowIndex]['serial_numbers'] = array_values($this->products[$rowIndex]['serial_numbers']);
        $this->recalculateSerialQuantities($rowIndex);

        $this->dispatch('rowsUpdated', $this->products);
    }

    public function updatedSerialSearchTerm(): void
    {
        $this->searchEligibleSerials();
    }

    public function searchEligibleSerials(): void
    {
        if (! $this->isAuthorizedForOrigin() || ! TransferStockVisibility::canView()) {
            $this->serialSearchResults = [];
            return;
        }

        $rowIndex = $this->selectedRowIndexForSerials;
        if ($rowIndex === null || !isset($this->products[$rowIndex])) {
            $this->serialSearchResults = [];
            return;
        }

        if (
            $this->stockCondition !== Transfer::CONDITION_GOOD &&
            $this->stockCondition !== Transfer::CONDITION_BREAKAGE
        ) {
            $this->serialSearchResults = [];
            return;
        }

        $term = trim($this->serialSearchTerm);
        $productId = (int) ($this->products[$rowIndex]['id'] ?? 0);

        $productModel = Product::query()
            ->whereKey($productId)
            ->where('stock_managed', true)
            ->where('serial_number_required', true)
            ->active()
            ->first();

        if (! $productModel) {
            $this->serialSearchResults = [];
            return;
        }

        $isBrokenMode = ($this->stockCondition === Transfer::CONDITION_BREAKAGE);

        // Current selected serial IDs across all rows
        $selectedSerialIds = collect($this->products)->pluck('serial_numbers')->flatten(1)->pluck('id')->filter()->toArray();

        $query = ProductSerialNumber::query()
            ->where('product_id', $productModel->id)
            ->where('location_id', $this->originLocationId)
            ->whereNull('dispatch_detail_id')
            ->where('is_in_return_process', false)
            ->whereNotIn('id', $selectedSerialIds);

        if ($isBrokenMode) {
            $query->where('status', ProductSerialNumber::STATUS_ACTIVE)
                ->where('is_broken', true);
        } else {
            $query->where('status', ProductSerialNumber::STATUS_ACTIVE)
                ->where('is_broken', false);
        }

        if ($term !== '') {
            $query->where('serial_number', 'like', '%' . $term . '%');
        }

        $this->serialSearchResults = $query->limit(10)->get(['id', 'serial_number'])->toArray();
    }

    public function selectEligibleSerial(int $serialId): void
    {
        if (! $this->isAuthorizedForOrigin() || ! TransferStockVisibility::canView()) {
            return;
        }

        $rowIndex = $this->selectedRowIndexForSerials;
        if ($rowIndex === null || !isset($this->products[$rowIndex])) {
            return;
        }

        $serial = ProductSerialNumber::find($serialId);
        if ($serial) {
            $this->addRowSerial($serial->serial_number);
            $this->searchEligibleSerials();
        }
    }

    // =========================================================================
    // Backward-Compatible Event Handlers
    // =========================================================================

    /**
     * Add or increment a product row using minimal intent payload.
     */
    public function productSelected(array $product): void
    {
        $operationToken = !empty($product['operation_token']) ? (string) $product['operation_token'] : null;

        if (! $this->isAuthorizedForOrigin()) {
            if ($operationToken !== null && $operationToken !== '') {
                $this->dispatch('transfer:scan-processed', ['token' => $operationToken]);
            }
            return;
        }

        if ($operationToken !== null && $operationToken !== '' && ! $this->claimOperation($operationToken)) {
            $this->dispatch('transfer:scan-processed', ['token' => $operationToken]);
            return;
        }

        $productId = (int) ($product['id'] ?? 0);
        if ($productId <= 0) {
            if ($operationToken !== null && $operationToken !== '') {
                $this->dispatch('transfer:scan-processed', ['token' => $operationToken]);
            }
            return;
        }

        // Reload the authoritative product
        $productModel = Product::query()
            ->whereKey($productId)
            ->where('stock_managed', true)
            ->active()
            ->first();

        if (! $productModel) {
            if ($operationToken !== null && $operationToken !== '') {
                $this->dispatch('transfer:scan-processed', ['token' => $operationToken]);
            }
            return;
        }

        if (
            $this->stockCondition !== Transfer::CONDITION_GOOD &&
            $this->stockCondition !== Transfer::CONDITION_BREAKAGE
        ) {
            if ($operationToken !== null && $operationToken !== '') {
                $this->dispatch('transfer:scan-processed', ['token' => $operationToken]);
            }
            return;
        }

        $isBrokenMode = ($this->stockCondition === Transfer::CONDITION_BREAKAGE);

        // Authoritative stock record at origin location
        $stock = ProductStock::where('product_id', $productModel->id)
            ->where('location_id', $this->originLocationId)
            ->first();

        if (! $stock) {
            if ($operationToken !== null && $operationToken !== '') {
                $this->dispatch('transfer:scan-processed', ['token' => $operationToken]);
            }
            return;
        }

        $canViewSystemStock = TransferStockVisibility::canView();

        // Check if row already exists
        $existingKey = collect($this->products)->search(fn ($p) =>
            ($p['id'] ?? null) == $productModel->id &&
            ((bool)($p['is_broken_mode'] ?? false)) === $isBrokenMode
        );

        // Authoritative scan multiplier
        $scanMultiplier = 1;
        if (!empty($product['conversion_id'])) {
            $conversionId = (int) $product['conversion_id'];
            $conversionRecord = ProductUnitConversion::query()
                ->whereKey($conversionId)
                ->where('product_id', $productModel->id)
                ->first();

            if ($conversionRecord) {
                $factor = (float) $conversionRecord->conversion_factor;
                if ($factor > 0 && abs($factor - round($factor)) < 1e-6) {
                    $scanMultiplier = (int) round($factor);
                } else {
                    if ($operationToken !== null && $operationToken !== '') {
                        $this->dispatch('transfer:scan-processed', ['token' => $operationToken]);
                    }
                    return;
                }
            } else {
                if ($operationToken !== null && $operationToken !== '') {
                    $this->dispatch('transfer:scan-processed', ['token' => $operationToken]);
                }
                return;
            }
        }

        $isSerialized = (bool) $productModel->serial_number_required;

        if ($existingKey !== false) {
            if (! $isSerialized) {
                $currentRequested = (int) ($this->products[$existingKey]['requested_quantity'] ?? 0);
                $newRequested = $currentRequested + $scanMultiplier;

                if ($isBrokenMode) {
                    $availableBrokenStock = (int) ($stock->broken_quantity_tax ?? 0) + (int) ($stock->broken_quantity_non_tax ?? 0) - $currentRequested;

                    if ($availableBrokenStock >= $scanMultiplier) {
                        $this->products[$existingKey]['requested_quantity'] = $newRequested;
                        $this->recalculateAllocation($existingKey);
                    } else {
                        $this->flashInsufficientStock($canViewSystemStock, true);
                    }
                } else {
                    $availableGoodStock = (int) ($stock->quantity_tax ?? 0) + (int) ($stock->quantity_non_tax ?? 0);
                    if ($availableGoodStock === 0 && !is_null($stock->quantity)) {
                        $brokenQty = (int) ($stock->broken_quantity_tax ?? 0) + (int) ($stock->broken_quantity_non_tax ?? 0);
                        $availableGoodStock = max(0, (int) $stock->quantity - $brokenQty);
                    }
                    $availableStock = $availableGoodStock - $currentRequested;

                    if ($availableStock >= $scanMultiplier) {
                        $this->products[$existingKey]['requested_quantity'] = $newRequested;
                        $this->recalculateAllocation($existingKey);
                    } else {
                        $this->flashInsufficientStock($canViewSystemStock, false);
                    }
                }

                if ($operationToken !== null && $operationToken !== '') {
                    $this->dispatch('transfer:scan-processed', ['token' => $operationToken]);
                }

                $this->dispatch('rowsUpdated', $this->products);
            } else {
                if ($operationToken !== null && $operationToken !== '') {
                    $this->dispatch('transfer:scan-processed', ['token' => $operationToken]);
                }
            }
            return;
        }

        // Check if there is sufficient condition-specific stock to add this item
        if ($isBrokenMode) {
            $totalAvailable = (int) ($stock->broken_quantity_tax ?? 0) + (int) ($stock->broken_quantity_non_tax ?? 0);
        } else {
            $totalAvailable = (int) ($stock->quantity_tax ?? 0) + (int) ($stock->quantity_non_tax ?? 0);
            if ($totalAvailable === 0 && !is_null($stock->quantity)) {
                $brokenQty = (int) ($stock->broken_quantity_tax ?? 0) + (int) ($stock->broken_quantity_non_tax ?? 0);
                $totalAvailable = max(0, (int) $stock->quantity - $brokenQty);
            }
        }

        $neededStock = $isSerialized ? 1 : $scanMultiplier;
        if ($totalAvailable < $neededStock) {
            $this->flashInsufficientStock($canViewSystemStock, $isBrokenMode);
            if ($operationToken !== null && $operationToken !== '') {
                $this->dispatch('transfer:scan-processed', ['token' => $operationToken]);
            }
            return;
        }

        // Initialize authoritative row payload
        $row = [
            'id'                      => $productModel->id,
            'product_name'            => $productModel->product_name,
            'product_code'            => $productModel->product_code,
            'barcode'                 => $productModel->barcode,
            'serial_number_required'  => $isSerialized,
            'is_broken_mode'          => $isBrokenMode,
            'requested_quantity'      => $isSerialized ? 0 : $scanMultiplier,
            'quantity_tax'            => 0,
            'quantity_non_tax'        => 0,
            'broken_quantity_tax'     => 0,
            'broken_quantity_non_tax' => 0,
            'serial_numbers'          => [],
        ];

        if ($canViewSystemStock) {
            $row['stock'] = [
                'total'                   => $stock->quantity                 ?? 0,
                'quantity_tax'            => $stock->quantity_tax             ?? 0,
                'quantity_non_tax'        => $stock->quantity_non_tax         ?? 0,
                'broken_quantity_tax'     => $stock->broken_quantity_tax      ?? 0,
                'broken_quantity_non_tax' => $stock->broken_quantity_non_tax  ?? 0,
            ];
        }

        $this->products[] = $row;
        $key = array_key_last($this->products);
        $this->serialNumberErrors[] = null;
        $this->tableValidationErrors = [];

        if (! $isSerialized) {
            $this->recalculateAllocation($key);
        }

        if ($operationToken !== null && $operationToken !== '') {
            $this->dispatch('transfer:scan-processed', ['token' => $operationToken]);
        }

        $this->dispatch('rowsUpdated', $this->products);
    }

    private function flashInsufficientStock(bool $canViewSystemStock, bool $isBrokenMode): void
    {
        if ($canViewSystemStock) {
            session()->flash('message', $isBrokenMode
                ? 'Stok rusak tidak mencukupi untuk ditambah lagi.'
                : 'Stok tidak mencukupi untuk ditambah lagi.');
            $this->feedbackMessage = $isBrokenMode
                ? 'Stok rusak tidak mencukupi untuk ditambah lagi.'
                : 'Stok tidak mencukupi untuk ditambah lagi.';
            return;
        }

        session()->flash('message', 'Stok tidak mencukupi untuk item ini.');
        $this->feedbackMessage = 'Stok tidak mencukupi untuk item ini.';
    }

    /**
     * Remove a product from the table.
     */
    public function removeProduct(int $key): void
    {
        unset($this->products[$key]);
        $this->products = array_values($this->products);
        unset($this->serialNumberErrors[$key]);
        $this->serialNumberErrors = array_values($this->serialNumberErrors);
        $this->tableValidationErrors = [];

        $this->dispatch('rowsUpdated', $this->products);
    }

    public function serialNumberSelected($payload): void
    {
        $operationToken = !empty($payload['operation_token']) ? (string) $payload['operation_token'] : null;

        if (! $this->isAuthorizedForOrigin()) {
            if ($operationToken !== null && $operationToken !== '') {
                $this->dispatch('transfer:scan-processed', ['token' => $operationToken]);
            }
            return;
        }

        if (
            $this->stockCondition !== Transfer::CONDITION_GOOD &&
            $this->stockCondition !== Transfer::CONDITION_BREAKAGE
        ) {
            if ($operationToken !== null && $operationToken !== '') {
                $this->dispatch('transfer:scan-processed', ['token' => $operationToken]);
            }
            return;
        }

        if ($operationToken !== null && $operationToken !== '' && ! $this->claimOperation($operationToken)) {
            $this->dispatch('transfer:scan-processed', ['token' => $operationToken]);
            return;
        }

        $productCompositeKey = $payload['productCompositeKey'] ?? null;

        if (! is_numeric($productCompositeKey)) {
            if ($operationToken !== null && $operationToken !== '') {
                $this->dispatch('transfer:scan-processed', ['token' => $operationToken]);
            }
            return;
        }

        $rowKey = (int) $productCompositeKey;

        if (! isset($this->products[$rowKey])) {
            if ($operationToken !== null && $operationToken !== '') {
                $this->dispatch('transfer:scan-processed', ['token' => $operationToken]);
            }
            return;
        }

        $rowProductId = (int) ($this->products[$rowKey]['id'] ?? 0);

        // Authoritatively reload the global product
        $productModel = Product::query()
            ->whereKey($rowProductId)
            ->where('stock_managed', true)
            ->where('serial_number_required', true)
            ->active()
            ->first();

        if (! $productModel) {
            if ($operationToken !== null && $operationToken !== '') {
                $this->dispatch('transfer:scan-processed', ['token' => $operationToken]);
            }
            return;
        }

        $serialNumber = $payload['serialNumber'] ?? null;
        $serialId     = (int) ($serialNumber['id'] ?? 0);

        if ($serialId <= 0) {
            if ($operationToken !== null && $operationToken !== '') {
                $this->dispatch('transfer:scan-processed', ['token' => $operationToken]);
            }
            return;
        }

        // Prevent duplicate selections across all rows
        if ($this->serialExistsInRows($serialId, $rowKey)) {
            $this->serialNumberErrors[$rowKey] = 'Nomor seri sudah dipilih.';
            if ($operationToken !== null && $operationToken !== '') {
                $this->dispatch('transfer:scan-processed', ['token' => $operationToken]);
            }
            return;
        }

        $isBrokenMode = ($this->stockCondition === Transfer::CONDITION_BREAKAGE);
        $canViewSystemStock = TransferStockVisibility::canView();

        $serial = ProductSerialNumber::query()
            ->whereKey($serialId)
            ->where('product_id', $productModel->id)
            ->where('location_id', $this->originLocationId)
            ->first();

        if (! $serial) {
            $this->serialNumberErrors[$rowKey] = $canViewSystemStock
                ? 'Nomor seri tidak valid untuk produk atau lokasi asal terpilih.'
                : 'Nomor seri tidak dapat digunakan.';
            if ($operationToken !== null && $operationToken !== '') {
                $this->dispatch('transfer:scan-processed', ['token' => $operationToken]);
            }
            return;
        }

        if ($serial->dispatch_detail_id !== null || PendingDispatchSerialGuard::isReserved((string) $serial->serial_number)) {
            $this->serialNumberErrors[$rowKey] = $canViewSystemStock
                ? 'Nomor seri sedang dalam proses pengiriman.'
                : 'Nomor seri tidak dapat digunakan.';
            if ($operationToken !== null && $operationToken !== '') {
                $this->dispatch('transfer:scan-processed', ['token' => $operationToken]);
            }
            return;
        }

        if ($serial->is_in_return_process) {
            $this->serialNumberErrors[$rowKey] = $canViewSystemStock
                ? 'Nomor seri sedang dalam proses retur.'
                : 'Nomor seri tidak dapat digunakan.';
            if ($operationToken !== null && $operationToken !== '') {
                $this->dispatch('transfer:scan-processed', ['token' => $operationToken]);
            }
            return;
        }

        if ($isBrokenMode) {
            if (! $serial->isAvailableBroken()) {
                $this->serialNumberErrors[$rowKey] = $canViewSystemStock
                    ? 'Nomor seri tidak berstatus rusak untuk transfer barang rusak.'
                    : 'Nomor seri tidak dapat digunakan untuk transfer ini.';
                if ($operationToken !== null && $operationToken !== '') {
                    $this->dispatch('transfer:scan-processed', ['token' => $operationToken]);
                }
                return;
            }
        } else {
            if (! $serial->isSellable()) {
                $this->serialNumberErrors[$rowKey] = $canViewSystemStock
                    ? 'Nomor seri tidak aktif atau tidak siap jual.'
                    : 'Nomor seri tidak dapat digunakan untuk transfer ini.';
                if ($operationToken !== null && $operationToken !== '') {
                    $this->dispatch('transfer:scan-processed', ['token' => $operationToken]);
                }
                return;
            }
        }

        $currentSerials = collect($this->products[$rowKey]['serial_numbers'] ?? []);

        if ($currentSerials->pluck('id')->contains($serial->id)) {
            $this->serialNumberErrors[$rowKey] = 'Nomor seri sudah dipilih.';
            if ($operationToken !== null && $operationToken !== '') {
                $this->dispatch('transfer:scan-processed', ['token' => $operationToken]);
            }
            return;
        }

        $this->serialNumberErrors[$rowKey] = null;

        $identity = [
            'id'            => $serial->id,
            'serial_number' => $serial->serial_number,
        ];

        $this->products[$rowKey]['serial_numbers'][] = $canViewSystemStock
            ? array_merge($identity, [
                'tax_id'    => $serial->tax_id,
                'taxable'   => (bool) $serial->tax_id,
                'is_broken' => (bool) $serial->is_broken,
            ])
            : $identity;

        $this->blindSerialProvenance[$serial->id] = [
            'taxable'   => (bool) $serial->tax_id,
            'is_broken' => (bool) $serial->is_broken,
        ];

        $this->recalculateSerialQuantities($rowKey);

        if ($operationToken !== null && $operationToken !== '') {
            $this->dispatch('transfer:scan-processed', ['token' => $operationToken]);
        }

        $this->dispatch('rowsUpdated', $this->products);
    }

    public function removeSerialNumber($rowKey, $serialIndex = null): void
    {
        if (is_array($rowKey)) {
            $serialIndex = $rowKey['serialIndex'] ?? null;
            $rowKey      = $rowKey['productCompositeKey'] ?? $rowKey['row'] ?? null;
        }

        if (! is_numeric($rowKey)) {
            return;
        }

        $rowKey = (int) $rowKey;

        if (! isset($this->products[$rowKey]) || $serialIndex === null) {
            return;
        }

        if (! isset($this->products[$rowKey]['serial_numbers'][$serialIndex])) {
            return;
        }

        unset($this->products[$rowKey]['serial_numbers'][$serialIndex]);
        $this->products[$rowKey]['serial_numbers'] = array_values($this->products[$rowKey]['serial_numbers']);

        $this->serialNumberErrors[$rowKey] = null;

        $this->recalculateSerialQuantities($rowKey);

        $this->dispatch('rowsUpdated', $this->products);
    }

    private function serialProvenance(array $serial): array
    {
        $serialId = (int) ($serial['id'] ?? 0);

        if (array_key_exists('taxable', $serial) && array_key_exists('is_broken', $serial) && TransferStockVisibility::canView()) {
            return [
                'taxable'   => (bool) $serial['taxable'],
                'is_broken' => (bool) $serial['is_broken'],
            ];
        }

        if (isset($this->blindSerialProvenance[$serialId])) {
            return $this->blindSerialProvenance[$serialId];
        }

        $model = ProductSerialNumber::find($serialId);

        $provenance = [
            'taxable'   => (bool) ($model?->tax_id),
            'is_broken' => (bool) ($model?->is_broken),
        ];

        $this->blindSerialProvenance[$serialId] = $provenance;

        return $provenance;
    }

    protected function serialExistsInRows(int $serialId, int $currentRowKey): bool
    {
        return collect($this->products)
            ->filter(fn ($_, $index) => $index !== $currentRowKey)
            ->pluck('serial_numbers')
            ->flatten(1)
            ->pluck('id')
            ->contains($serialId);
    }

    protected function recalculateSerialQuantities(int $rowKey): void
    {
        $serials = $this->products[$rowKey]['serial_numbers'] ?? [];

        $quantityTax            = 0;
        $quantityNonTax        = 0;
        $brokenQuantityTax     = 0;
        $brokenQuantityNonTax  = 0;

        foreach ($serials as $serial) {
            $provenance = $this->serialProvenance($serial);
            $isBroken = $provenance['is_broken'];
            $isTaxed  = $provenance['taxable'];

            if ($isBroken && $isTaxed) {
                $brokenQuantityTax++;
            } elseif ($isBroken && ! $isTaxed) {
                $brokenQuantityNonTax++;
            } elseif (! $isBroken && $isTaxed) {
                $quantityTax++;
            } else {
                $quantityNonTax++;
            }
        }

        $result = [
            'quantity_tax'            => $quantityTax,
            'quantity_non_tax'        => $quantityNonTax,
            'broken_quantity_tax'     => $brokenQuantityTax,
            'broken_quantity_non_tax' => $brokenQuantityNonTax,
        ];

        $this->applyAllocationResult($rowKey, TransferStockVisibility::canView(), $result);
        $this->products[$rowKey]['requested_quantity'] = count($serials);
    }

    public function updated($name, $value)
    {
        if (strpos($name, 'products.') === 0) {
            if (preg_match('/^products\.(\d+)\.requested_quantity$/', $name, $matches)) {
                $rowKey = (int) $matches[1];
                $this->recalculateAllocation($rowKey);
            } else {
                unset($this->tableValidationErrors[$name]);
            }

            unset($this->tableValidationErrors['rows']);
            $this->dispatch('rowsUpdated', $this->products);
        }
    }

    protected function recalculateAllocation(int $rowKey): void
    {
        if (!isset($this->products[$rowKey])) {
            return;
        }

        if (! $this->isAuthorizedForOrigin()) {
            return;
        }

        $product = &$this->products[$rowKey];

        if (!empty($product['serial_number_required'])) {
            return;
        }

        $canViewSystemStock = TransferStockVisibility::canView();
        $requestedQty = max(0, (int) ($product['requested_quantity'] ?? 0));

        $stock = ProductStock::where('product_id', $product['id'])
            ->where('location_id', $this->originLocationId)
            ->first();

        $zeroed = [
            'quantity_tax' => 0,
            'quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ];

        if (!$stock) {
            $this->applyAllocationResult($rowKey, $canViewSystemStock, $zeroed);
            return;
        }

        $allocationService = app(TransferAllocationPreviewService::class);
        $isBrokenMode = (bool) ($product['is_broken_mode'] ?? false);

        $allocation = $allocationService->previewAllocation($stock, $requestedQty, $isBrokenMode);

        if ($isBrokenMode) {
            $result = [
                'broken_quantity_non_tax' => (int) $allocation->allocatedNonTax,
                'broken_quantity_tax'     => (int) $allocation->allocatedTax,
                'quantity_tax'            => 0,
                'quantity_non_tax'        => 0,
            ];

            if ($allocation->isInsufficient) {
                $this->tableValidationErrors['products.' . $rowKey . '.requested_quantity'] = $canViewSystemStock
                    ? 'Stok rusak tidak mencukupi untuk jumlah yang diminta.'
                    : 'Stok tidak mencukupi untuk item ini.';
            } else {
                unset($this->tableValidationErrors['products.' . $rowKey . '.requested_quantity']);
            }
        } else {
            $result = [
                'quantity_non_tax'        => (int) $allocation->allocatedNonTax,
                'quantity_tax'            => (int) $allocation->allocatedTax,
                'broken_quantity_tax'     => 0,
                'broken_quantity_non_tax' => 0,
            ];

            if ($allocation->isInsufficient) {
                $this->tableValidationErrors['products.' . $rowKey . '.requested_quantity'] = $canViewSystemStock
                    ? 'Stok tidak mencukupi untuk jumlah yang diminta.'
                    : 'Stok tidak mencukupi untuk item ini.';
            } else {
                unset($this->tableValidationErrors['products.' . $rowKey . '.requested_quantity']);
            }
        }

        $this->applyAllocationResult($rowKey, $canViewSystemStock, $result);
    }

    private function applyAllocationResult(int $rowKey, bool $canViewSystemStock, array $result): void
    {
        if ($canViewSystemStock) {
            $this->products[$rowKey] = array_merge($this->products[$rowKey], $result);
            return;
        }

        $this->blindAllocationCache[$rowKey] = $result;

        foreach (array_keys($result) as $key) {
            unset($this->products[$rowKey][$key]);
        }
    }

    public function onTableValidationErrors(array $errors): void
    {
        $this->tableValidationErrors = $errors;
    }

    public function onCollectTableErrors(): void
    {
        $this->dispatch('tableValidationErrors', $this->tableValidationErrors);
    }

    public function serialScanned(array $payload): void
    {
        $operationToken = !empty($payload['operation_token']) ? (string) $payload['operation_token'] : null;

        $productId = $payload['product_id'] ?? null;
        if (!$productId) {
            if ($operationToken !== null && $operationToken !== '') {
                $this->dispatch('transfer:scan-processed', ['token' => $operationToken]);
            }
            return;
        }

        $rowKey = collect($this->products)
            ->filter(fn ($p) => ($p['id'] ?? null) == $productId)
            ->keys()
            ->last();

        if ($rowKey === null) {
            if ($operationToken !== null && $operationToken !== '') {
                $this->dispatch('transfer:scan-processed', ['token' => $operationToken]);
            }
            return;
        }

        $payload['productCompositeKey'] = $rowKey;
        $payload['serialNumber'] = $payload;

        $this->serialNumberSelected($payload);
    }

    protected function getProcessedTokensKey(): string
    {
        return 'transfer_processed_tokens_' . session()->getId() . '_' . ($this->originLocationId ?? 'none');
    }

    protected function getClaimedTokenCacheKey(string $token): string
    {
        return 'transfer_op_claimed_' . $this->getProcessedTokensKey() . '_' . $token;
    }

    public function isOperationProcessed(string $token): bool
    {
        return \Illuminate\Support\Facades\Cache::has($this->getClaimedTokenCacheKey($token));
    }

    public function claimOperation(string $token): bool
    {
        $ttlMinutes = (int) config('session.lifetime', 120);

        return \Illuminate\Support\Facades\Cache::add($this->getClaimedTokenCacheKey($token), time(), now()->addMinutes($ttlMinutes));
    }

    public function render(): View
    {
        return view('livewire.transfer.transfer-product-table', [
            'canViewSystemStock' => TransferStockVisibility::canView(),
        ]);
    }
}
