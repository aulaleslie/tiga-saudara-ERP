<?php

namespace App\Livewire\Transfer;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Adjustment\Services\TransferAllocationPreviewService;
use Modules\Adjustment\Services\TransferStockVisibility;
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
     * this listener. Accepting a condition change here instead would only
     * ever be a crafted direct call impersonating that event: resetting rows
     * limits contamination of already-entered rows, but a forged value would
     * still stand as this live instance's origin/condition authority for
     * every product/serial mutation afterward, letting the same call chain
     * add new rows under a condition or origin the parent form never
     * actually selected. Condition is therefore not synchronized live at
     * all -- only a remount (the real wire:key change) may ever change it
     * for this instance.
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
     * determines origin stock eligibility (existing behavior: destination
     * changes must never clear rows). originLocationId, like stockCondition
     * above, is only ever authoritative via mount() -- a live change request
     * for it is ignored rather than trusted, since accepting a crafted
     * origin here would let a subsequent crafted productSelected() add rows
     * against an origin the parent form never actually selected, even
     * though isAuthorizedForOrigin() would still correctly confine it to a
     * tenant-owned location.
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

        // notify parent of reset
        $this->dispatch('rowsUpdated', $this->products);
    }

    /**
     * Whether the acting user may drive this table's stock-querying methods
     * at all (surface authorization) for the location currently set as
     * originLocationId (authoritative tenant scope).
     *
     * This component is an independently callable Livewire endpoint: a
     * crafted direct call to productSelected/serialNumberSelected/etc. does
     * not pass through the route or parent form that normally mount it, so
     * relying on their gates would leave those methods unauthorized on
     * their own. A user who holds only stockTransfers.view-system-stock --
     * without transfer create/edit authority -- must not be able to drive
     * this table to query stock at all; that permission governs *what* is
     * shown for an authorized origin, not *whether* this table may be used.
     * Separately, originLocationId is public Livewire state, so an
     * otherwise-authorized user must still be confined to a location their
     * active tenant setting actually owns.
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

    /**
     * Add a product (with its stock snapshot) to the table
     */
    /**
     * Add or increment a product row using minimal intent payload (reloaded authoritatively).
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

        $tenantSettingId = (int) session('setting_id');

        // Reload the authoritative product
        $productModel = \Modules\Product\Entities\Product::query()
            ->whereKey($productId)
            ->where('setting_id', $tenantSettingId)
            ->where('stock_managed', true)
            ->active()
            ->first();

        if (! $productModel) {
            if ($operationToken !== null && $operationToken !== '') {
                $this->dispatch('transfer:scan-processed', ['token' => $operationToken]);
            }
            return;
        }

        // Authoritative condition: the table's own stockCondition, kept in
        // sync with the parent form via the transfer:condition-changed
        // listener. A client-supplied is_broken_mode field on the payload is
        // never trusted as mutation authority; an unset/invalid condition
        // rejects the intent rather than defaulting to good mode.
        if (
            $this->stockCondition !== \Modules\Adjustment\Entities\Transfer::CONDITION_GOOD &&
            $this->stockCondition !== \Modules\Adjustment\Entities\Transfer::CONDITION_BREAKAGE
        ) {
            if ($operationToken !== null && $operationToken !== '') {
                $this->dispatch('transfer:scan-processed', ['token' => $operationToken]);
            }
            return;
        }

        $isBrokenMode = ($this->stockCondition === \Modules\Adjustment\Entities\Transfer::CONDITION_BREAKAGE);

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

        // Authoritative scan multiplier (reloaded if conversion_id is present, otherwise strictly 1)
        $scanMultiplier = 1;
        if (!empty($product['conversion_id'])) {
            $conversionId = (int) $product['conversion_id'];
            $conversionRecord = \Modules\Product\Entities\ProductUnitConversion::query()
                ->whereKey($conversionId)
                ->where('product_id', $productModel->id)
                ->whereHas('product', fn($q) => $q->where('setting_id', $tenantSettingId))
                ->first();

            if ($conversionRecord) {
                $factor = (float) $conversionRecord->conversion_factor;
                if ($factor > 0 && abs($factor - round($factor)) < 1e-6) {
                    $scanMultiplier = (int) round($factor);
                } else {
                    // Invalid conversion factor, reject intent
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
            // For serial products, quantities are derived from serials, don't increment automatically
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

        // merge in stock columns for privileged display only
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
            return;
        }

        session()->flash('message', 'Stok tidak mencukupi untuk item ini.');
    }

    /**
     * Remove a product from the table
     */
    public function removeProduct(int $key): void
    {
        unset($this->products[$key]);
        $this->products = array_values($this->products);
        unset($this->serialNumberErrors[$key]);
        $this->serialNumberErrors = array_values($this->serialNumberErrors);
        $this->tableValidationErrors = [];

        // notify parent that rows have changed
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

        if (empty($this->products[$rowKey]['serial_number_required'])) {
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

        $tenantSettingId = (int) session('setting_id');
        $rowProductId = (int) ($this->products[$rowKey]['id'] ?? 0);
        $isBrokenMode = (bool) ($this->products[$rowKey]['is_broken_mode'] ?? false);
        $canViewSystemStock = TransferStockVisibility::canView();

        $serial = ProductSerialNumber::query()
            ->whereKey($serialId)
            ->where('product_id', $rowProductId)
            ->where('location_id', $this->originLocationId)
            ->whereHas('product', fn ($q) => $q->where('setting_id', $tenantSettingId))
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

        // Validate operational availability
        if ($serial->dispatch_detail_id !== null) {
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

        // Validate mode compatibility
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

        // Public row state always carries the selected serial's identity
        // (operator intent, shared with every authorized editor). tax_id,
        // taxable classification, and broken-condition provenance are
        // protected system information and are only added for a privileged
        // user; a blind user's authoritative bucket split is instead kept
        // in the protected blindSerialProvenance cache below.
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

    /**
     * Resolves a selected serial's tax/broken provenance, always
     * authoritatively (a privileged serial entry already carries it; a
     * blind entry does not, so it is reloaded server-side by id -- never
     * trusted from a client-supplied taxable/is_broken field even if one
     * were somehow present).
     *
     * @param array $serial
     * @return array{taxable: bool, is_broken: bool}
     */
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

        // The aggregate serial-provenance breakdown is protected system
        // information just like a non-serialized allocation breakdown (task
        // 3.4): applyAllocationResult keeps it in the blind-only server
        // cache instead of public row state for a blind user.
        $this->applyAllocationResult($rowKey, TransferStockVisibility::canView(), $result);

        // Synchronize requested_quantity to match the serial count
        $this->products[$rowKey]['requested_quantity'] = count($serials);
    }

    /**
     * Livewire hook: whenever any nested product property updates, re-dispatch rows and clear row errors
     */
    public function updated($name, $value)
    {
        if (strpos($name, 'products.') === 0) {
            // Handle quantity input changes
            if (preg_match('/^products\.(\d+)\.requested_quantity$/', $name, $matches)) {
                $rowKey = (int) $matches[1];
                $this->recalculateAllocation($rowKey);
                // Don't clear the error for requested_quantity, it's set by recalculateAllocation
            } else {
                // clear any existing validation for this field (but not requested_quantity)
                unset($this->tableValidationErrors[$name]);
            }

            unset($this->tableValidationErrors['rows']);
            $this->dispatch('rowsUpdated', $this->products);
        }
    }

    /**
     * Recalculate tax/non-tax allocation based on requested quantity
     */
    protected function recalculateAllocation(int $rowKey): void
    {
        if (!isset($this->products[$rowKey])) {
            return;
        }

        if (! $this->isAuthorizedForOrigin()) {
            return;
        }

        $product = &$this->products[$rowKey];

        // For serialized products, allocation is derived from serials
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

    /**
     * Writes computed allocation into public row state for a privileged
     * user (existing behavior). For a blind user, the breakdown is kept
     * only in the protected, non-serialized blindAllocationCache -- it
     * never reaches public Livewire state or the rendered row.
     */
    private function applyAllocationResult(int $rowKey, bool $canViewSystemStock, array $result): void
    {
        if ($canViewSystemStock) {
            $this->products[$rowKey] = array_merge($this->products[$rowKey], $result);
            return;
        }

        $this->blindAllocationCache[$rowKey] = $result;

        // Blind public state carries no allocation breakdown at all -- not
        // even zeroed placeholders -- per the "omitted rather than masked"
        // requirement.
        foreach (array_keys($result) as $key) {
            unset($this->products[$rowKey][$key]);
        }
    }

    /**
     * Handle validation errors dispatched from parent
     */
    public function onTableValidationErrors(array $errors): void
    {
        $this->tableValidationErrors = $errors;
    }

    /**
     * Collect current table validation errors and dispatch to parent
     */
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

        // Resolve the target row by product id and its own already-recorded
        // is_broken_mode rather than trusting an is_broken field on the
        // scanned-serial payload (omitted for a blind user, and the
        // authoritative serial condition is reloaded by id regardless in
        // serialNumberSelected -> serialProvenance()). productSelected is
        // always dispatched immediately before this event for the same
        // scan, so the most recently touched matching row is used.
        $rowKey = collect($this->products)
            ->filter(fn ($p) => ($p['id'] ?? null) == $productId)
            ->keys()
            ->last();

        if ($rowKey === null) {
            // Should not happen since productSelected is dispatched right
            // before, but still acknowledge so the scan queue is not stalled.
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

    /**
     * Determine if an operation token has already been processed, without
     * claiming it. Backed by the cache (not the session): PHP's session bag
     * is loaded into memory once at the start of each request and only
     * flushed back to the session store at the end of it, so two concurrent
     * requests can each hold their own stale in-memory copy for their entire
     * lifetime -- a session write in one is invisible to the other even
     * while serialized behind a cache lock. The cache store has no such
     * per-request snapshot, so it is the only thing that can act as
     * genuinely shared state here.
     */
    public function isOperationProcessed(string $token): bool
    {
        return \Illuminate\Support\Facades\Cache::has($this->getClaimedTokenCacheKey($token));
    }

    /**
     * Atomically claim an operation token: returns true only if this call is
     * the first to claim it. Cache::add() is itself an atomic
     * check-and-set at the store level, so no separate lock is needed (a
     * lock could only ever serialize access to a non-atomic read-then-write,
     * which is exactly what let two concurrent requests both see the token
     * as unclaimed before). Returns false if the token was already claimed
     * by another (possibly concurrent) call, in which case the caller must
     * treat the operation as a duplicate and only acknowledge it.
     *
     * The claim's TTL is tied to config('session.lifetime') rather than a
     * short fixed window: the contract is at-most-once for the life of the
     * active form session, and a claim that outlived a delayed retry only to
     * expire first would silently narrow that guarantee to whatever fixed
     * window was chosen instead.
     *
     * This guarantee additionally assumes CACHE_STORE resolves to a store
     * genuinely shared across every host serving this application (as the
     * existing SESSION_DRIVER=file/CACHE_DRIVER=file config already
     * implicitly assumes for a single-host deployment) -- on a multi-host
     * deployment behind a load balancer, a per-host file/array cache would
     * not coordinate claims across hosts and this guarantee would silently
     * degrade to per-host at-most-once instead.
     */
    public function claimOperation(string $token): bool
    {
        $ttlMinutes = (int) config('session.lifetime', 120);

        return \Illuminate\Support\Facades\Cache::add($this->getClaimedTokenCacheKey($token), time(), now()->addMinutes($ttlMinutes));
    }

    public function render(): View
    {
        return view('livewire.transfer.transfer-product-table');
    }
}
