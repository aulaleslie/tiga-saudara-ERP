<?php

namespace Modules\Pos\Livewire\PosReturn;

use Livewire\Component;
use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Entities\PosReturnLine;
use Modules\Pos\Services\PosReturnSnapshotService;
use Modules\Pos\Services\PosReturnSubmissionService;
use Modules\Pos\Services\PosReturnReplacementGuard;

class PosReturnEditForm extends Component
{
    public PosReturn $return;
    public $snapshot = null;
    public $error = null;
    public array $existingSerialLineQuantities = [];
    public array $existingNonSerialLineQuantities = [];

    /**
     * Source-line-keyed draft selections (same structure as create form).
     */
    public $lineSelections = [];

    public function mount(PosReturn $return)
    {
        abort_if(\Illuminate\Support\Facades\Gate::denies('pos.returns.edit'), 403);

        if (!$return->isRevisionEditable()) {
            abort(403, 'Hanya retur draft atau ditolak yang dapat diubah.');
        }

        $this->return = $return;

        $lines = $return->lines()->get();
        foreach ($lines as $line) {
            $this->existingNonSerialLineQuantities[(string) $line->sale_detail_id] =
                ($this->existingNonSerialLineQuantities[(string) $line->sale_detail_id] ?? 0.0) + (float) $line->quantity;

            if ($line->returned_serial_id) {
                $serialKey = $this->buildSerialQuantityKey((int) $line->sale_detail_id, (int) $line->returned_serial_id);
                $this->existingSerialLineQuantities[$serialKey] =
                    ($this->existingSerialLineQuantities[$serialKey] ?? 0.0) + (float) $line->quantity;
            }
        }

        $snapshotService = app(PosReturnSnapshotService::class);
        $this->snapshot = $snapshotService->build($return->pos_transaction_id);

        // Build a lookup of existing lines keyed by (sale_detail_id/pos_transaction_line_id, returned_serial_id)
        $existingLines = $lines->keyBy(function ($line) {
            if ($line->returned_serial_id) {
                $prefix = $line->pos_transaction_line_id ?? $line->sale_detail_id;
                return $prefix . '-' . $line->returned_serial_id;
            }
            return (string) $line->sale_detail_id;
        });

        // Initialize source-line-keyed selections from snapshot + existing lines
        foreach ($this->snapshot['lines'] as $line) {
            $this->initializeLineSelectionFromExisting($line, $existingLines);

            // Corrections/2: also initialize independent selections for each
            // bundle component row, hydrated from any existing persisted
            // component line (e.g. a prior product_replacement on a component;
            // synthesized cash_return component lines are intentionally NOT
            // re-surfaced as editable component selections since cash_return
            // is not a selectable component resolution).
            foreach ($line['bundle_items'] ?? [] as $componentEntry) {
                if (empty($componentEntry['sale_detail_id'])) continue;
                foreach (PosReturnCreateForm::explodeComponentLines($componentEntry) as $componentLine) {
                    $this->initializeLineSelectionFromExisting($componentLine, $existingLines);
                }
            }
        }
    }

    /**
     * Initialize (or hydrate from an existing persisted line) a lineSelections
     * entry for a snapshot-shaped line — top-level or bundle component (same
     * shape, see PosReturnSnapshotService::buildComponentBundleItemEntry()).
     */
    protected function initializeLineSelectionFromExisting(array $line, $existingLines): void
    {
        $key = PosReturnCreateForm::buildLineKey($line);
        $existing = $existingLines->get($key);

        if (($line['is_tracked'] ?? false) && !empty($line['serial_number_ids'])) {
            $replacementLabel = '';
            if ($existing && $existing->replacement_serial_id) {
                $replacementSerial = \Modules\Product\Entities\ProductSerialNumber::find($existing->replacement_serial_id);
                $replacementLabel = $replacementSerial ? $replacementSerial->serial_number : '';
            }

            $this->lineSelections[$key] = [
                'resolution' => $existing ? $existing->resolution : PosReturnLine::RESOLUTION_NONE,
                'replacement_serial_id' => $existing ? $existing->replacement_serial_id : null,
                'replacement_serial_input' => '',
                'replacement_serial_label' => $replacementLabel,
                'replacement_reason' => $existing ? (string) data_get($existing->line_meta, 'replacement_reason', '') : '',
            ];
        } else {
            $this->lineSelections[$key] = [
                'resolution' => $existing ? $existing->resolution : PosReturnLine::RESOLUTION_NONE,
                'quantity' => $existing ? (float) $existing->quantity : 0,
                'replacement_reason' => $existing ? (string) data_get($existing->line_meta, 'replacement_reason', '') : '',
            ];
        }
    }

    protected function buildSerialQuantityKey(int $saleDetailId, int $returnedSerialId): string
    {
        return $saleDetailId . ':' . $returnedSerialId;
    }

    public function getExistingSerialLineQuantity(int $saleDetailId, ?int $returnedSerialId): float
    {
        if (!$returnedSerialId) {
            return 0.0;
        }

        return (float) ($this->existingSerialLineQuantities[$this->buildSerialQuantityKey($saleDetailId, $returnedSerialId)] ?? 0.0);
    }

    public function getExistingNonSerialLineQuantity(int $saleDetailId): float
    {
        return (float) ($this->existingNonSerialLineQuantities[(string) $saleDetailId] ?? 0.0);
    }

    /**
     * Update the resolution for a source line.
     *
     * Corrections/2: component rows may never be set to cash_return — the
     * whole-bundle refund is automatic (see PosReturnSubmissionService point
     * 1). Defense in depth; the blade never renders a cash button for
     * component rows.
     */
    public function updateResolution(string $lineKey, string $resolution)
    {
        if (!isset($this->lineSelections[$lineKey])) return;

        if ($resolution === PosReturnLine::RESOLUTION_CASH_RETURN && $this->isComponentLineKey($lineKey)) {
            return;
        }

        $this->lineSelections[$lineKey]['resolution'] = $resolution;

        // Clear replacement serial if resolution is not product_replacement
        if ($resolution !== PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT) {
            if (isset($this->lineSelections[$lineKey]['replacement_serial_id'])) {
                $this->lineSelections[$lineKey]['replacement_serial_id'] = null;
                $this->lineSelections[$lineKey]['replacement_serial_input'] = '';
                $this->lineSelections[$lineKey]['replacement_serial_label'] = '';
            }
            $this->lineSelections[$lineKey]['replacement_reason'] = '';
        }
    }

    /**
     * True if $lineKey identifies a bundle component row rather than a
     * top-level line.
     */
    protected function isComponentLineKey(string $lineKey): bool
    {
        if (!$this->snapshot) return false;

        foreach ($this->snapshot['lines'] as $line) {
            if (PosReturnCreateForm::buildLineKey($line) === $lineKey) {
                return false;
            }
            foreach ($line['bundle_items'] ?? [] as $componentEntry) {
                if (empty($componentEntry['sale_detail_id'])) continue;
                foreach (PosReturnCreateForm::explodeComponentLines($componentEntry) as $componentLine) {
                    if (PosReturnCreateForm::buildLineKey($componentLine) === $lineKey) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Scan/lookup a replacement serial for a serial-tracked line.
     */
    public function scanReplacementSerial(string $lineKey)
    {
        $input = trim($this->lineSelections[$lineKey]['replacement_serial_input'] ?? '');
        if (empty($input)) return;

        $line = $this->findSnapshotLineByKey($lineKey);
        if (!$line) return;

        try {
            $guard = app(PosReturnReplacementGuard::class);
            $returnedSerialId = $line['serial_number_ids'][0] ?? null;

            $replacementSerial = \Modules\Product\Entities\ProductSerialNumber::where('serial_number', $input)->first();
            if (!$replacementSerial) {
                $this->addError("lineSelections.{$lineKey}.replacement_serial_input", "Serial number {$input} tidak ditemukan.");
                return;
            }

            if ($this->replacementSerialAlreadySelected($lineKey, $replacementSerial->id)) {
                $this->addError("lineSelections.{$lineKey}.replacement_serial_input", 'Serial pengganti tidak boleh digunakan lebih dari satu kali dalam retur yang sama.');
                return;
            }

            $guard->validateReplacementSerial(
                $line['product_id'],
                $replacementSerial->id,
                $returnedSerialId,
                $this->return->id
            );

            $this->lineSelections[$lineKey]['replacement_serial_id'] = $replacementSerial->id;
            $this->lineSelections[$lineKey]['replacement_serial_label'] = $replacementSerial->serial_number;
            $this->lineSelections[$lineKey]['replacement_serial_input'] = '';
            $this->resetErrorBag("lineSelections.{$lineKey}.replacement_serial_input");
        } catch (\Illuminate\Validation\ValidationException $e) {
            $messages = $e->validator->errors()->all();
            $this->addError("lineSelections.{$lineKey}.replacement_serial_input", implode(' ', $messages));
        } catch (\Exception $e) {
            $this->addError("lineSelections.{$lineKey}.replacement_serial_input", $e->getMessage());
        }

        $this->lineSelections[$lineKey]['replacement_serial_input'] = '';
    }

    /**
     * Clear a previously selected replacement serial.
     */
    public function clearReplacementSerial(string $lineKey)
    {
        if (!isset($this->lineSelections[$lineKey])) return;
        $this->lineSelections[$lineKey]['replacement_serial_id'] = null;
        $this->lineSelections[$lineKey]['replacement_serial_label'] = '';
        $this->lineSelections[$lineKey]['replacement_serial_input'] = '';
    }

    protected function findSnapshotLineByKey(string $lineKey): ?array
    {
        if (!$this->snapshot) return null;
        foreach ($this->snapshot['lines'] as $line) {
            if (PosReturnCreateForm::buildLineKey($line) === $lineKey) {
                return $line;
            }
            foreach ($line['bundle_items'] ?? [] as $componentEntry) {
                if (empty($componentEntry['sale_detail_id'])) continue;
                foreach (PosReturnCreateForm::explodeComponentLines($componentEntry) as $componentLine) {
                    if (PosReturnCreateForm::buildLineKey($componentLine) === $lineKey) {
                        return $componentLine;
                    }
                }
            }
        }
        return null;
    }

    protected function replacementSerialAlreadySelected(string $lineKey, int $replacementSerialId): bool
    {
        foreach ($this->lineSelections as $selectedLineKey => $selection) {
            if ($selectedLineKey === $lineKey) {
                continue;
            }

            if (($selection['replacement_serial_id'] ?? null) === $replacementSerialId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build one submission line array from a snapshot-shaped line (top-level
     * OR a bundle component entry) and its lineSelections entry.
     */
    protected function buildSubmissionLineFor(array $line, array $selection): ?array
    {
        $resolution = $selection['resolution'] ?? PosReturnLine::RESOLUTION_NONE;
        $isSerial = ($line['is_tracked'] ?? false) && !empty($line['serial_number_ids']);

        if ($isSerial) {
            // Serial lines are always emitted (even at 'none') so an explicit
            // reversion back to 'none' on edit is persisted rather than
            // silently dropped.
            return [
                'sale_detail_id' => $line['sale_detail_id'],
                'sale_id' => $line['sale_id'] ?? null,
                'pos_transaction_line_id' => $line['pos_transaction_line_id'] ?? null,
                'returned_serial_id' => $line['serial_number_ids'][0],
                'resolution' => $resolution,
                'quantity' => 1,
                'replacement_serial_id' => $selection['replacement_serial_id'] ?? null,
                'replacement_reason' => $selection['replacement_reason'] ?? null,
            ];
        }

        $quantity = (float) ($selection['quantity'] ?? 0);
        if ($resolution === PosReturnLine::RESOLUTION_NONE || $quantity <= 0) {
            return null;
        }

        return [
            'sale_detail_id' => $line['sale_detail_id'],
            'sale_id' => $line['sale_id'] ?? null,
            'pos_transaction_line_id' => $line['pos_transaction_line_id'] ?? null,
            'returned_serial_id' => null,
            'resolution' => $resolution,
            'quantity' => $quantity,
            'replacement_serial_id' => null,
            'replacement_reason' => $selection['replacement_reason'] ?? null,
        ];
    }

    /**
     * Build submission lines from source-line-keyed selections.
     *
     * Corrections/2: also iterates each top-level line's bundle_items[] so
     * independently-selected component product_replacement intent is emitted.
     */
    protected function buildSubmissionLines(): array
    {
        $lines = [];
        foreach ($this->snapshot['lines'] as $line) {
            $key = PosReturnCreateForm::buildLineKey($line);
            $selection = $this->lineSelections[$key] ?? null;
            if ($selection) {
                $built = $this->buildSubmissionLineFor($line, $selection);
                if ($built) $lines[] = $built;
            }

            foreach ($line['bundle_items'] ?? [] as $componentEntry) {
                if (empty($componentEntry['sale_detail_id'])) continue;
                foreach (PosReturnCreateForm::explodeComponentLines($componentEntry) as $componentLine) {
                    $componentKey = PosReturnCreateForm::buildLineKey($componentLine);
                    $componentSelection = $this->lineSelections[$componentKey] ?? null;
                    if (!$componentSelection) continue;

                    $built = $this->buildSubmissionLineFor($componentLine, $componentSelection);
                    if ($built) $lines[] = $built;
                }
            }
        }

        return $lines;
    }

    /**
     * Corrections/2: Flatten every candidate line (top-level + every exploded
     * component/component-serial line) for a snapshot line into one array.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function candidateLinesFor(array $line): array
    {
        $candidates = [$line];
        foreach ($line['bundle_items'] ?? [] as $componentEntry) {
            if (empty($componentEntry['sale_detail_id'])) continue;
            foreach (PosReturnCreateForm::explodeComponentLines($componentEntry) as $componentLine) {
                $candidates[] = $componentLine;
            }
        }

        return $candidates;
    }

    /**
     * Validate replacement_serial_id / replacement_reason for every
     * product_replacement selection (top-level and component).
     */
    protected function validateReplacementSelections(): ?array
    {
        foreach ($this->snapshot['lines'] as $line) {
            foreach ($this->candidateLinesFor($line) as $candidateLine) {
                $key = PosReturnCreateForm::buildLineKey($candidateLine);
                $selection = $this->lineSelections[$key] ?? null;
                if (!$selection) continue;

                if (($selection['resolution'] ?? '') !== PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT) {
                    continue;
                }

                $isSerial = ($candidateLine['is_tracked'] ?? false) && !empty($candidateLine['serial_number_ids']);

                if ($isSerial && empty($selection['replacement_serial_id'])) {
                    return [$key . '.replacement_serial_input', 'Serial pengganti harus diisi untuk penggantian produk.'];
                }

                if (!$isSerial && trim((string) ($selection['replacement_reason'] ?? '')) === '') {
                    return [$key . '.replacement_reason', 'Alasan penggantian wajib diisi untuk penggantian produk non-serial.'];
                }
            }
        }

        return null;
    }

    public function submit()
    {
        $lines = $this->buildSubmissionLines();

        if (empty($lines)) {
            $this->addError('lineSelections', 'Pilih setidaknya satu item untuk diretur.');
            return;
        }

        $hasActionable = false;
        foreach ($lines as $line) {
            if (in_array($line['resolution'], [PosReturnLine::RESOLUTION_CASH_RETURN, PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT])) {
                $hasActionable = true;
                break;
            }
        }

        if (!$hasActionable) {
            $this->addError('lineSelections', 'Minimal satu item harus dipilih untuk retur (ganti produk atau uang kembali).');
            return;
        }

        // Validate replacement serials/reasons for product_replacement lines
        $replacementError = $this->validateReplacementSelections();
        if ($replacementError) {
            [$errorKey, $errorMessage] = $replacementError;
            $this->addError("lineSelections.{$errorKey}", $errorMessage);
            return;
        }

        try {
            $submissionService = app(PosReturnSubmissionService::class);
            $submissionService->update($this->return, [
                'source_snapshot_hash' => $this->snapshot['hash'],
                'lines' => $lines,
            ]);

            toast('Retur POS berhasil diperbarui.', 'success');
            return redirect()->route('pos.returns.show', $this->return->id);
        } catch (\Exception $e) {
            $this->error = 'Gagal memperbarui retur: ' . $e->getMessage();
        }
    }

    /**
     * Group snapshot lines for display (reuses the same logic as create form).
     */
    public function getGroupedLinesProperty(): array
    {
        if (!$this->snapshot) return [];

        $groups = [];
        foreach ($this->snapshot['lines'] as $line) {
            // Task 6.10: Hide zero-quantity split bundle component allocation rows
            if ($line['is_zero_qty_component'] ?? false) continue;

            // Task 6.9: Group by original POS transaction line if available
            $groupKey = $line['pos_transaction_line_id'] ?? $line['sale_detail_id'];
            
            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'pos_transaction_line_id' => $line['pos_transaction_line_id'] ?? null,
                    'sale_detail_id' => $line['sale_detail_id'],
                    'product_name' => $line['product_name'],
                    'product_code' => $line['product_code'],
                    'product_id' => $line['product_id'],
                    'is_bundle' => $line['is_bundle'],
                    'is_tracked' => $line['is_tracked'],
                    'bundle_items' => $line['bundle_items'] ?? [],
                    'unit_price' => $line['unit_price'],
                    'serial_lines' => [],
                    'non_serial_line' => null,
                ];
            }

            if ($line['is_tracked'] && !empty($line['serial_number_ids'])) {
                $groups[$groupKey]['serial_lines'][] = $line;
            } else {
                $groups[$groupKey]['non_serial_line'] = $line;
            }
        }

        return array_values($groups);
    }

    public function getComponentAvailability($productId, $checkoutSaleId)
    {
        if (!$productId || !$checkoutSaleId || !$this->snapshot) return 0;

        $owner = collect($this->snapshot['owners'] ?? [])->firstWhere('checkout_sale_id', $checkoutSaleId);
        if (!$owner) return 0;

        $settingId = $owner['source_setting_id'] ?? null;
        if (!$settingId) return 0;

        // Use SalesLocationResolver to aggregate stock across all locations for the
        // source setting, matching the same scope POS uses when checking availability.
        $allowedLocationIds = \App\Support\SalesLocationResolver::resolveLocationIds($settingId)
            ->filter(fn ($id) => (int) $id > 0)
            ->values()
            ->all();

        if (empty($allowedLocationIds)) return 0;

        return (float) \Modules\Product\Entities\ProductStock::where('product_id', $productId)
            ->whereIn('location_id', $allowedLocationIds)
            ->sum('quantity');
    }

    public function render()
    {
        return view('livewire.modules.pos.pos-return.edit-form');
    }
}
