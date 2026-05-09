<?php

namespace Modules\Pos\Livewire\PosReturn;

use Livewire\Component;
use Modules\Pos\Services\PosReturnLookupService;
use Modules\Pos\Services\PosReturnSnapshotService;
use Modules\Pos\Services\PosReturnSubmissionService;
use Modules\Pos\Services\PosReturnReplacementGuard;
use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Entities\PosReturnLine;

class PosReturnCreateForm extends Component
{
    public $identifier = '';
    public $loading = false;
    public $error = null;
    public $snapshot = null;
    public $posTransactionId = null;
    public $posCheckoutId = null;

    /**
     * Source-line-keyed draft selections.
     *
     * For serial lines:  lineSelections["{sale_detail_id}-{serial_id}"] => [
     *   'resolution' => 'none'|'cash_return'|'product_replacement',
     *   'replacement_serial_id' => null|int,
     *   'replacement_serial_input' => '',
     *   'replacement_serial_label' => '',
     * ]
     *
     * For non-serial lines: lineSelections["{sale_detail_id}"] => [
     *   'resolution' => 'none'|'cash_return'|'product_replacement',
     *   'quantity' => 0,
     * ]
     */
    public $lineSelections = [];

    public function mount()
    {
        abort_if(\Illuminate\Support\Facades\Gate::denies('pos.returns.create'), 403);
    }

    /**
     * Build a unique line key for a snapshot line.
     */
    public static function buildLineKey(array $line): string
    {
        if ($line['is_tracked'] && !empty($line['serial_number_ids'])) {
            // Task 2.9: Key by POS line + Serial ID for source identity stability
            $prefix = $line['pos_transaction_line_id'] ?? $line['sale_detail_id'];
            return $prefix . '-' . $line['serial_number_ids'][0];
        }
        return (string) $line['sale_detail_id'];
    }

    public function lookup()
    {
        $this->validate([
            'identifier' => 'required|string|min:3',
        ]);

        $this->loading = true;
        $this->error = null;
        $this->snapshot = null;
        $this->lineSelections = [];

        try {
            $lookupService = app(PosReturnLookupService::class);
            $snapshotService = app(PosReturnSnapshotService::class);

            $result = $lookupService->lookup($this->identifier);

            if ($result) {
                $this->posTransactionId = $result['pos_transaction_id'];
                $this->posCheckoutId = $result['pos_checkout_id'];
                $this->snapshot = $snapshotService->build($this->posTransactionId);

                // Initialize source-line-keyed selections
                foreach ($this->snapshot['lines'] as $line) {
                    $key = self::buildLineKey($line);
                    if ($line['is_tracked'] && !empty($line['serial_number_ids'])) {
                        // Serial line: default resolution is 'none' (task 6.3)
                        $this->lineSelections[$key] = [
                            'resolution' => PosReturnLine::RESOLUTION_NONE,
                            'replacement_serial_id' => null,
                            'replacement_serial_input' => '',
                            'replacement_serial_label' => '',
                        ];
                    } else {
                        // Non-serial line
                        $this->lineSelections[$key] = [
                            'resolution' => PosReturnLine::RESOLUTION_NONE,
                            'quantity' => 0,
                        ];
                    }
                }
            } else {
                $this->error = 'Transaksi tidak ditemukan atau belum diposting.';
            }
        } catch (\Exception $e) {
            $this->error = 'Terjadi kesalahan: ' . $e->getMessage();
        } finally {
            $this->loading = false;
        }
    }

    /**
     * Update the resolution for a source line (task 6.4).
     * Clears replacement serial when changing away from product_replacement (task 4.5).
     */
    public function updateResolution(string $lineKey, string $resolution)
    {
        if (!isset($this->lineSelections[$lineKey])) return;

        $this->lineSelections[$lineKey]['resolution'] = $resolution;

        // Clear replacement serial if resolution is not product_replacement (task 4.5)
        if ($resolution !== PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT) {
            if (isset($this->lineSelections[$lineKey]['replacement_serial_id'])) {
                $this->lineSelections[$lineKey]['replacement_serial_id'] = null;
                $this->lineSelections[$lineKey]['replacement_serial_input'] = '';
                $this->lineSelections[$lineKey]['replacement_serial_label'] = '';
            }
        }
    }

    /**
     * Scan/lookup a replacement serial for a serial-tracked line (task 4.1, 6.5).
     */
    public function scanReplacementSerial(string $lineKey)
    {
        $input = trim($this->lineSelections[$lineKey]['replacement_serial_input'] ?? '');
        if (empty($input)) return;

        // Find the snapshot line for this key
        $line = $this->findSnapshotLineByKey($lineKey);
        if (!$line) return;

        try {
            $guard = app(PosReturnReplacementGuard::class);
            $returnedSerialId = $line['serial_number_ids'][0] ?? null;

            // Look up the replacement serial by serial number string
            $replacementSerial = \Modules\Product\Entities\ProductSerialNumber::where('serial_number', $input)->first();
            if (!$replacementSerial) {
                $this->addError("lineSelections.{$lineKey}.replacement_serial_input", "Serial number {$input} tidak ditemukan.");
                return;
            }

            // Validate via guard
            $guard->validateReplacementSerial(
                $line['product_id'],
                $replacementSerial->id,
                $returnedSerialId
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

    /**
     * Find the snapshot line matching a lineKey.
     */
    protected function findSnapshotLineByKey(string $lineKey): ?array
    {
        if (!$this->snapshot) return null;
        foreach ($this->snapshot['lines'] as $line) {
            if (self::buildLineKey($line) === $lineKey) {
                return $line;
            }
        }
        return null;
    }

    public function resetLookup()
    {
        $this->identifier = '';
        $this->snapshot = null;
        $this->error = null;
        $this->lineSelections = [];
    }

    public function getExistingSerialLineQuantity(int $saleDetailId, ?int $returnedSerialId): float
    {
        return 0.0;
    }

    public function getExistingNonSerialLineQuantity(int $saleDetailId): float
    {
        return 0.0;
    }

    /**
     * Build submission lines from source-line-keyed selections.
     */
    protected function buildSubmissionLines(): array
    {
        $lines = [];
        foreach ($this->snapshot['lines'] as $line) {
            $key = self::buildLineKey($line);
            $selection = $this->lineSelections[$key] ?? null;
            if (!$selection) continue;

            $resolution = $selection['resolution'] ?? PosReturnLine::RESOLUTION_NONE;
            $isSerial = $line['is_tracked'] && !empty($line['serial_number_ids']);

            if ($isSerial) {
                // Serial lines always submit with qty=1; skip 'none' resolution lines at service level
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
                // Non-serial lines
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

        // Validate that at least one has an actionable resolution
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

        // Validate replacement serials are set for product_replacement lines
        foreach ($this->snapshot['lines'] as $line) {
            $key = self::buildLineKey($line);
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
            $posReturn = $submissionService->store([
                'pos_transaction_id' => $this->posTransactionId,
                'return_option' => PosReturn::OPTION_CASH_RETURN,
                'source_snapshot' => $this->snapshot,
                'source_snapshot_hash' => $this->snapshot['hash'],
                'lines' => $lines,
            ]);

            toast('Retur POS berhasil disimpan sebagai draft.', 'success');
            return redirect()->route('pos.returns.show', $posReturn->id);
        } catch (\Exception $e) {
            $this->error = 'Gagal menyimpan retur: ' . $e->getMessage();
        }
    }

    /**
     * Group snapshot lines for display: group by sale_detail_id to show bundle/non-bundle grouping.
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
        return view('livewire.modules.pos.pos-return.create-form');
    }
}
