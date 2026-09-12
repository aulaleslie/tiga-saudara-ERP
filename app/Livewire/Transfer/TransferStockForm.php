<?php

namespace App\Livewire\Transfer;

use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferProduct;
use Modules\Setting\Entities\Setting;

class TransferStockForm extends Component
{
    public $currentSetting;
    public $settings;

    public $selectedBusiness    = null;
    public $originLocation      = null;
    public $destinationLocation = null;

    // the transfer's single explicit stock condition (Transfer::CONDITION_*)
    public ?string $stockCondition = null;

    // true when hydrating a legacy transfer whose history mixes good and
    // broken buckets; such a record cannot be saved until an explicit mode
    // is chosen, which clears the incompatible rows
    public bool $isMixedConditionHistory = false;

    /**
     * Tracks the stock condition value as of the last time rows were reset
     * against it, so updatedStockCondition can tell an actual change from
     * Livewire re-applying the same value (e.g. a stale/duplicate request).
     * Must be public: Livewire only persists public properties across
     * requests in its component snapshot: a private property is
     * reconstructed to its class default on every request and would never
     * retain the previous value, silently defeating this guard. #[Locked]
     * blocks a crafted `wire:model`/`$set` request from writing to it
     * directly, since it must only ever change via updatedStockCondition's
     * own assignment.
     */
    #[Locked]
    public ?string $lastAppliedStockCondition = null;

    // when true, a confirmation modal is shown before applying a
    // creation-time condition switch that would clear entered rows
    public bool $showConditionConfirmModal = false;

    // the condition value awaiting confirmation, applied only if confirmed
    public ?string $pendingStockCondition = null;

    // holds the table rows data
    public $rows = [];

    // hold validation errors for locations only
    public $selfManagedValidationErrors = [];

    // hold validation errors from the table
    public $tableValidationErrors = [];

    protected $listeners = [
        'locationDropdownSelected'    => 'onLocationDropdownSelected',
        'originLocationSelected'      => 'onOriginLocationSelected',
        'destinationLocationSelected' => 'onDestinationLocationSelected',
        'rowsUpdated'                 => 'onRowsUpdated',
        'tableValidationErrors'       => 'onTableValidationErrors',
    ];

    public ?Transfer $transfer = null;

    public function mount(?Transfer $transfer = null)
    {
        $this->currentSetting = Setting::find(session('setting_id'));
        $this->settings       = Setting::all();

        if ($transfer && $transfer->exists) {
            $this->transfer = $transfer;
            $this->originLocation = $transfer->origin_location_id;
            $this->destinationLocation = $transfer->destination_location_id;
            $this->stockCondition = $transfer->stock_condition;

            $mapper = app(\Modules\Adjustment\Services\TransferFormStateMapper::class);
            $this->isMixedConditionHistory = $mapper->isMixedConditionHistory($transfer);
            $this->rows = $this->isMixedConditionHistory ? [] : $mapper->mapToLivewireRows($transfer);
        } else {
            // New transfers default to good-stock mode; the operator can
            // still switch to breakage mode via selectStockCondition().
            $this->stockCondition = Transfer::CONDITION_GOOD;
        }

        $this->lastAppliedStockCondition = $this->stockCondition;
    }

    /**
     * Routes the shared LocationSearchDropdown's targeted selection event to
     * the origin or destination handler based on the field name.
     */
    public function onLocationDropdownSelected($name, $value): void
    {
        if ($name === 'origin_location') {
            $this->onOriginLocationSelected($value ? ['id' => (int) $value] : null);
        } elseif ($name === 'destination_location') {
            $this->onDestinationLocationSelected($value ? ['id' => (int) $value] : null);
        }
    }

    /**
     * Changing origin invalidates destination exclusion, stock snapshots,
     * and serial eligibility, so destination and all rows are cleared.
     * Destination selection/change never takes this destructive path.
     */
    public function onOriginLocationSelected($payload)
    {
        Log::info('onOriginLocationSelected', ['payload' => $payload]);

        $newOriginId = $payload['id'] ?? null;
        $originActuallyChanged = $newOriginId !== $this->originLocation;

        $this->originLocation = $newOriginId;

        if ($newOriginId) {
            unset($this->selfManagedValidationErrors['origin_location']);
        }

        if ($originActuallyChanged) {
            $this->destinationLocation = null;
            $this->rows = [];
            $this->tableValidationErrors = [];
            $this->isMixedConditionHistory = false;
            $this->dispatch('transfer:rows-reset');
        }

        $this->notifyLocationChange();
    }

    /**
     * Destination selection/change/clear never affects rows: it does not
     * change source stock availability.
     */
    public function onDestinationLocationSelected($payload)
    {
        Log::info('onDestinationLocationSelected', ['payload' => $payload]);

        $this->destinationLocation = $payload['id'] ?? null;

        if ($this->destinationLocation) {
            unset($this->selfManagedValidationErrors['destination_location']);
        }

        $this->notifyLocationChange();
    }

    /**
     * Changing the transfer-wide stock condition invalidates every row's
     * stock interpretation, so rows/serials/errors are cleared. Origin and
     * destination selections are preserved across this reset. Called
     * directly by the segmented control's buttons (wire:click), not via
     * wire:model, so Livewire component state is always the source of
     * truth for which condition is selected/highlighted.
     */
    public function selectStockCondition(?string $value): void
    {
        if (! in_array($value, Transfer::CONDITIONS, true)) {
            return;
        }

        if ($this->lastAppliedStockCondition === $value) {
            return;
        }

        // Condition is only writable before initial persistence. If rows
        // have already been entered, require confirmation before clearing
        // them; otherwise apply the change immediately.
        if (!empty($this->rows)) {
            $this->pendingStockCondition = $value;
            $this->showConditionConfirmModal = true;

            // Revert the visible control to the last applied value until
            // the user confirms; confirming re-applies $value explicitly.
            $this->stockCondition = $this->lastAppliedStockCondition;

            return;
        }

        $this->applyStockConditionChange($value);
    }

    /**
     * Apply a confirmed creation-time condition switch: clears every row
     * and reloads product entry for the newly selected condition.
     */
    public function confirmConditionChange(): void
    {
        $value = $this->pendingStockCondition;

        $this->showConditionConfirmModal = false;
        $this->pendingStockCondition = null;

        $this->applyStockConditionChange($value);
    }

    /**
     * Cancel a pending creation-time condition switch: preserves the prior
     * condition and every existing row.
     */
    public function cancelConditionChange(): void
    {
        $this->showConditionConfirmModal = false;
        $this->pendingStockCondition = null;
        $this->stockCondition = $this->lastAppliedStockCondition;
    }

    private function applyStockConditionChange(?string $value): void
    {
        $this->stockCondition = $value;
        $this->lastAppliedStockCondition = $value;

        $this->rows = [];
        $this->tableValidationErrors = [];
        $this->isMixedConditionHistory = false;
        $this->dispatch('transfer:rows-reset');
        $this->dispatch('transfer:condition-changed', condition: $value);
    }

    public function onRowsUpdated(array $rows)
    {
        Log::info('onRowsUpdated', ['rows' => $rows]);
        $this->rows = $rows;
    }

    public function onTableValidationErrors(array $errors)
    {
        $this->tableValidationErrors = $errors;
    }

    /**
     * Save the current origin/mode/rows as a DRAFT. Destination is optional.
     * A visible @can in the Blade view only hides the button; it does not
     * authorize a crafted Livewire request, so the permission is rechecked
     * here at the mutation boundary.
     */
    public function saveDraft()
    {
        $requiredPermission = ($this->transfer && $this->transfer->exists)
            ? 'stockTransfers.edit'
            : 'stockTransfers.create';

        if (\Illuminate\Support\Facades\Gate::denies($requiredPermission)) {
            abort(403);
        }

        $preparedRows = $this->validateAndPrepareRows(requireDestination: false);

        if ($preparedRows === null) {
            return;
        }

        try {
            $draftService = app(\Modules\Adjustment\Services\TransferDraftService::class);
            $mapper = app(\Modules\Adjustment\Services\TransferFormStateMapper::class);

            $wasPending = $this->transfer && $this->transfer->exists
                && $this->transfer->status === Transfer::STATUS_PENDING;

            $formState = $mapper->mapToTransferFormState(
                $preparedRows,
                (int) $this->originLocation,
                $this->destinationLocation ? (int) $this->destinationLocation : null,
                $this->stockCondition
            );

            $transfer = $draftService->saveDraft(
                $formState,
                auth()->user(),
                $this->currentSetting->id,
                $this->transfer
            );

            if ($wasPending && $transfer->status === Transfer::STATUS_DRAFT) {
                toast('Transfer diperbarui dan dikembalikan ke Draf karena perubahan material. Ajukan kembali untuk persetujuan. No. Dokumen: ' . $transfer->document_number, 'info');
            } else {
                toast('Draft Transfer Stok disimpan. No. Dokumen: ' . $transfer->document_number, 'success');
            }

            return redirect()->route('transfers.index');
        } catch (Exception $e) {
            Log::error('Transfer saveDraft error', ['error' => $e->getMessage()]);
            session()->flash('message', 'Terjadi kesalahan saat menyimpan draft transfer.');
        } finally {
            $this->dispatch('transfer:submit-finish');
        }
    }

    /**
     * Submit a complete DRAFT for approval. Requires a valid destination.
     * Rechecks stockTransfers.edit here since the Blade @can only hides the
     * button and cannot stop a crafted Livewire request.
     */
    public function submitForApproval()
    {
        if (! $this->transfer || ! $this->transfer->exists) {
            $this->selfManagedValidationErrors['rows'] = 'Simpan draft terlebih dahulu sebelum mengajukan persetujuan.';
            return;
        }

        if (\Illuminate\Support\Facades\Gate::denies('stockTransfers.edit')) {
            abort(403);
        }

        $preparedRows = $this->validateAndPrepareRows(requireDestination: true);

        if ($preparedRows === null) {
            return;
        }

        try {
            $draftService = app(\Modules\Adjustment\Services\TransferDraftService::class);
            $mapper = app(\Modules\Adjustment\Services\TransferFormStateMapper::class);

            $formState = $mapper->mapToTransferFormState(
                $preparedRows,
                (int) $this->originLocation,
                (int) $this->destinationLocation,
                $this->stockCondition
            );

            $transfer = $draftService->submitForApproval(
                $formState,
                auth()->user(),
                $this->currentSetting->id,
                $this->transfer
            );

            toast('Transfer Stok diajukan untuk persetujuan! No. Dokumen: ' . $transfer->document_number, 'success');

            return redirect()->route('transfers.index');
        } catch (Exception $e) {
            Log::error('Transfer submitForApproval error', ['error' => $e->getMessage()]);
            session()->flash('message', 'Terjadi kesalahan saat mengajukan transfer untuk persetujuan.');
        } finally {
            $this->dispatch('transfer:submit-finish');
        }
    }

    /**
     * Shared origin/mode/row validation for both saveDraft and
     * submitForApproval. Returns null (after dispatching errors) when
     * validation fails, or the prepared row array when it passes.
     */
    private function validateAndPrepareRows(bool $requireDestination): ?array
    {
        $this->dispatch('transfer:submit-start');

        // reset location errors
        $this->selfManagedValidationErrors = [];
        $tableErrors = [];

        // emit any existing table errors (will be reset below if none)
        $this->dispatch('tableValidationErrors', $tableErrors);

        // Collect any existing validation errors from the table (e.g., insufficient stock)
        $this->dispatch('collectTableErrors');

        // validate origin and destination
        if (! $this->originLocation) {
            $this->selfManagedValidationErrors['origin_location'] = 'Silakan pilih Lokasi Asal.';
        }
        if ($requireDestination && ! $this->destinationLocation) {
            $this->selfManagedValidationErrors['destination_location'] = 'Silakan pilih Lokasi Tujuan untuk mengajukan persetujuan.';
        }

        if ($this->originLocation && $this->destinationLocation && $this->originLocation === $this->destinationLocation) {
            $this->selfManagedValidationErrors['destination_location'] = 'Lokasi tujuan harus berbeda dari lokasi asal.';
        }

        if (! $this->stockCondition || ! in_array($this->stockCondition, Transfer::CONDITIONS, true)) {
            $this->selfManagedValidationErrors['stock_condition'] = 'Silakan pilih kondisi stok (Baik atau Rusak).';
        }

        if ($this->isMixedConditionHistory) {
            $this->selfManagedValidationErrors['stock_condition'] = 'Pilih satu kondisi stok untuk melanjutkan; baris yang tidak sesuai akan dihapus.';
        }

        // validate rows
        $preparedRows = [];

        if (empty($this->rows)) {
            $this->selfManagedValidationErrors['rows'] = 'Silakan pilih minimal satu produk.';
        } else {
            $canViewSystemStock = \Illuminate\Support\Facades\Gate::allows(\Modules\Adjustment\Services\TransferStockVisibility::PERMISSION);

            foreach ($this->rows as $i => $row) {
                $requestedQuantity = max(0, (int) ($row['requested_quantity'] ?? 0));
                $serials       = $this->normalizeSerialPayload($row['serial_numbers'] ?? []);
                $serialDetails = $this->calculateSerialBreakdown($serials);

                $requiresSerial = ! empty($row['serial_number_required']);

                // A blind row's public state carries no bucket breakdown at
                // all (task 3.2): the row-level pre-check below is therefore
                // skipped for it and the operator's single requested_quantity
                // is trusted as pre-check input. Authoritative allocation and
                // stock-sufficiency validation always happens server-side in
                // TransferDraftService::buildProductsData regardless of this
                // pre-check, so this relaxation never bypasses real
                // enforcement -- it only changes which layer reports the
                // (neutral, for a blind user) error.
                $hasBucketState = array_key_exists('quantity_tax', $row)
                    || array_key_exists('quantity_non_tax', $row)
                    || array_key_exists('broken_quantity_tax', $row)
                    || array_key_exists('broken_quantity_non_tax', $row);

                if (! $hasBucketState && empty($serials)) {
                    if ($requiresSerial && empty($serials)) {
                        $tableErrors["products.{$i}.serial_numbers"] = 'Produk ini memerlukan nomor seri.';
                    }

                    if ($requestedQuantity <= 0) {
                        $tableErrors["products.{$i}"] = "Jumlah keseluruhan produk harus lebih besar dari 0.";
                    }

                    $preparedRows[] = array_merge($row, [
                        'serial_numbers'           => $serials,
                        'requested_quantity'       => $requestedQuantity,
                        'total'                    => $requestedQuantity,
                    ]);

                    continue;
                }

                $allocatedQuantities = [
                    'quantity_tax'            => max(0, (int) ($row['quantity_tax']            ?? 0)),
                    'quantity_non_tax'        => max(0, (int) ($row['quantity_non_tax']        ?? 0)),
                    'quantity_broken_tax'     => max(0, (int) ($row['broken_quantity_tax']     ?? 0)),
                    'quantity_broken_non_tax' => max(0, (int) ($row['broken_quantity_non_tax'] ?? 0)),
                ];

                $finalQuantities = $allocatedQuantities;

                if (! empty($serials)) {
                    if ($allocatedQuantities !== $serialDetails['quantities']) {
                        $tableErrors["products.{$i}.serial_numbers"] =
                            'Jumlah nomor seri tidak sesuai dengan rincian kuantitas yang dimasukkan.';
                    }

                    $finalQuantities = $serialDetails['quantities'];
                }

                if ($requiresSerial && empty($serials)) {
                    $tableErrors["products.{$i}.serial_numbers"] = 'Produk ini memerlukan nomor seri.';
                }

                $total = array_sum($finalQuantities);

                if ($total <= 0) {
                    $tableErrors["products.{$i}"] = "Jumlah keseluruhan produk harus lebih besar dari 0.";
                }

                if ($requestedQuantity > 0 && $total !== $requestedQuantity) {
                    $tableErrors["products.{$i}.requested_quantity"] = $canViewSystemStock
                        ? "Jumlah yang diminta ({$requestedQuantity}) tidak sesuai dengan alokasi stok ({$total})."
                        : "Jumlah yang dimasukkan untuk item ini tidak sesuai.";
                }

                $stock = $row['stock'] ?? [];

                if ($finalQuantities['quantity_tax'] > ($stock['quantity_tax'] ?? 0)) {
                    $available = $stock['quantity_tax'] ?? 0;
                    $tableErrors["products.{$i}.quantity_tax"] = $canViewSystemStock
                        ? "Jumlah Pajak tidak boleh lebih dari stok ({$available})."
                        : "Stok tidak mencukupi untuk item ini.";
                }
                if ($finalQuantities['quantity_non_tax'] > ($stock['quantity_non_tax'] ?? 0)) {
                    $available = $stock['quantity_non_tax'] ?? 0;
                    $tableErrors["products.{$i}.quantity_non_tax"] = $canViewSystemStock
                        ? "Jumlah Non Pajak tidak boleh lebih dari stok ({$available})."
                        : "Stok tidak mencukupi untuk item ini.";
                }
                if ($finalQuantities['quantity_broken_tax'] > ($stock['broken_quantity_tax'] ?? 0)) {
                    $available = $stock['broken_quantity_tax'] ?? 0;
                    $tableErrors["products.{$i}.broken_quantity_tax"] = $canViewSystemStock
                        ? "Rusak Pajak tidak boleh lebih dari stok rusak ({$available})."
                        : "Stok tidak mencukupi untuk item ini.";
                }
                if ($finalQuantities['quantity_broken_non_tax'] > ($stock['broken_quantity_non_tax'] ?? 0)) {
                    $available = $stock['broken_quantity_non_tax'] ?? 0;
                    $tableErrors["products.{$i}.broken_quantity_non_tax"] = $canViewSystemStock
                        ? "Rusak Non Pajak tidak boleh lebih dari stok rusak ({$available})."
                        : "Stok tidak mencukupi untuk item ini.";
                }

                // Show warning if tax stock is being used (requires return) -- privileged only
                if ($canViewSystemStock && ($finalQuantities['quantity_tax'] > 0 || $finalQuantities['quantity_broken_tax'] > 0)) {
                    $row['tax_warning'] = 'Stok pajak akan digunakan dan harus dikembalikan lintas lokasi.';
                }

                $preparedRows[] = array_merge($row, [
                    'serial_numbers'           => $serials,
                    'quantity_tax'             => $finalQuantities['quantity_tax'] ?? 0,
                    'quantity_non_tax'         => $finalQuantities['quantity_non_tax'] ?? 0,
                    'broken_quantity_tax'      => $finalQuantities['quantity_broken_tax'] ?? 0,
                    'broken_quantity_non_tax'  => $finalQuantities['quantity_broken_non_tax'] ?? 0,
                    'total'                    => $total,
                ]);
            }
        }

        // Merge table validation errors from the component state
        foreach ($this->tableValidationErrors as $key => $error) {
            $tableErrors[$key] = $error;
        }

        // abort on validation errors
        if (! empty($tableErrors) || ! empty($this->selfManagedValidationErrors)) {
            if (! empty($tableErrors)) {
                $this->dispatch('tableValidationErrors', $tableErrors);
            }

            return null;
        }

        return $preparedRows;
    }

    public function render()
    {
        return view('livewire.transfer.transfer-stock-form');
    }

    protected function notifyLocationChange(): void
    {
        $this->dispatch('locationsConfirmed', [
            'originLocationId'      => $this->originLocation,
            'destinationLocationId' => $this->destinationLocation,
        ]);
    }

    private function normalizeSerialPayload(array $serials): array
    {
        return collect($serials)
            ->map(function ($serial) {
                $id = (int) ($serial['id'] ?? 0);

                if ($id <= 0) {
                    return null;
                }

                $taxId   = $serial['tax_id'] ?? null;
                $taxable = array_key_exists('taxable', $serial)
                    ? (bool) $serial['taxable']
                    : ! empty($taxId);

                return [
                    'id'            => $id,
                    'serial_number' => $serial['serial_number'] ?? null,
                    'tax_id'        => $taxId !== null ? (int) $taxId : null,
                    'taxable'       => $taxable,
                    'is_broken'     => (bool) ($serial['is_broken'] ?? false),
                ];
            })
            ->filter()
            ->values()
            ->toArray();
    }

    private function calculateSerialBreakdown(array $serials): array
    {
        $quantityTax           = 0;
        $quantityNonTax        = 0;
        $brokenQuantityTax     = 0;
        $brokenQuantityNonTax  = 0;

        foreach ($serials as $serial) {
            $isBroken = (bool) ($serial['is_broken'] ?? false);
            $isTaxed  = array_key_exists('taxable', $serial)
                ? (bool) $serial['taxable']
                : ! empty($serial['tax_id']);

            if ($isBroken && $isTaxed) {
                $brokenQuantityTax++;
            } elseif ($isBroken && ! $isTaxed) {
                $brokenQuantityNonTax++;
            } elseif ($isTaxed) {
                $quantityTax++;
            } else {
                $quantityNonTax++;
            }
        }

        return [
            'quantities' => [
                'quantity_tax'            => $quantityTax,
                'quantity_non_tax'        => $quantityNonTax,
                'quantity_broken_tax'     => $brokenQuantityTax,
                'quantity_broken_non_tax' => $brokenQuantityNonTax,
            ],
            'total' => $quantityTax + $quantityNonTax + $brokenQuantityTax + $brokenQuantityNonTax,
        ];
    }
}
