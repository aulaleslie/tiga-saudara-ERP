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
            $key = PosReturnCreateForm::buildLineKey($line);
            $existing = $existingLines->get($key);

            if ($line['is_tracked'] && !empty($line['serial_number_ids'])) {
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
                ];
            } else {
                $this->lineSelections[$key] = [
                    'resolution' => $existing ? $existing->resolution : PosReturnLine::RESOLUTION_NONE,
                    'quantity' => $existing ? (float) $existing->quantity : 0,
                ];
            }
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
     */
    public function updateResolution(string $lineKey, string $resolution)
    {
        if (!isset($this->lineSelections[$lineKey])) return;

        $this->lineSelections[$lineKey]['resolution'] = $resolution;

        // Clear replacement serial if resolution is not product_replacement
        if ($resolution !== PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT) {
            if (isset($this->lineSelections[$lineKey]['replacement_serial_id'])) {
                $this->lineSelections[$lineKey]['replacement_serial_id'] = null;
                $this->lineSelections[$lineKey]['replacement_serial_input'] = '';
                $this->lineSelections[$lineKey]['replacement_serial_label'] = '';
            }
        }
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
     * Build submission lines from source-line-keyed selections.
     */
    protected function buildSubmissionLines(): array
    {
        $lines = [];
        foreach ($this->snapshot['lines'] as $line) {
            $key = PosReturnCreateForm::buildLineKey($line);
            $selection = $this->lineSelections[$key] ?? null;
            if (!$selection) continue;

            $resolution = $selection['resolution'] ?? PosReturnLine::RESOLUTION_NONE;
            $isSerial = $line['is_tracked'] && !empty($line['serial_number_ids']);

            if ($isSerial) {
                $lines[] = [
                    'sale_detail_id' => $line['sale_detail_id'],
                    'sale_id' => $line['sale_id'],
                    'pos_transaction_line_id' => $line['pos_transaction_line_id'] ?? null,
                    'returned_serial_id' => $line['serial_number_ids'][0],
                    'resolution' => $resolution,
                    'quantity' => 1,
                    'replacement_serial_id' => $selection['replacement_serial_id'] ?? null,
                ];
            } else {
                $quantity = (float) ($selection['quantity'] ?? 0);
                if ($resolution !== PosReturnLine::RESOLUTION_NONE && $quantity > 0) {
                    $lines[] = [
                        'sale_detail_id' => $line['sale_detail_id'],
                        'sale_id' => $line['sale_id'],
                        'pos_transaction_line_id' => $line['pos_transaction_line_id'] ?? null,
                        'returned_serial_id' => null,
                        'resolution' => $resolution,
                        'quantity' => $quantity,
                        'replacement_serial_id' => null,
                    ];
                }
            }
        }

        return $lines;
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

        // Validate replacement serials for product_replacement lines
        foreach ($this->snapshot['lines'] as $line) {
            $key = PosReturnCreateForm::buildLineKey($line);
            $selection = $this->lineSelections[$key] ?? null;
            if (!$selection) continue;

            if (($selection['resolution'] ?? '') === PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT
                && $line['is_tracked']
                && empty($selection['replacement_serial_id'])) {
                $this->addError("lineSelections.{$key}.replacement_serial_input", 'Serial pengganti harus diisi untuk penggantian produk.');
                return;
            }
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
