<?php

namespace Modules\Pos\Livewire\PosReturn\Concerns;

use App\Support\SalesReturn\SerialRegistration\PendingSerialValidator;
use Modules\Pos\Entities\PosReturnLine;
use Modules\Pos\Services\PosReturnReplacementGuard;

/**
 * Create/Edit entry of proposed (unknown) serials for POS quantity-style lines.
 *
 * lineSelections[key]['pending_serials'][] = ['serial_number', 'replacement_serial_id', 'replacement_serial_label']
 *
 * This is draft state only. The submission service re-derives eligibility from persisted fulfillment,
 * and the serial is created only when receiving commits.
 */
trait ManagesPendingSerials
{
    /** Whole-bundle components: "carrierSaleDetailId:componentProductId" => proposed serial texts. */
    public array $componentPendingSerials = [];

    public array $componentPendingInput = [];

    /** Whole-bundle REPLACEMENT: "carrierSaleDetailId:componentProductId" => unit index => ['id' => int, 'label' => string]. */
    public array $componentReplacementSerials = [];

    /** "key.index" => text being typed for that unit's replacement serial. */
    public array $componentReplacementInput = [];

    public function addComponentPendingSerial(string $key): void
    {
        $this->resetErrorBag("componentPendingInput.{$key}");
        [$carrierId, $productId] = array_pad(array_map('intval', explode(':', $key)), 2, 0);

        $component = null;
        foreach ($this->snapshot['lines'] ?? [] as $line) {
            foreach ($line['bundle_items'] ?? [] as $entry) {
                if ((int) ($entry['sale_detail_id'] ?? 0) === $carrierId && (int) ($entry['product_id'] ?? 0) === $productId) {
                    $component = $entry;
                }
            }
        }
        if (! $component || empty($component['registration_eligible'])) {
            $this->addError("componentPendingInput.{$key}", 'Nomor seri baru tidak dapat didaftarkan untuk komponen ini.');
            return;
        }

        $validator = app(PendingSerialValidator::class);
        $text = $validator->normalize((string) ($this->componentPendingInput[$key] ?? ''));

        $proposals = [];
        foreach ($this->componentPendingSerials as $otherKey => $texts) {
            $otherProduct = (int) (explode(':', (string) $otherKey)[1] ?? 0);
            foreach ($texts as $i => $existing) {
                $proposals[] = ['product_id' => $otherProduct, 'serial' => $existing, 'key' => "{$otherKey}.{$i}"];
            }
        }
        foreach ($this->lineSelections as $otherKey => $selection) {
            $other = $this->findSnapshotLineByKey((string) $otherKey);
            foreach ($selection['pending_serials'] ?? [] as $i => $pending) {
                $proposals[] = ['product_id' => (int) ($other['product_id'] ?? 0), 'serial' => (string) $pending['serial_number'], 'key' => "line.{$otherKey}.{$i}"];
            }
        }
        $proposals[] = ['product_id' => $productId, 'serial' => $text, 'key' => 'new'];

        $errors = $validator->validate($proposals);
        if (isset($errors['new'])) {
            $this->addError("componentPendingInput.{$key}", match ($errors['new']) {
                PendingSerialValidator::ERROR_EMPTY => 'Nomor seri wajib diisi.',
                PendingSerialValidator::ERROR_TOO_LONG => 'Nomor seri terlalu panjang.',
                PendingSerialValidator::ERROR_DUPLICATE => 'Nomor seri ini sudah ditambahkan.',
                default => 'Nomor seri sudah terdaftar. Gunakan nomor seri yang sudah ada melalui pencarian.',
            });
            return;
        }

        $this->componentPendingSerials[$key][] = $text;
        $this->componentPendingInput[$key] = '';
    }

    public function removeComponentPendingSerial(string $key, int $index): void
    {
        if (! isset($this->componentPendingSerials[$key][$index])) {
            return;
        }
        unset($this->componentPendingSerials[$key][$index]);
        $this->componentPendingSerials[$key] = array_values($this->componentPendingSerials[$key]);

        // Replacement serials are paired with units by position: removing a unit removes its pairing too.
        if (isset($this->componentReplacementSerials[$key])) {
            unset($this->componentReplacementSerials[$key][$index]);
            $this->componentReplacementSerials[$key] = array_values($this->componentReplacementSerials[$key]);
        }
        $this->componentReplacementInput = [];
    }

    /** Existing replacement lookup (same product + same guard as every other serialized replacement), for one component unit. */
    public function scanComponentReplacementSerial(string $key, int $index): void
    {
        $field = "componentReplacementInput.{$key}.{$index}";
        $this->resetErrorBag($field);
        [$carrierId, $productId] = array_pad(array_map('intval', explode(':', $key)), 2, 0);

        if (! isset($this->componentPendingSerials[$key][$index])) {
            $this->addError($field, 'Isi nomor seri asli unit ini terlebih dahulu.');
            return;
        }

        $input = trim((string) ($this->componentReplacementInput[$key][$index] ?? ''));
        if ($input === '') {
            $this->addError($field, 'Nomor seri pengganti wajib diisi.');
            return;
        }

        $serial = \Modules\Product\Entities\ProductSerialNumber::query()
            ->where('product_id', $productId)->where('serial_number', \Modules\Product\Entities\ProductSerialNumber::normalize($input))->first();
        if (! $serial) {
            $this->addError($field, "Serial {$input} tidak ditemukan untuk komponen ini.");
            return;
        }

        if ($this->replacementSerialAlreadyUsed($serial->id, $key, $index)) {
            $this->addError($field, 'Serial pengganti tidak boleh digunakan lebih dari satu kali dalam retur yang sama.');
            return;
        }

        try {
            app(PosReturnReplacementGuard::class)->validateReplacementSerial($productId, $serial->id, null, $this->return->id ?? null);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->addError($field, implode(' ', $e->validator->errors()->all()));
            return;
        }

        $this->componentReplacementSerials[$key][$index] = ['id' => $serial->id, 'label' => $serial->serial_number];
        $this->componentReplacementInput[$key][$index] = '';
    }

    public function clearComponentReplacementSerial(string $key, int $index): void
    {
        unset($this->componentReplacementSerials[$key][$index]);
    }

    protected function replacementSerialAlreadyUsed(int $serialId, ?string $exceptKey = null, ?int $exceptIndex = null): bool
    {
        foreach ($this->lineSelections as $selection) {
            if ((int) ($selection['replacement_serial_id'] ?? 0) === $serialId) {
                return true;
            }
            foreach ($selection['pending_serials'] ?? [] as $pending) {
                if ((int) ($pending['replacement_serial_id'] ?? 0) === $serialId) {
                    return true;
                }
            }
        }
        foreach ($this->componentReplacementSerials as $key => $units) {
            foreach ($units as $i => $unit) {
                if ((int) ($unit['id'] ?? 0) === $serialId && ! ($key === $exceptKey && $i === $exceptIndex)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Snapshot parent line (selection key + selection) that hosts the given component registration key. */
    protected function componentParentSelection(string $key): ?array
    {
        [$carrierId, $productId] = array_pad(array_map('intval', explode(':', $key)), 2, 0);
        foreach ($this->snapshot['lines'] ?? [] as $line) {
            foreach ($line['bundle_items'] ?? [] as $entry) {
                if ((int) ($entry['sale_detail_id'] ?? 0) === $carrierId && (int) ($entry['product_id'] ?? 0) === $productId) {
                    return $this->lineSelections[\Modules\Pos\Livewire\PosReturn\PosReturnCreateForm::buildLineKey($line)] ?? null;
                }
            }
        }

        return null;
    }

    public function isComponentReplacementMode(string $key): bool
    {
        $parent = $this->componentParentSelection($key);

        return $parent !== null
            && ($parent['resolution'] ?? null) === PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT
            && ($parent['bundle_return_scope'] ?? PosReturnLine::BUNDLE_RETURN_SCOPE_WHOLE_BUNDLE) === PosReturnLine::BUNDLE_RETURN_SCOPE_WHOLE_BUNDLE;
    }

    /**
     * Submission payload: component key => replacement serial ids (one per proposed unit). Sent only for components whose
     * parent bundle line is a whole-bundle REPLACEMENT; cash-return drafts never carry replacement serials.
     *
     * @return array<string,list<int>>
     */
    protected function componentReplacementPayload(): array
    {
        $payload = [];
        foreach ($this->componentPendingSerials as $key => $texts) {
            if ($texts === [] || ! $this->isComponentReplacementMode((string) $key)) {
                continue;
            }
            $ids = [];
            foreach (array_keys($texts) as $i) {
                $ids[] = (int) ($this->componentReplacementSerials[$key][$i]['id'] ?? 0);
            }
            if (array_filter($ids)) {
                $payload[(string) $key] = $ids;
            }
        }

        return $payload;
    }

    /** @return array{0:string,1:string}|null [field, message] of the first incomplete component replacement */
    protected function validateComponentReplacements(): ?array
    {
        foreach ($this->componentPendingSerials as $key => $texts) {
            if ($texts === [] || ! $this->isComponentReplacementMode((string) $key)) {
                continue;
            }
            foreach (array_keys($texts) as $i) {
                if (empty($this->componentReplacementSerials[$key][$i]['id'])) {
                    $ordinal = $i + 1;

                    return ["componentReplacementInput.{$key}.{$i}", "Serial pengganti wajib diisi untuk unit komponen #{$ordinal}."];
                }
            }
        }

        return null;
    }

    public function addPendingSerial(string $lineKey): void
    {
        $selection = $this->lineSelections[$lineKey] ?? null;
        $line = $this->findSnapshotLineByKey($lineKey);
        if (! $selection || ! $line) {
            return;
        }

        $this->resetErrorBag("lineSelections.{$lineKey}.pending_input");

        if (empty($line['registration_eligible'])) {
            $this->addError("lineSelections.{$lineKey}.pending_input", 'Nomor seri baru tidak dapat didaftarkan untuk baris ini.');
            return;
        }

        $validator = app(PendingSerialValidator::class);
        $text = $validator->normalize((string) ($selection['pending_input'] ?? ''));

        $proposals = [];
        foreach ($this->lineSelections as $otherKey => $otherSelection) {
            $otherLine = $this->findSnapshotLineByKey((string) $otherKey);
            foreach ($otherSelection['pending_serials'] ?? [] as $i => $pending) {
                $proposals[] = ['product_id' => (int) ($otherLine['product_id'] ?? 0), 'serial' => (string) $pending['serial_number'], 'key' => "{$otherKey}.{$i}"];
            }
        }
        $proposals[] = ['product_id' => (int) $line['product_id'], 'serial' => $text, 'key' => 'new'];

        $errors = $validator->validate($proposals);
        if (isset($errors['new'])) {
            $this->addError("lineSelections.{$lineKey}.pending_input", match ($errors['new']) {
                PendingSerialValidator::ERROR_EMPTY => 'Nomor seri wajib diisi.',
                PendingSerialValidator::ERROR_TOO_LONG => 'Nomor seri terlalu panjang.',
                PendingSerialValidator::ERROR_DUPLICATE => 'Nomor seri ini sudah ditambahkan.',
                default => 'Nomor seri sudah terdaftar. Gunakan nomor seri yang sudah ada melalui pencarian.',
            });
            return;
        }

        $available = (float) ($line['returnable_quantity'] ?? 0) + (float) $this->getExistingNonSerialLineQuantity((int) $line['sale_detail_id']);
        if (count($selection['pending_serials'] ?? []) + 1 > $available) {
            $this->addError("lineSelections.{$lineKey}.pending_input", 'Jumlah nomor seri melebihi sisa kuantitas yang dapat diretur.');
            return;
        }

        $this->lineSelections[$lineKey]['pending_serials'][] = [
            'serial_number' => $text,
            'replacement_serial_id' => null,
            'replacement_serial_input' => '',
            'replacement_serial_label' => '',
        ];
        $this->lineSelections[$lineKey]['pending_input'] = '';
        $this->lineSelections[$lineKey]['quantity'] = count($this->lineSelections[$lineKey]['pending_serials']);
    }

    public function removePendingSerial(string $lineKey, int $index): void
    {
        if (! isset($this->lineSelections[$lineKey]['pending_serials'][$index])) {
            return;
        }
        unset($this->lineSelections[$lineKey]['pending_serials'][$index]);
        $this->lineSelections[$lineKey]['pending_serials'] = array_values($this->lineSelections[$lineKey]['pending_serials']);
        $this->lineSelections[$lineKey]['quantity'] = count($this->lineSelections[$lineKey]['pending_serials']);
    }

    public function scanPendingReplacementSerial(string $lineKey, int $index): void
    {
        $pending = $this->lineSelections[$lineKey]['pending_serials'][$index] ?? null;
        $line = $this->findSnapshotLineByKey($lineKey);
        if (! $pending || ! $line) {
            return;
        }
        $field = "lineSelections.{$lineKey}.pending_serials.{$index}.replacement_serial_input";
        $input = trim((string) ($pending['replacement_serial_input'] ?? ''));
        if ($input === '') {
            return;
        }

        $serial = \Modules\Product\Entities\ProductSerialNumber::query()
            ->where('product_id', $line['product_id'])->where('serial_number', \Modules\Product\Entities\ProductSerialNumber::normalize($input))->first();
        if (! $serial) {
            $this->addError($field, "Serial {$input} tidak ditemukan.");
            return;
        }

        foreach ($this->lineSelections as $otherKey => $otherSelection) {
            if (($otherSelection['replacement_serial_id'] ?? null) === $serial->id) {
                $this->addError($field, 'Serial pengganti tidak boleh digunakan lebih dari satu kali dalam retur yang sama.');
                return;
            }
            foreach ($otherSelection['pending_serials'] ?? [] as $i => $other) {
                if (! ($otherKey === $lineKey && $i === $index) && ($other['replacement_serial_id'] ?? null) === $serial->id) {
                    $this->addError($field, 'Serial pengganti tidak boleh digunakan lebih dari satu kali dalam retur yang sama.');
                    return;
                }
            }
        }

        try {
            app(PosReturnReplacementGuard::class)->validateReplacementSerial((int) $line['product_id'], $serial->id, null, $this->return->id ?? null);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->addError($field, implode(' ', $e->validator->errors()->all()));
            return;
        }

        $this->lineSelections[$lineKey]['pending_serials'][$index]['replacement_serial_id'] = $serial->id;
        $this->lineSelections[$lineKey]['pending_serials'][$index]['replacement_serial_label'] = $serial->serial_number;
        $this->lineSelections[$lineKey]['pending_serials'][$index]['replacement_serial_input'] = '';
        $this->resetErrorBag($field);
    }

    /**
     * One submission line per proposed unit (quantity 1, no returned serial id yet).
     *
     * @return array<int,array<string,mixed>>
     */
    protected function buildPendingSubmissionLines(array $line, array $selection): array
    {
        $resolution = $selection['resolution'] ?? PosReturnLine::RESOLUTION_NONE;
        if ($resolution === PosReturnLine::RESOLUTION_NONE) {
            return [];
        }

        $lines = [];
        foreach ($selection['pending_serials'] ?? [] as $pending) {
            $lines[] = [
                'sale_detail_id' => $line['sale_detail_id'],
                'sale_id' => $line['sale_id'] ?? null,
                'pos_transaction_line_id' => $line['pos_transaction_line_id'] ?? null,
                'returned_serial_id' => null,
                'pending_serial' => $pending['serial_number'],
                'resolution' => $resolution,
                'quantity' => 1,
                'replacement_serial_id' => $resolution === PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT ? ($pending['replacement_serial_id'] ?? null) : null,
                'replacement_reason' => $selection['replacement_reason'] ?? null,
                'bundle_return_scope' => $selection['bundle_return_scope'] ?? null,
            ];
        }

        return $lines;
    }
}
