<?php

namespace App\Livewire\Transfer;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Services\TransferScanResolverService;
use Modules\Adjustment\Services\TransferV3Access;
use Modules\Adjustment\Services\TransferV3GoodsService;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductUnitConversion;
use RuntimeException;
use Throwable;

/**
 * Workflow version 3 goods entry: one condition, product search and exact
 * barcode/conversion/serial scans across all businesses, editable
 * quantities, Simpan Draf and Ajukan Persetujuan. Component state carries
 * product/serial identities only -- never locations or business provenance.
 * Every mutation re-resolves identities server-side; client-held values are
 * never trusted for conversion factors or serial eligibility.
 */
class TransferV3GoodsForm extends Component
{
    #[Locked]
    public ?int $transferId = null;

    #[Locked]
    public ?int $transferRevision = null;

    #[Locked]
    public ?string $transferStatus = null;

    #[Locked]
    public string $stockCondition = Transfer::CONDITION_GOOD;

    #[Locked]
    public string $operationKey = '';

    /**
     * Rows: [{product_id, product_name, product_code, serialized, quantity, serials: [{id, serial_number}]}].
     * Only quantity is editable, through setQuantity(). Rows are public Livewire state, so
     * the goods service revalidates every product and serial identity (and,
     * at submission, serial eligibility) instead of trusting them.
     */
    public array $rows = [];

    /** Product search modal (Cari Produk (Stok Dikelola)). */
    public bool $showSearchModal = false;

    /** Deliberate name/code/barcode/category/brand search; results always need explicit selection. */
    public string $searchQuery = '';

    /** Product search results (location-free projection). */
    #[Locked]
    public array $searchResults = [];

    /** Ambiguous exact-scan candidates (location-free projection). */
    #[Locked]
    public array $candidates = [];

    /**
     * Operation token of the scan whose ambiguity is awaiting a choice. A
     * choice or cancellation must carry this token, so a stale candidate
     * list can never be applied to another scan.
     */
    #[Locked]
    public ?string $pendingScanToken = null;

    public ?string $scanMessage = null;

    /** success | warning | danger */
    public string $scanMessageLevel = 'success';

    /**
     * Recently settled scanner operation tokens, so a retried request from
     * the client scan queue is applied exactly once.
     */
    #[Locked]
    public array $processedScanTokens = [];

    public ?string $errorMessage = null;

    public bool $confirmConditionChange = false;

    #[Locked]
    public ?string $pendingCondition = null;

    public function mount(?Transfer $transfer = null): void
    {
        $this->operationKey = (string) Str::uuid();

        if ($transfer && $transfer->exists) {
            TransferV3Access::assertV3($transfer);

            $this->transferId = $transfer->id;
            $this->transferRevision = (int) $transfer->revision;
            $this->transferStatus = $transfer->status;
            $this->stockCondition = $transfer->stock_condition;

            $transfer->loadMissing('products.product');
            foreach ($transfer->products as $line) {
                $this->rows[] = [
                    'product_id'   => (int) $line->product_id,
                    'product_name' => (string) $line->product?->product_name,
                    'product_code' => (string) $line->product?->product_code,
                    'serialized'   => (bool) $line->product?->serial_number_required,
                    'quantity'     => (int) $line->quantity,
                    'serials'      => array_values(array_map(fn ($serial) => [
                        'id'            => (int) ($serial['id'] ?? 0),
                        'serial_number' => (string) ($serial['serial_number'] ?? ''),
                    ], $line->serial_numbers ?? [])),
                ];
            }
        }
    }

    public function selectStockCondition(string $condition): void
    {
        if ($this->transferId !== null || ! in_array($condition, Transfer::CONDITIONS, true) || $condition === $this->stockCondition) {
            return;
        }

        if ($this->pendingScanToken !== null) {
            $this->feedback('Selesaikan pilihan pindaian terlebih dahulu sebelum mengubah kondisi barang.', 'warning');

            return;
        }

        if ($this->rows !== []) {
            $this->pendingCondition = $condition;
            $this->confirmConditionChange = true;

            return;
        }

        $this->stockCondition = $condition;
    }

    public function applyConditionChange(): void
    {
        if ($this->transferId === null && in_array($this->pendingCondition, Transfer::CONDITIONS, true)) {
            $this->stockCondition = $this->pendingCondition;
            $this->rows = [];
            $this->searchResults = [];
        }

        $this->pendingCondition = null;
        $this->confirmConditionChange = false;
    }

    public function cancelConditionChange(): void
    {
        $this->pendingCondition = null;
        $this->confirmConditionChange = false;
    }

    /**
     * Dedicated scanner entry: exact product barcode, conversion barcode or
     * serial number only. Never falls back to name search.
     *
     * Called by the client FIFO scan coordinator with a per-scan token and
     * returns an acknowledgment. `ambiguous`/`blocked` keep the scan pending
     * on the client; every other status settles it. A token that already
     * settled returns `replayed` without applying again.
     *
     * @return array{status: string, token: ?string}
     */
    public function scanBarcode(string $value, ?string $token = null): array
    {
        $this->authorizeEntry();

        $token = $this->normalizeToken($token);

        if ($token !== null && in_array($token, $this->processedScanTokens, true)) {
            return ['status' => 'replayed', 'token' => $token];
        }

        if ($this->pendingScanToken !== null) {
            // Retried ambiguous scan: keep the same candidate list.
            if ($token === $this->pendingScanToken) {
                return ['status' => 'ambiguous', 'token' => $token];
            }

            return ['status' => 'blocked', 'token' => $token];
        }

        $query = trim(str_replace(["\r", "\n"], '', $value));
        if ($query === '') {
            return $this->settleScan($token, 'empty');
        }

        $result = app(TransferScanResolverService::class)->resolveAcrossBusinesses($query, $this->isBroken());

        switch ($result['status'] ?? null) {
            case 'resolved':
                return $this->settleScan($token, $this->applyCandidate($result['candidate']));
            case 'ambiguous':
                $this->candidates = array_values(array_map(fn (array $candidate) => $this->projectCandidate($candidate), $result['candidates']));
                // Untokened direct calls still get a binding token.
                $this->pendingScanToken = $token ?? (string) Str::uuid();
                $this->feedback("Beberapa hasil cocok dengan pindaian '{$query}'. Pilih salah satu atau batalkan pindaian ini.", 'warning');

                return ['status' => 'ambiguous', 'token' => $this->pendingScanToken];
            case 'rejected':
                $this->feedback((string) $result['message'], 'danger');

                return $this->settleScan($token, 'rejected');
            default:
                $this->feedback("Barcode atau nomor seri '{$query}' tidak ditemukan.", 'danger');

                return $this->settleScan($token, 'not_found');
        }
    }

    /**
     * @return array{status: string, token: ?string}
     */
    public function chooseCandidate(int $index, ?string $token = null): array
    {
        $this->authorizeEntry();

        $token = $this->normalizeToken($token);
        if ($token !== null && in_array($token, $this->processedScanTokens, true)) {
            return ['status' => 'replayed', 'token' => $token];
        }
        if ($this->pendingScanToken === null || $token !== $this->pendingScanToken) {
            return ['status' => 'stale', 'token' => $token];
        }

        $candidate = $this->candidates[$index] ?? null;
        if (! $candidate) {
            return ['status' => 'stale', 'token' => $token];
        }

        $this->clearPendingScan();

        return $this->settleScan($token, $this->applyCandidate($candidate));
    }

    /**
     * @return array{status: string, token: ?string}
     */
    public function cancelCandidates(?string $token = null): array
    {
        $this->authorizeEntry();

        $token = $this->normalizeToken($token);
        if ($token !== null && in_array($token, $this->processedScanTokens, true)) {
            return ['status' => 'replayed', 'token' => $token];
        }
        if ($this->pendingScanToken === null || $token !== $this->pendingScanToken) {
            return ['status' => 'stale', 'token' => $token];
        }

        $this->clearPendingScan();
        $this->feedback('Pindaian dibatalkan. Tidak ada barang yang ditambahkan.', 'warning');

        return $this->settleScan($token, 'cancelled');
    }

    public function openSearchModal(): void
    {
        $this->authorizeEntry();

        $this->searchQuery = '';
        $this->searchResults = [];
        $this->showSearchModal = true;
        $this->dispatch('v3-transfer-search-opened');
    }

    public function closeSearchModal(): void
    {
        $this->showSearchModal = false;
        $this->searchQuery = '';
        $this->searchResults = [];
        $this->dispatch('v3-transfer-scan-focus', force: true);
    }

    public function updatedSearchQuery(): void
    {
        $this->searchProducts();
    }

    /** Enter in the modal: search only; never selects or submits. */
    public function searchProducts(): void
    {
        $this->authorizeEntry();

        $query = trim($this->searchQuery);
        if ($query === '') {
            $this->searchResults = [];

            return;
        }

        $this->searchResults = array_values(array_map(
            fn (array $candidate) => $this->projectSearchResult($candidate),
            app(TransferScanResolverService::class)->searchAcrossBusinesses($query, $this->isBroken())
        ));
    }

    public function selectSearchResult(int $index): void
    {
        $this->authorizeEntry();

        $candidate = $this->searchResults[$index] ?? null;
        if ($candidate) {
            $this->applyCandidate(['type' => 'product', 'product' => ['id' => $candidate['id']]]);
        }

        $this->closeSearchModal();
    }

    /**
     * Manual quantity edit from the Jumlah input. Stored as entered when
     * numeric (submission validates positive whole quantities and the
     * serial count); a scan later only raises it to the selected serial
     * count, so a higher manual quantity is preserved.
     */
    public function setQuantity(int $index, $value): void
    {
        $this->authorizeEntry();

        if (! isset($this->rows[$index])) {
            return;
        }

        $value = is_string($value) ? trim($value) : $value;
        $this->rows[$index]['quantity'] = is_numeric($value) ? $value + 0 : 0;
    }

    public function removeRow(int $index): void
    {
        unset($this->rows[$index]);
        $this->rows = array_values($this->rows);
    }

    public function removeSerial(int $index, int $serialId): void
    {
        if (! isset($this->rows[$index])) {
            return;
        }

        $this->rows[$index]['serials'] = array_values(array_filter(
            $this->rows[$index]['serials'],
            fn (array $serial) => (int) $serial['id'] !== $serialId
        ));
    }

    public function saveDraft()
    {
        $this->authorizeEntry();
        // Cleared first: the client scan coordinator reads an empty
        // errorMessage after this call as "saved, redirecting".
        $this->errorMessage = null;
        if ($this->blockedByPendingScan()) {
            return null;
        }
        $goods = app(TransferV3GoodsService::class);

        try {
            $transfer = $this->transferId === null
                ? $goods->createDraft($this->stockCondition, $this->lines(), auth()->user(), $this->activeSettingId(), $this->operationKey)
                : $goods->saveDraft($this->transfer(), $this->stockCondition, $this->lines(), auth()->user(), $this->activeSettingId(), $this->transferRevision);
        } catch (InvalidArgumentException|RuntimeException $e) {
            $this->errorMessage = $e->getMessage();

            return null;
        } catch (Throwable $e) {
            return $this->failed($e);
        }

        $message = $this->transferStatus === Transfer::STATUS_PENDING && $transfer->status === Transfer::STATUS_DRAFT
            ? 'Transfer diperbarui dan dikembalikan ke Draf. Ajukan kembali untuk persetujuan.'
            : 'Draf Transfer Stok disimpan.';
        toast($message . ' No. Dokumen: ' . $transfer->document_number, 'success');

        return redirect()->route('transfers.show', $transfer->id);
    }

    public function submitForApproval()
    {
        $this->authorizeEntry();
        // Cleared first: the client scan coordinator reads an empty
        // errorMessage after this call as "saved, redirecting".
        $this->errorMessage = null;
        if ($this->blockedByPendingScan()) {
            return null;
        }
        $goods = app(TransferV3GoodsService::class);

        try {
            if ($this->transferId === null) {
                $transfer = $goods->createAndSubmit($this->stockCondition, $this->lines(), auth()->user(), $this->activeSettingId(), $this->operationKey);
            } else {
                $transfer = $this->transfer();
                // The editor's loaded revision guards every step; a revision
                // is only advanced by our own save within this same request.
                $expectedRevision = $this->transferRevision;
                if ($transfer->status === Transfer::STATUS_PENDING) {
                    $transfer = $goods->saveDraft($transfer, $this->stockCondition, $this->lines(), auth()->user(), $this->activeSettingId(), $expectedRevision);
                    $expectedRevision = (int) $transfer->revision;
                }
                $transfer = $goods->submit($transfer, $this->lines(), auth()->user(), $this->activeSettingId(), $expectedRevision);
            }
        } catch (InvalidArgumentException|RuntimeException $e) {
            $this->errorMessage = $e->getMessage();

            return null;
        } catch (Throwable $e) {
            return $this->failed($e);
        }

        toast('Transfer Stok diajukan untuk persetujuan. No. Dokumen: ' . $transfer->document_number, 'success');

        return redirect()->route('transfers.show', $transfer->id);
    }

    public function render()
    {
        return view('livewire.transfer.transfer-v3-goods-form');
    }

    /**
     * Operator lines for the goods service, which validates them
     * authoritatively.
     */
    private function lines(): array
    {
        return array_map(fn (array $row) => [
            'product_id' => (int) $row['product_id'],
            'quantity'   => is_numeric($row['quantity'] ?? null) ? $row['quantity'] + 0 : 0,
            'serial_ids' => array_map(fn (array $serial) => (int) $serial['id'], $row['serials'] ?? []),
        ], $this->rows);
    }

    /** @return string settled status: applied | duplicate | rejected */
    private function applyCandidate(array $candidate): string
    {
        $productId = (int) ($candidate['product']['id'] ?? 0);
        $product = Product::active()->where('stock_managed', true)->find($productId);

        if (! $product) {
            $this->feedback('Produk tidak tersedia.', 'danger');

            return 'rejected';
        }

        if (($candidate['type'] ?? null) === 'serial') {
            return $this->addSerial($product, (int) ($candidate['serial']['id'] ?? 0));
        }

        $factor = 1;
        if (($candidate['type'] ?? null) === 'conversion') {
            // Authoritative factor from the database, never the client payload.
            $conversion = ProductUnitConversion::where('product_id', $product->id)->find((int) ($candidate['conversion']['id'] ?? 0));
            $raw = $conversion ? (float) $conversion->conversion_factor : 0.0;

            if ($raw <= 0 || abs($raw - round($raw)) > 1e-6) {
                $this->feedback('Barcode konversi tidak valid.', 'danger');

                return 'rejected';
            }
            $factor = (int) round($raw);
        }

        $index = $this->rowIndex($product);
        $this->rows[$index]['quantity'] = (int) $this->rows[$index]['quantity'] + $factor;
        $this->feedback("{$product->product_name} +{$factor}", 'success');

        return 'applied';
    }

    private function addSerial(Product $product, int $serialId): string
    {
        $serial = ProductSerialNumber::where('product_id', $product->id)->find($serialId);
        $eligible = $serial && ($this->isBroken() ? $serial->isAvailableBroken() : $serial->isSellable());

        if (! $eligible) {
            $this->feedback('Nomor seri tidak dapat digunakan untuk transfer ini.', 'danger');

            return 'rejected';
        }

        foreach ($this->rows as $row) {
            foreach ($row['serials'] as $selected) {
                if ((int) $selected['id'] === $serialId) {
                    $this->feedback("Nomor seri {$serial->serial_number} sudah dipilih.", 'warning');

                    return 'duplicate';
                }
            }
        }

        $index = $this->rowIndex($product);
        $this->rows[$index]['serials'][] = ['id' => (int) $serial->id, 'serial_number' => (string) $serial->serial_number];

        if ((int) $this->rows[$index]['quantity'] < count($this->rows[$index]['serials'])) {
            $this->rows[$index]['quantity'] = count($this->rows[$index]['serials']);
        }

        $this->feedback("Nomor seri {$serial->serial_number} ditambahkan.", 'success');

        return 'applied';
    }

    private function rowIndex(Product $product): int
    {
        foreach ($this->rows as $index => $row) {
            if ((int) $row['product_id'] === (int) $product->id) {
                return $index;
            }
        }

        $this->rows[] = [
            'product_id'   => (int) $product->id,
            'product_name' => (string) $product->product_name,
            'product_code' => (string) $product->product_code,
            'serialized'   => (bool) $product->serial_number_required,
            'quantity'     => 0,
            'serials'      => [],
        ];

        return array_key_last($this->rows);
    }

    /**
     * @return array{status: string, token: ?string}
     */
    private function settleScan(?string $token, string $status): array
    {
        if ($token !== null) {
            $this->processedScanTokens = array_slice([...$this->processedScanTokens, $token], -100);
        }

        return ['status' => $status, 'token' => $token];
    }

    private function clearPendingScan(): void
    {
        $this->pendingScanToken = null;
        $this->candidates = [];
    }

    private function normalizeToken(?string $token): ?string
    {
        $token = $token === null ? null : Str::limit(trim($token), 64, '');

        return $token === '' ? null : $token;
    }

    private function blockedByPendingScan(): bool
    {
        if ($this->pendingScanToken === null) {
            return false;
        }

        $this->errorMessage = 'Tidak dapat menyimpan: masih ada pindaian yang menunggu pilihan produk. Pilih produk atau batalkan pindaian tersebut terlebih dahulu.';

        return true;
    }

    private function feedback(string $message, string $level): void
    {
        $this->scanMessage = $message;
        $this->scanMessageLevel = $level;
    }

    /**
     * Search display fields only; no stock, location or business provenance.
     */
    private function projectSearchResult(array $candidate): array
    {
        $product = $candidate['product'] ?? [];

        return [
            'id'                     => (int) ($product['id'] ?? 0),
            'product_name'           => (string) ($product['product_name'] ?? ''),
            'product_code'           => (string) ($product['product_code'] ?? ''),
            'barcode'                => $product['barcode'] ?? null,
            'base_unit'              => (string) ($product['base_unit'] ?? 'Unit'),
            'serial_number_required' => (bool) ($product['serial_number_required'] ?? false),
            'category_name'          => $product['category_name'] ?? null,
            'brand_name'             => $product['brand_name'] ?? null,
        ];
    }

    /**
     * Keeps only identity fields; drops any location/business provenance.
     */
    private function projectCandidate(array $candidate): array
    {
        return [
            'type'        => $candidate['type'] ?? 'product',
            'description' => (string) ($candidate['description'] ?? ''),
            'product'     => ['id' => (int) ($candidate['product']['id'] ?? 0)],
            'serial'      => isset($candidate['serial']) ? ['id' => (int) $candidate['serial']['id']] : null,
            'conversion'  => isset($candidate['conversion']['id']) ? ['id' => (int) $candidate['conversion']['id']] : null,
        ];
    }

    private function authorizeEntry(): void
    {
        TransferV3Access::authorize($this->transferId === null ? TransferV3Access::CREATE : TransferV3Access::EDIT);
    }

    private function transfer(): Transfer
    {
        $transfer = Transfer::findOrFail($this->transferId);
        TransferV3Access::assertV3($transfer);

        return $transfer;
    }

    private function isBroken(): bool
    {
        return $this->stockCondition === Transfer::CONDITION_BREAKAGE;
    }

    private function activeSettingId(): int
    {
        return (int) session('setting_id');
    }

    private function failed(Throwable $e)
    {
        Log::error('Stock transfer v3 goods form error', ['error' => $e->getMessage(), 'transfer_id' => $this->transferId]);
        $this->errorMessage = 'Terjadi kesalahan saat menyimpan transfer stok.';

        return null;
    }
}
