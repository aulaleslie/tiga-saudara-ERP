<?php

namespace App\Livewire\Transfer;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
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
        'locationsConfirmed'      => 'syncLocationIds',
        'transfer:rows-reset'     => 'resetRows',
        'tableValidationErrors'   => 'onTableValidationErrors',
        'collectTableErrors'      => 'onCollectTableErrors',
    ];

    public $products = [];
    public $originLocationId;
    public $destinationLocationId;
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
    public function mount($originLocationId = null, $destinationLocationId = null, $existingProducts = []): void
    {
        $this->originLocationId      = $originLocationId;
        $this->destinationLocationId = $destinationLocationId;
        $this->products              = $existingProducts;
        $this->serialNumberErrors    = array_fill(0, count($existingProducts), null);
    }

    /**
     * Keep the table's location IDs in sync with the parent form on any
     * origin/destination change, without clearing rows. Row clearing is
     * driven separately by the parent's explicit `transfer:rows-reset` event,
     * fired only for an actual origin or mode change.
     */
    public function syncLocationIds(array $payload): void
    {
        $this->originLocationId      = $payload['originLocationId'];
        $this->destinationLocationId = $payload['destinationLocationId'];
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
    public function productSelected(array $product): void
    {
        if (! $this->isAuthorizedForOrigin()) {
            return;
        }

        $canViewSystemStock = TransferStockVisibility::canView();

        $isBrokenMode = $product['is_broken_mode'] ?? false;
        $existingKey = collect($this->products)->search(fn ($p) =>
            ($p['id'] ?? null) == ($product['id'] ?? null) &&
            ((bool)($p['is_broken_mode'] ?? false)) === (bool)$isBrokenMode
        );
        $scanMultiplier = max(1, (int) ($product['scan_quantity_multiplier'] ?? 1));

        // Get stock record (always reloaded server-side; never trusted from
        // the client-dispatched $product payload)
        $stock = ProductStock::where('product_id', $product['id'])
            ->where('location_id', $this->originLocationId)
            ->first();

        if ($existingKey !== false) {
            // For serial products, we don't automatically increment quantities, the user must scan the serials.
            if (empty($product['serial_number_required'])) {
                // requested_quantity is public state and therefore the one
                // total that reliably survives across Livewire requests --
                // unlike blindAllocationCache, which is a protected,
                // non-serialized per-request cache and is empty again by
                // the time this comparison runs on a later scan. Comparing
                // it directly against the mode's authoritative stock bucket
                // total (never the client's own $product payload) keeps the
                // accumulated-request check correct for repeated scans
                // regardless of visibility.
                $currentRequested = (int) ($this->products[$existingKey]['requested_quantity'] ?? 0);
                $newRequested = $currentRequested + $scanMultiplier;

                if ($isBrokenMode) {
                    $availableBrokenStock = ($stock?->broken_quantity ?? 0) - $currentRequested;

                    if ($availableBrokenStock >= $scanMultiplier && $stock) {
                        $this->products[$existingKey]['requested_quantity'] = $newRequested;
                        $this->recalculateAllocation($existingKey);
                    } else {
                        $this->flashInsufficientStock($canViewSystemStock, true);
                    }
                } else {
                    $availableStock = ($stock?->quantity ?? 0) - $currentRequested;

                    if ($availableStock >= $scanMultiplier && $stock) {
                        $this->products[$existingKey]['requested_quantity'] = $newRequested;
                        $this->recalculateAllocation($existingKey);
                    } else {
                        $this->flashInsufficientStock($canViewSystemStock, false);
                    }
                }

                $this->dispatch('rowsUpdated', $this->products);
            }
            return;
        }

        // initialize transfer inputs
        $product['requested_quantity']      = 0; // Single user-facing quantity input
        $product['quantity_tax']            = 0; // Calculated allocation (privileged display only)
        $product['quantity_non_tax']        = 0; // Calculated allocation (privileged display only)
        $product['broken_quantity_tax']     = 0; // Calculated allocation (privileged display only)
        $product['broken_quantity_non_tax'] = 0; // Calculated allocation (privileged display only)
        $product['serial_number_required']  = (bool) ($product['serial_number_required'] ?? false);
        $product['serial_numbers']          = [];

        // merge in stock columns for privileged display only; a blind
        // user's public $this->products row never carries this key
        if ($canViewSystemStock) {
            $product['stock'] = [
                'total'                   => $stock?->quantity                 ?? 0,
                'quantity_tax'            => $stock?->quantity_tax             ?? 0,
                'quantity_non_tax'        => $stock?->quantity_non_tax         ?? 0,
                'broken_quantity_tax'     => $stock?->broken_quantity_tax      ?? 0,
                'broken_quantity_non_tax' => $stock?->broken_quantity_non_tax  ?? 0,
            ];
        }

        // Set the requested quantity and let recalculateAllocation handle allocation
        $product['requested_quantity'] = $scanMultiplier;

        $this->products[] = $product;
        $key = array_key_last($this->products);
        $this->serialNumberErrors[] = null;
        $this->tableValidationErrors = [];

        if (!$product['serial_number_required'] && $stock) {
            $this->recalculateAllocation($key);
        }

        // notify parent that rows have changed
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
        if (! $this->isAuthorizedForOrigin()) {
            return;
        }

        $productCompositeKey = $payload['productCompositeKey'] ?? null;

        if (! is_numeric($productCompositeKey)) {
            return;
        }

        $rowKey = (int) $productCompositeKey;

        if (! isset($this->products[$rowKey])) {
            return;
        }

        if (empty($this->products[$rowKey]['serial_number_required'])) {
            return;
        }

        $serialNumber = $payload['serialNumber'] ?? null;
        $serialId     = (int) ($serialNumber['id'] ?? 0);

        if ($serialId <= 0) {
            return;
        }

        // Prevent duplicate selections across all rows
        if ($this->serialExistsInRows($serialId, $rowKey)) {
            $this->serialNumberErrors[$rowKey] = 'Nomor seri sudah dipilih.';
            return;
        }

        $serial = ProductSerialNumber::find($serialId);

        if (! $serial) {
            $this->serialNumberErrors[$rowKey] = 'Nomor seri tidak ditemukan.';
            return;
        }

        $currentSerials = collect($this->products[$rowKey]['serial_numbers'] ?? []);

        if ($currentSerials->pluck('id')->contains($serial->id)) {
            $this->serialNumberErrors[$rowKey] = 'Nomor seri sudah dipilih.';
            return;
        }

        $this->serialNumberErrors[$rowKey] = null;

        $canViewSystemStock = TransferStockVisibility::canView();

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
        $productId = $payload['product_id'] ?? null;
        if (!$productId) return;

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
            return; // Should not happen since productSelected is dispatched right before
        }

        $payload['productCompositeKey'] = $rowKey;
        $payload['serialNumber'] = $payload;

        $this->serialNumberSelected($payload);
    }

    public function render(): View
    {
        return view('livewire.transfer.transfer-product-table');
    }
}
