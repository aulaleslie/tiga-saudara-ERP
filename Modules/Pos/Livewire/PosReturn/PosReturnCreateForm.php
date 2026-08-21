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
     *   'replacement_reason' => '',
     * ]
     *
     * For non-serial lines: lineSelections["{sale_detail_id}"] => [
     *   'resolution' => 'none'|'cash_return'|'product_replacement',
     *   'quantity' => 0,
     *   'replacement_reason' => '',
     * ]
     *
     * Corrections/2: Bundle COMPONENT rows use the exact same lineSelections
     * structure, keyed via buildLineKey() applied to their bundle_items[]
     * snapshot entry (which now carries sale_detail_id/is_tracked/
     * serial_number_ids just like a top-level line). Components are never
     * offered 'cash_return' — only 'none'/'product_replacement' — enforced in
     * the blade (no cash button rendered) and implicitly by the service
     * (component-only cash_return is still rejected by completeness checks).
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

    /**
     * Corrections/2: A component's bundle_items[] entry aggregates ALL of its
     * dispatched serials in one 'serial_numbers' array (one entry per
     * component product). Each serial must be its own independently
     * selectable row (mirroring the parent's serial-lines table pattern), so
     * this expands a serial-tracked component entry into one line-shaped
     * array per serial — keyed identically to how the blade partial derives
     * its per-serial key (pos_transaction_line_id synthesized as the
     * component's own sale_detail_id so the key stays stable and distinct
     * from the parent's own serial rows).
     *
     * Non-serial components are returned as a single-element array (the
     * component entry itself is already line-shaped for buildLineKey()).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function explodeComponentLines(array $componentEntry): array
    {
        $isSerial = ($componentEntry['is_tracked'] ?? false) && !empty($componentEntry['serial_number_ids']);
        if (!$isSerial) {
            return [$componentEntry];
        }

        $lines = [];
        foreach ($componentEntry['serial_numbers'] ?? [] as $serial) {
            $lines[] = array_merge($componentEntry, [
                'pos_transaction_line_id' => $componentEntry['sale_detail_id'],
                'serial_number_ids' => [$serial['id']],
                'serial_numbers' => [$serial],
                'returnable_quantity' => $serial['returnable_quantity'] ?? 1,
            ]);
        }

        return $lines;
    }

    /**
     * Initialize a default lineSelections entry for a snapshot line OR a
     * bundle component entry (same shape) at 'none' resolution.
     */
    protected function initializeLineSelection(array $line): void
    {
        $key = self::buildLineKey($line);
        if (($line['is_tracked'] ?? false) && !empty($line['serial_number_ids'])) {
            $this->lineSelections[$key] = [
                'resolution' => PosReturnLine::RESOLUTION_NONE,
                'replacement_serial_id' => null,
                'replacement_serial_input' => '',
                'replacement_serial_label' => '',
                'replacement_reason' => '',
            ];
        } else {
            $this->lineSelections[$key] = [
                'resolution' => PosReturnLine::RESOLUTION_NONE,
                'quantity' => 0,
                'replacement_reason' => '',
            ];
        }
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
                    $this->initializeLineSelection($line);

                    // Corrections/2: also initialize independent selections for
                    // each bundle component row (and each of its serials, if
                    // serial-tracked).
                    foreach ($line['bundle_items'] ?? [] as $componentEntry) {
                        if (empty($componentEntry['sale_detail_id'])) continue;
                        foreach (self::explodeComponentLines($componentEntry) as $componentLine) {
                            $this->initializeLineSelection($componentLine);
                        }
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
     *
     * Corrections/2: component rows may never be set to cash_return — the
     * whole-bundle refund is automatic (see point 1). This is a defense in
     * depth guard; the blade never renders a cash button for component rows.
     */
    public function updateResolution(string $lineKey, string $resolution)
    {
        if (!isset($this->lineSelections[$lineKey])) return;

        if ($resolution === PosReturnLine::RESOLUTION_CASH_RETURN && $this->isComponentLineKey($lineKey)) {
            return;
        }

        $this->lineSelections[$lineKey]['resolution'] = $resolution;

        // Clear replacement serial if resolution is not product_replacement (task 4.5)
        if ($resolution !== PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT) {
            if (isset($this->lineSelections[$lineKey]['replacement_serial_id'])) {
                $this->lineSelections[$lineKey]['replacement_serial_id'] = null;
                $this->lineSelections[$lineKey]['replacement_serial_input'] = '';
                $this->lineSelections[$lineKey]['replacement_serial_label'] = '';
            }
        }

        if ($resolution !== PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT) {
            $this->lineSelections[$lineKey]['replacement_reason'] = '';
        }
    }

    /**
     * True if $lineKey identifies a bundle component row (found inside some
     * top-level line's bundle_items[]) rather than a top-level line.
     */
    protected function isComponentLineKey(string $lineKey): bool
    {
        if (!$this->snapshot) return false;

        foreach ($this->snapshot['lines'] as $line) {
            if (self::buildLineKey($line) === $lineKey) {
                return false;
            }
            foreach ($line['bundle_items'] ?? [] as $componentEntry) {
                if (empty($componentEntry['sale_detail_id'])) continue;
                foreach (self::explodeComponentLines($componentEntry) as $componentLine) {
                    if (self::buildLineKey($componentLine) === $lineKey) {
                        return true;
                    }
                }
            }
        }

        return false;
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

            if ($this->replacementSerialAlreadySelected($lineKey, $replacementSerial->id)) {
                $this->addError("lineSelections.{$lineKey}.replacement_serial_input", 'Serial pengganti tidak boleh digunakan lebih dari satu kali dalam retur yang sama.');
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
     * Find the snapshot line matching a lineKey — searches both top-level
     * lines and (Corrections/2) each top-level line's bundle_items[] entries,
     * since components are keyed/selected via the same lineSelections shape.
     */
    protected function findSnapshotLineByKey(string $lineKey): ?array
    {
        if (!$this->snapshot) return null;
        foreach ($this->snapshot['lines'] as $line) {
            if (self::buildLineKey($line) === $lineKey) {
                return $line;
            }
            foreach ($line['bundle_items'] ?? [] as $componentEntry) {
                if (empty($componentEntry['sale_detail_id'])) continue;
                foreach (self::explodeComponentLines($componentEntry) as $componentLine) {
                    if (self::buildLineKey($componentLine) === $lineKey) {
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
     * Build one submission line array from a snapshot-shaped line (top-level
     * OR a bundle component entry — both share the same shape) and its
     * lineSelections entry. Returns null when there is nothing to submit.
     */
    protected function buildSubmissionLineFor(array $line, array $selection): ?array
    {
        $resolution = $selection['resolution'] ?? PosReturnLine::RESOLUTION_NONE;
        $isSerial = ($line['is_tracked'] ?? false) && !empty($line['serial_number_ids']);

        if ($isSerial) {
            // Serial lines are always emitted (even at 'none') so an explicit
            // reversion back to 'none' on edit is persisted rather than
            // silently dropped — matches pre-existing behavior where the
            // service (not the form) decides whether a 'none' resolution
            // line is actionable.
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
     * Component cash_return is never emitted here (the blade never offers it
     * and updateResolution() blocks it) — whole-bundle cash_return coverage is
     * synthesized server-side from the parent line alone (point 1).
     */
    protected function buildSubmissionLines(): array
    {
        $lines = [];
        foreach ($this->snapshot['lines'] as $line) {
            $key = self::buildLineKey($line);
            $selection = $this->lineSelections[$key] ?? null;
            if ($selection) {
                $built = $this->buildSubmissionLineFor($line, $selection);
                if ($built) $lines[] = $built;
            }

            foreach ($line['bundle_items'] ?? [] as $componentEntry) {
                if (empty($componentEntry['sale_detail_id'])) continue;
                foreach (self::explodeComponentLines($componentEntry) as $componentLine) {
                    $componentKey = self::buildLineKey($componentLine);
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
            foreach (self::explodeComponentLines($componentEntry) as $componentLine) {
                $candidates[] = $componentLine;
            }
        }

        return $candidates;
    }

    /**
     * Validate replacement_serial_id / replacement_reason for every
     * product_replacement selection (top-level and component). Returns an
     * error message keyed by lineKey, or null when all valid.
     */
    protected function validateReplacementSelections(): ?array
    {
        foreach ($this->snapshot['lines'] as $line) {
            foreach ($this->candidateLinesFor($line) as $candidateLine) {
                $key = self::buildLineKey($candidateLine);
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

        // Validate replacement serials/reasons are set for product_replacement lines
        $replacementError = $this->validateReplacementSelections();
        if ($replacementError) {
            [$errorKey, $errorMessage] = $replacementError;
            $this->addError("lineSelections.{$errorKey}", $errorMessage);
            return;
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
