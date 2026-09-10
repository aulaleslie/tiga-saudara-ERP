<?php

namespace App\Livewire\Adjustment;

use App\Services\Notification\DocumentNotificationService;
use Exception;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Adjustment\Entities\Adjustment;
use Modules\Adjustment\Services\AdjustmentProductResolver;
use Modules\Adjustment\Services\CountDraftService;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\Transaction;
use Modules\Setting\Entities\Location;

class AdjustmentProductTable extends Component
{
    protected $listeners = [
        'productSelected',
        'serialNumberSelected',
        'locationSelected',
        'locationDropdownSelected',
    ];

    /**
     * Mode: 'opname' for normal count drafts, 'legacy' for legacy normal/breakage if needed.
     */
    public string $mode = 'opname';

    // Products table rows
    public array $products = [];

    // Parallel arrays for backward compatibility with tests & legacy breakage
    public $quantities = [];
    public array $serialNumberErrors = [];
    public $hasAdjustments = false;

    /**
     * Locked against direct client mutation: Livewire's wire protocol lets a
     * crafted request $set() any public property regardless of which method
     * it is bound to in the Blade template, so without #[Locked] a request
     * could set locationId straight to a foreign-setting location and skip
     * handleLocationChange()'s ownership check entirely. Every location
     * transition must go through locationSelected()/locationDropdownSelected()
     * or confirmLocationChange(), which revalidate via resolveOwnedLocation().
     */
    #[Locked]
    public ?int $locationId = null;

    // Redesigned Stock Opname State
    public ?string $draftSessionId = null;
    public ?int $adjustmentId = null;
    public string $activeCondition = 'good'; // 'good' or 'bad'
    public string $scanInput = '';
    public ?string $feedbackMessage = null;
    public string $feedbackType = 'info'; // 'success', 'warning', 'danger', 'info'

    // Ambiguity resolution state (locked against direct client manipulation)
    public bool $showAmbiguityModal = false;
    #[Locked]
    public array $ambiguousCandidates = [];
    #[Locked]
    public string $ambiguousCondition = 'good';

    // Row serial dialog state
    public bool $showSerialModal = false;
    public ?int $selectedRowIndexForSerials = null;
    public string $rowSerialInput = '';
    public string $rowSerialCondition = 'good';
    public ?string $rowSerialError = null;

    // Location confirmation modal state
    public bool $showLocationConfirmModal = false;

    /**
     * Locked for the same reason as locationId above: confirmLocationChange()
     * trusts this value to already be an owned location because
     * handleLocationChange() is the only path that sets it. resolveOwnedLocation()
     * is still re-checked in applyLocationChange() as defense in depth.
     */
    #[Locked]
    public ?int $pendingLocationId = null;

    // Product search modal state
    public bool $showSearchModal = false;
    public string $searchTerm = '';
    public array $searchResults = [];

    public function mount(
        $adjustedProducts = null,
        $locationId = null,
        $serial_numbers = null,
        $is_taxables = null,
        $product_ids = null,
        $quantities = null,
        $adjustment = null
    ): void {
        $this->products = [];
        $rawLocationId = $locationId ? (int) $locationId : null;
        $resolvedLoc = $rawLocationId ? $this->resolveOwnedLocation($rawLocationId) : null;
        $this->locationId = $resolvedLoc ? (int) $resolvedLoc->id : null;
        $this->quantities = $quantities ?? [];
        $this->draftSessionId = (string) \Illuminate\Support\Str::uuid();

        // Check if there is old draft payload in session / old input first (e.g. failed validation restoration)
        $hasOldDraft = old('count_draft') !== null || (session()->has('_old_input') && array_key_exists('count_draft', session()->get('_old_input', [])));
        if ($hasOldDraft) {
            $oldDraft = old('count_draft') ?? session()->getOldInput('count_draft');
            $draftData = is_string($oldDraft) ? json_decode($oldDraft, true) : $oldDraft;
            $oldLocation = old('location_id') ?? (session()->has('_old_input') ? session()->getOldInput('location_id') : null);

            $candidateLocation = $oldLocation ?: ($draftData['location_id'] ?? $this->locationId);
            $resolvedOldLoc = $candidateLocation ? $this->resolveOwnedLocation((int) $candidateLocation) : null;
            $this->locationId = $resolvedOldLoc ? (int) $resolvedOldLoc->id : null;
            if (!empty($draftData['draft_session_id'])) {
                $this->draftSessionId = (string) $draftData['draft_session_id'];
            }
            if ($adjustment instanceof Adjustment) {
                $this->adjustmentId = (int) $adjustment->id;
            }
            $rows = is_array($draftData) && isset($draftData['rows']) && is_array($draftData['rows'])
                ? $draftData['rows']
                : [];
            $this->initFromDraftRows($rows);
            return;
        }

        // Check if mounting from an Adjustment model or draft
        if ($adjustment instanceof Adjustment) {
            $this->adjustmentId = (int) $adjustment->id;
            $this->initFromAdjustment($adjustment);
            return;
        }

        // Backward compatibility: legacy adjustedProducts
        if ($adjustedProducts) {
            $this->hasAdjustments = true;
            $this->products = array_map(function ($adjustedProduct) {
                $stock = ProductStock::where('product_id', $adjustedProduct['product']['id'])
                    ->where('location_id', $this->locationId)
                    ->first();

                $goodTax = (int) ($stock->quantity_tax ?? 0);
                $goodNonTax = (int) ($stock->quantity_non_tax ?? 0);
                $badTax = (int) ($stock->broken_quantity_tax ?? 0);
                $badNonTax = (int) ($stock->broken_quantity_non_tax ?? 0);

                return [
                    'id' => $adjustedProduct['product']['id'],
                    'product_name' => $adjustedProduct['product']['product_name'],
                    'product_code' => $adjustedProduct['product']['product_code'],
                    'serial_number_required' => (bool) $adjustedProduct['product']['serial_number_required'],
                    'serial_numbers' => $this->mapStoredSerialNumbers(
                        is_string($adjustedProduct['serial_numbers'])
                            ? json_decode($adjustedProduct['serial_numbers'], true)
                            : $adjustedProduct['serial_numbers']
                    ),
                    'unit' => $adjustedProduct['product']['base_unit']['name'] ?? '',
                    'quantity' => $adjustedProduct['quantity'],
                    'good_count' => (int) $adjustedProduct['quantity'],
                    'bad_count' => 0,
                    'baseline' => $this->sanitizeBaseline([
                        'existing_good_total' => $goodTax + $goodNonTax,
                        'existing_good_tax' => $goodTax,
                        'existing_good_non_tax' => $goodNonTax,
                        'existing_bad_total' => $badTax + $badNonTax,
                        'existing_bad_tax' => $badTax,
                        'existing_bad_non_tax' => $badNonTax,
                        'captured_at' => now()->toIso8601String(),
                    ]),
                    'quantity_tax' => $goodTax,
                    'quantity_non_tax' => $goodNonTax,
                    'broken_quantity_tax' => $badTax,
                    'broken_quantity_non_tax' => $badNonTax,
                    'is_taxable' => $adjustedProduct['is_taxable'] ?? 0,
                ];
            }, $adjustedProducts);

            $this->quantities = collect($adjustedProducts)->mapWithKeys(function ($item, $index) {
                return [
                    $index => [
                        'tax' => (int) ($item['quantity_tax'] ?? 0),
                        'non_tax' => (int) ($item['quantity_non_tax'] ?? 0),
                    ]
                ];
            })->toArray();
        } elseif (!empty($product_ids) && !empty($quantities)) {
            // Restore from legacy validation error
            foreach ($product_ids as $key => $product_id) {
                $product = Product::with('baseUnit')->find($product_id);
                $stock = ProductStock::where('product_id', $product_id)
                    ->where('location_id', $this->locationId)
                    ->first();

                if ($product) {
                    $baseUnit = $product->baseUnit;
                    $unitName = $baseUnit->unit_name ?? $baseUnit->name ?? '';
                    $goodTax = (int) ($stock->quantity_tax ?? 0);
                    $goodNonTax = (int) ($stock->quantity_non_tax ?? 0);
                    $badTax = (int) ($stock->broken_quantity_tax ?? 0);
                    $badNonTax = (int) ($stock->broken_quantity_non_tax ?? 0);

                    $resolver = app(AdjustmentProductResolver::class);
                    $capturedBaseline = $resolver->captureBaseline($product->id, (int) $this->locationId);

                    $this->products[] = [
                        'id' => $product->id,
                        'product_name' => $product->product_name,
                        'product_code' => $product->product_code,
                        'serial_number_required' => (bool) $product->serial_number_required,
                        'serial_numbers' => $this->getSerialNumbers($serial_numbers, $key),
                        'unit' => $unitName,
                        'quantity' => $quantities[$key] ?? 0,
                        'good_count' => (int) ($quantities[$key] ?? 0),
                        'bad_count' => 0,
                        'baseline' => $this->sanitizeBaseline($capturedBaseline),
                        'quantity_tax' => $goodTax,
                        'quantity_non_tax' => $goodNonTax,
                        'broken_quantity_tax' => $badTax,
                        'broken_quantity_non_tax' => $badNonTax,
                        'is_taxable' => $is_taxables[$key] ?? 0,
                    ];
                }
            }
        }
    }

    /**
     * Initialize from an existing Adjustment model (draft or adapted legacy).
     */
    protected function initFromAdjustment(Adjustment $adjustment): void
    {
        $this->adjustmentId = (int) $adjustment->id;
        $this->locationId = $adjustment->location_id;

        if ($adjustment->isVersionedCountDraft()) {
            $draft = $adjustment->count_draft;
            $this->draftSessionId = !empty($draft['draft_session_id'])
                ? (string) $draft['draft_session_id']
                : "adj-{$adjustment->id}";
            $this->initFromDraftRows($draft['rows'] ?? []);
        } else {
            // Adapt legacy
            $service = app(CountDraftService::class);
            $draft = $service->adaptLegacyAdjustmentToDraft($adjustment);
            $this->draftSessionId = "adj-{$adjustment->id}";
            $this->initFromDraftRows($draft['rows'] ?? []);
        }
    }

    /**
     * Determine if current user is authorized to view system stock numbers.
     */
    protected function canViewSystemStock(): bool
    {
        $user = auth()->user();
        if (!$user) {
            return false;
        }

        return $user->hasRole('Super Admin') || $user->can('adjustments.view-system-stock');
    }

    /**
     * Sanitize baseline payload to remove readable quantities for unauthorized users.
     */
    protected function sanitizeBaseline(array $baseline): array
    {
        if ($this->canViewSystemStock()) {
            return $baseline;
        }

        // Keep only opaque reference token, captured timestamp, and signature; scrub all quantities
        return [
            'token' => $baseline['token'] ?? null,
            'signature' => $baseline['signature'] ?? null,
            'captured_at' => $baseline['captured_at'] ?? now()->toIso8601String(),
        ];
    }

    /**
     * Initialize product rows from draft rows structure.
     */
    protected function initFromDraftRows(array $rows): void
    {
        $this->products = [];
        $this->quantities = [];
        $this->serialNumberErrors = [];

        foreach ($rows as $index => $row) {
            $isSerialized = (bool) ($row['is_serialized'] ?? false);
            $serials = $row['serials'] ?? [];

            // Preserve raw attempted good_count and bad_count without casting to (int)
            $rawGoodCount = $row['good_count'] ?? 0;
            $rawBadCount = $row['bad_count'] ?? 0;

            $rawBaseline = $row['baseline'] ?? [
                'existing_good_total' => 0,
                'existing_good_tax' => 0,
                'existing_good_non_tax' => 0,
                'existing_bad_total' => 0,
                'existing_bad_tax' => 0,
                'existing_bad_non_tax' => 0,
                'captured_at' => now()->toIso8601String(),
            ];

            $this->products[] = [
                'id' => (int) $row['product_id'],
                'product_name' => $row['product_name'] ?? '',
                'product_code' => $row['product_code'] ?? '',
                'serial_number_required' => $isSerialized,
                'unit' => $row['base_unit'] ?? '',
                'good_count' => $rawGoodCount,
                'bad_count' => $rawBadCount,
                'serial_numbers' => $serials,
                'baseline' => $this->sanitizeBaseline($rawBaseline),
                'quantity' => is_numeric($rawGoodCount) ? (int) $rawGoodCount : 0,
                'quantity_tax' => (int) ($row['tax_allocation']['good_tax'] ?? 0),
                'quantity_non_tax' => (int) ($row['tax_allocation']['good_non_tax'] ?? 0),
            ];

            $this->quantities[$index] = [
                'tax' => (int) ($row['tax_allocation']['good_tax'] ?? 0),
                'non_tax' => (int) ($row['tax_allocation']['good_non_tax'] ?? 0),
            ];
            $this->serialNumberErrors[$index] = null;
        }

        $this->hasAdjustments = count($this->products) > 0;
    }

    public function rendering(): void
    {
        $this->sanitizeExistingProducts();
    }

    public function dehydrate(): void
    {
        $this->sanitizeExistingProducts();
    }

    /**
     * Recheck permission and sanitize products state to scrub readable baseline system quantities if permission was revoked.
     */
    protected function sanitizeExistingProducts(): void
    {
        if (!$this->canViewSystemStock()) {
            foreach ($this->products as $index => $product) {
                if (isset($product['baseline']) && is_array($product['baseline'])) {
                    $this->products[$index]['baseline'] = $this->sanitizeBaseline($product['baseline']);
                }
            }
        }
    }

    public function render(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        return view('livewire.adjustment.adjustment-product-table', [
            'canViewSystemStock' => $this->canViewSystemStock(),
        ]);
    }

    /**
     * Every Livewire action that accepts a location ID from the client must
     * revalidate it server-side against the active session setting and
     * exclude consignment locations -- the LocationSearchDropdown's rendered
     * options are a UI convenience, not a security boundary, so a crafted
     * request could otherwise read another setting's location/stock data.
     */
    protected function resolveOwnedLocation(?int $locationId): ?Location
    {
        if (!$locationId) {
            return null;
        }

        $activeSettingId = (int) session('setting_id');

        return Location::with('setting')
            ->where('id', $locationId)
            ->where('setting_id', $activeSettingId)
            ->where('is_consignment', false)
            ->first();
    }

    /**
     * Location dropdown event bridge.
     */
    public function locationDropdownSelected($name, $value): void
    {
        if ($name === 'location_id') {
            $this->handleLocationChange($value);
        }
    }

    public function locationSelected($locationId): void
    {
        $this->handleLocationChange($locationId);
    }

    protected function handleLocationChange($newLocationId): void
    {
        $newLocationId = !empty($newLocationId) ? (int) $newLocationId : null;

        if ($newLocationId !== null && !$this->resolveOwnedLocation($newLocationId)) {
            $this->feedbackMessage = 'Lokasi tidak ditemukan atau bukan milik pengaturan aktif.';
            $this->feedbackType = 'danger';
            $this->dispatch('setSelectedLocation', locationId: $this->locationId);
            return;
        }

        if ($this->locationId === $newLocationId) {
            return;
        }

        // If rows are already populated, ask for confirmation before clearing
        if (count($this->products) > 0) {
            $this->pendingLocationId = $newLocationId;
            $this->showLocationConfirmModal = true;
            return;
        }

        // If no products, directly switch location
        $this->applyLocationChange($newLocationId);
    }

    public function confirmLocationChange(): void
    {
        $this->applyLocationChange($this->pendingLocationId);
        $this->showLocationConfirmModal = false;
        $this->pendingLocationId = null;
    }

    public function cancelLocationChange(): void
    {
        $this->showLocationConfirmModal = false;
        $this->pendingLocationId = null;

        // Restore the dropdown's selected value back to the table's current locationId
        $this->dispatch('setSelectedLocation', locationId: $this->locationId);
    }

    /**
     * Single funnel that assigns $this->locationId from PHP.
     * Revalidates ownership again as defense in depth.
     */
    protected function applyLocationChange($newLocationId): void
    {
        $newLocationId = !empty($newLocationId) ? (int) $newLocationId : null;

        if ($newLocationId !== null && !$this->resolveOwnedLocation($newLocationId)) {
            $newLocationId = null;
            $this->feedbackMessage = 'Lokasi tidak ditemukan atau bukan milik pengaturan aktif.';
            $this->feedbackType = 'danger';
        } else {
            $this->feedbackMessage = 'Lokasi telah diubah. Daftar perhitungan telah diatur ulang.';
            $this->feedbackType = 'info';
        }

        $this->locationId = $newLocationId;
        $this->products = [];
        $this->quantities = [];
        $this->serialNumberErrors = [];
        $this->hasAdjustments = false;
    }

    /**
     * Set active counting condition: 'good' or 'bad'.
     */
    public function setActiveCondition(string $condition): void
    {
        if (in_array($condition, ['good', 'bad'], true)) {
            $this->activeCondition = $condition;
        }
    }

    /**
     * Scan bar input handler (called on Enter / scan completion or explicit queue drain).
     * Accepts an optional explicit $code while falling back to $this->scanInput.
     * Also accepts an optional explicit $condition ('good' or 'bad') to preserve the condition
     * captured when the scan was enqueued, even if $activeCondition changed later.
     */
    public function processScan(?string $code = null, ?string $condition = null): void
    {
        $code = trim($code ?? $this->scanInput);
        $this->feedbackMessage = null;

        $targetCondition = in_array($condition, ['good', 'bad'], true) ? $condition : $this->activeCondition;

        if ($code === '') {
            return;
        }

        if (!$this->locationId || !$this->resolveOwnedLocation($this->locationId)) {
            $this->locationId = null;
            $this->feedbackMessage = 'Pilih lokasi terlebih dahulu sebelum memindai.';
            $this->feedbackType = 'warning';
            $this->dispatch('select-scan-input');
            return;
        }

        $resolver = app(AdjustmentProductResolver::class);
        $result = $resolver->resolveScan($code);

        if ($result['status'] === 'not_found') {
            $this->feedbackMessage = "Barcode atau nomor seri '{$code}' tidak ditemukan.";
            $this->feedbackType = 'danger';
            $this->dispatch('select-scan-input');
            return;
        }

        if ($result['status'] === 'ambiguous') {
            $this->ambiguousCandidates = $result['candidates'] ?? [];
            $this->ambiguousCondition = $targetCondition;
            $this->showAmbiguityModal = true;
            $this->dispatch('opname-ambiguity-opened');
            return;
        }

        if ($result['status'] === 'resolved') {
            $applied = $this->applyResolvedCandidate($result['candidate'], $targetCondition);
            if ($applied) {
                $this->scanInput = '';
                $this->dispatch('restore-scanner-focus');
            } else {
                $this->dispatch('select-scan-input');
            }
        }
    }

    /**
     * Select a candidate from the ambiguity modal.
     */
    public function selectAmbiguousCandidate(int $candidateIndex): void
    {
        if (isset($this->ambiguousCandidates[$candidateIndex])) {
            $candidate = $this->ambiguousCandidates[$candidateIndex];
            $condition = in_array($this->ambiguousCondition, ['good', 'bad'], true) ? $this->ambiguousCondition : 'good';
            $this->showAmbiguityModal = false;
            $this->ambiguousCandidates = [];
            $this->ambiguousCondition = 'good';
            $this->dispatch('opname-ambiguity-closed');
            $applied = $this->applyResolvedCandidate($candidate, $condition);
            if ($applied) {
                $this->scanInput = '';
                $this->dispatch('restore-scanner-focus');
            } else {
                $this->dispatch('select-scan-input');
            }
        }
    }

    public function closeAmbiguityModal(): void
    {
        $this->showAmbiguityModal = false;
        $this->ambiguousCandidates = [];
        $this->ambiguousCondition = 'good';
        $this->dispatch('opname-ambiguity-closed');
        $this->dispatch('restore-scanner-focus');
    }

    /**
     * Apply a resolved match candidate to the opname table.
     * Returns true if application succeeded, false if rejected (e.g. duplicate serial, invalid conversion factor, or row creation failure).
     */
    protected function applyResolvedCandidate(array $candidate, ?string $targetCondition = null): bool
    {
        $type = $candidate['type'];
        $productData = $candidate['product'];
        $productId = (int) $productData['id'];

        $condition = in_array($targetCondition, ['good', 'bad'], true) ? $targetCondition : $this->activeCondition;
        $conditionLabel = $condition === 'good' ? 'Bagus' : 'Rusak';

        $existingIndex = $this->findProductRowIndex($productId);

        if ($type === 'product') {
            if ($productData['serial_number_required']) {
                // Serialized barcode: create row at 0 or focus without increment
                if ($existingIndex === null) {
                    $newIndex = $this->addProductRow($productData);
                    if ($newIndex === null) {
                        return false;
                    }
                    $this->feedbackMessage = "Produk berseri '{$productData['product_name']}' ditambahkan. Silakan masukkan nomor seri.";
                } else {
                    $this->feedbackMessage = "Produk berseri '{$productData['product_name']}' difokuskan (baris " . ($existingIndex + 1) . ").";
                }
                $this->feedbackType = 'info';
            } else {
                // Ordinary barcode: add 1 to target condition
                if ($existingIndex === null) {
                    $existingIndex = $this->addProductRow($productData);
                    if ($existingIndex === null) {
                        return false;
                    }
                }
                $this->incrementCount($existingIndex, $condition, 1);
                $this->feedbackMessage = "+1 ({$conditionLabel}) untuk '{$productData['product_name']}'.";
                $this->feedbackType = 'success';
            }
            return true;
        } elseif ($type === 'conversion') {
            $conversion = $candidate['conversion'];
            $factor = (float) $conversion['conversion_factor'];

            // Check if factor is an integer
            if (abs($factor - round($factor)) > 1e-6) {
                $this->feedbackMessage = "Faktor konversi {$factor} bukan bilangan bulat dan belum didukung untuk penyesuaian stok.";
                $this->feedbackType = 'warning';
                return false;
            }

            $factorInt = (int) round($factor);

            if ($productData['serial_number_required']) {
                // Serialized conversion barcode: create at 0 or focus without increment
                if ($existingIndex === null) {
                    $newIndex = $this->addProductRow($productData);
                    if ($newIndex === null) {
                        return false;
                    }
                    $this->feedbackMessage = "Produk berseri '{$productData['product_name']}' ditambahkan. Silakan masukkan nomor seri.";
                } else {
                    $this->feedbackMessage = "Produk berseri '{$productData['product_name']}' difokuskan.";
                }
                $this->feedbackType = 'info';
            } else {
                if ($existingIndex === null) {
                    $existingIndex = $this->addProductRow($productData);
                    if ($existingIndex === null) {
                        return false;
                    }
                }
                $this->incrementCount($existingIndex, $condition, $factorInt);
                $this->feedbackMessage = "+{$factorInt} satuan dasar ({$conversion['unit_name']}) {$conditionLabel} untuk '{$productData['product_name']}'.";
                $this->feedbackType = 'success';
            }
            return true;
        } elseif ($type === 'serial') {
            $serialData = $candidate['serial'];
            $serialText = ProductSerialNumber::normalize($serialData['serial_number']);

            if ($existingIndex === null) {
                $existingIndex = $this->addProductRow($productData);
                if ($existingIndex === null) {
                    return false;
                }
            }

            // Check if serial already exists on this product row
            $serials = $this->products[$existingIndex]['serial_numbers'] ?? [];
            if (collect($serials)->contains(fn($s) => ProductSerialNumber::normalize($s['serial_number']) === $serialText)) {
                $this->feedbackMessage = "Nomor seri '{$serialText}' sudah ada pada produk ini.";
                $this->feedbackType = 'warning';
                return false;
            }

            // Add serial entry
            $this->products[$existingIndex]['serial_numbers'][] = [
                'serial_number' => $serialText,
                'condition' => $condition,
                'source_serial_id' => (int) $serialData['id'],
                'source_location_id' => !empty($serialData['location_id']) ? (int) $serialData['location_id'] : null,
                'source_location_name' => $serialData['location_name'] ?? null,
                'source_status' => $serialData['status'] ?? null,
                'source_tax_id' => !empty($serialData['tax_id']) ? (int) $serialData['tax_id'] : null,
            ];

            $this->recalculateSerializedCounts($existingIndex);
            $this->feedbackMessage = "Nomor seri '{$serialText}' ditambahkan sebagai {$conditionLabel} untuk '{$productData['product_name']}'.";
            $this->feedbackType = 'success';
            return true;
        }

        return false;
    }

    /**
     * Add a product row initialized at zero counts with captured baseline.
     */
    protected function addProductRow(array $productData): ?int
    {
        $resolvedLocation = $this->resolveOwnedLocation($this->locationId);
        if (!$resolvedLocation) {
            $this->locationId = null;
            $this->feedbackMessage = 'Lokasi tidak ditemukan atau bukan milik pengaturan aktif.';
            $this->feedbackType = 'danger';
            return null;
        }

        $productId = (int) $productData['id'];
        $resolver = app(AdjustmentProductResolver::class);
        $baseline = $resolver->captureBaseline(
            $productId,
            (int) $resolvedLocation->id,
            auth()->id(),
            $this->draftSessionId,
            $this->adjustmentId
        );

        $isSerialized = (bool) ($productData['serial_number_required'] ?? false);
        $baseUnit = $productData['base_unit'] ?? ($productData['unit'] ?? '');

        $row = [
            'id' => $productId,
            'product_name' => $productData['product_name'],
            'product_code' => $productData['product_code'],
            'serial_number_required' => $isSerialized,
            'unit' => $baseUnit,
            'good_count' => 0,
            'bad_count' => 0,
            'serial_numbers' => [],
            'baseline' => $this->sanitizeBaseline($baseline),
            'quantity' => 0,
            'quantity_tax' => 0,
            'quantity_non_tax' => 0,
        ];

        $newIndex = count($this->products);
        $this->products[] = $row;
        $this->quantities[$newIndex] = ['tax' => 0, 'non_tax' => 0];
        $this->serialNumberErrors[$newIndex] = null;
        $this->hasAdjustments = true;

        return $newIndex;
    }

    protected function findProductRowIndex(int $productId): ?int
    {
        foreach ($this->products as $index => $row) {
            $rowId = (int) ($row['id'] ?? ($row['product']['id'] ?? 0));
            if ($rowId === $productId) {
                return $index;
            }
        }
        return null;
    }

    protected function incrementCount(int $index, string $condition, int $amount): void
    {
        if ($condition === 'good') {
            $this->products[$index]['good_count'] = max(0, (int) ($this->products[$index]['good_count'] ?? 0) + $amount);
        } else {
            $this->products[$index]['bad_count'] = max(0, (int) ($this->products[$index]['bad_count'] ?? 0) + $amount);
        }

        $this->products[$index]['quantity'] = (int) $this->products[$index]['good_count'];
    }

    /**
     * Recalculate good_count and bad_count for serialized product from serial entries.
     */
    protected function recalculateSerializedCounts(int $index): void
    {
        $serials = $this->products[$index]['serial_numbers'] ?? [];
        $good = 0;
        $bad = 0;

        foreach ($serials as $serial) {
            $cond = strtolower(trim($serial['condition'] ?? 'good'));
            if ($cond === 'bad') {
                $bad++;
            } else {
                $good++;
            }
        }

        $this->products[$index]['good_count'] = $good;
        $this->products[$index]['bad_count'] = $bad;
        $this->products[$index]['quantity'] = $good;
    }

    /**
     * Product search modal: perform search.
     */
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

    public function searchProducts(): void
    {
        $term = trim($this->searchTerm);
        if ($term === '') {
            $this->searchResults = [];
            return;
        }

        $resolver = app(AdjustmentProductResolver::class);
        $this->searchResults = $resolver->searchProducts($term, 10);
    }

    public function updatedSearchTerm(): void
    {
        $this->searchProducts();
    }

    /**
     * Selection handler from SearchProduct or internal search modal.
     */
    public function productSelected($product): void
    {
        Log::info('AdjustmentProductTable: productSelected', ['product' => $product]);

        if (!$this->locationId || !$this->resolveOwnedLocation($this->locationId)) {
            $this->locationId = null;
            session()->flash('message', 'Pilih lokasi terlebih dahulu sebelum menambahkan produk.');
            $this->feedbackMessage = 'Pilih lokasi terlebih dahulu sebelum menambahkan produk.';
            $this->feedbackType = 'warning';
            return;
        }

        $productId = (int) ($product['id'] ?? ($product['product']['id'] ?? 0));
        $existingIndex = $this->findProductRowIndex($productId);

        if ($existingIndex !== null) {
            $this->feedbackMessage = "Produk '{$this->products[$existingIndex]['product_name']}' sudah ada di daftar (baris " . ($existingIndex + 1) . ").";
            $this->feedbackType = 'info';
            $this->closeSearchModal();
            return;
        }

        // Authoritatively normalize product data
        $productEntity = Product::with(['baseUnit', 'conversions.unit'])->find($productId);
        if ($productEntity) {
            $resolver = app(AdjustmentProductResolver::class);
            $normalized = $resolver->normalizeProductData($productEntity);
            $addedIndex = $this->addProductRow($normalized);
            if ($addedIndex === null) {
                $this->closeSearchModal();
                return;
            }
            $this->feedbackMessage = "Produk '{$normalized['product_name']}' berhasil ditambahkan ke daftar.";
            $this->feedbackType = 'success';
        }

        $this->closeSearchModal();
    }

    /**
     * Row Serial Dialog: Open modal for row.
     */
    public function openSerialModal(int $rowIndex): void
    {
        if (!isset($this->products[$rowIndex])) {
            return;
        }

        $this->selectedRowIndexForSerials = $rowIndex;
        $this->rowSerialInput = '';
        $this->rowSerialCondition = $this->activeCondition;
        $this->rowSerialError = null;
        $this->showSerialModal = true;
    }

    public function closeSerialModal(): void
    {
        $this->showSerialModal = false;
        $this->selectedRowIndexForSerials = null;
        $this->rowSerialInput = '';
        $this->rowSerialError = null;
        $this->dispatch('restore-scanner-focus');
    }

    /**
     * Row Serial Dialog: Add serial text (known or raw unregistered).
     */
    public function addRowSerial(): void
    {
        $index = $this->selectedRowIndexForSerials;
        if ($index === null || !isset($this->products[$index])) {
            return;
        }

        $rawText = trim($this->rowSerialInput);
        if ($rawText === '') {
            $this->rowSerialError = 'Nomor seri tidak boleh kosong.';
            return;
        }

        $normalizedText = ProductSerialNumber::normalize($rawText);
        $serials = $this->products[$index]['serial_numbers'] ?? [];

        // Deduplicate across conditions on the same product row
        foreach ($serials as $existing) {
            if (ProductSerialNumber::normalize($existing['serial_number']) === $normalizedText) {
                $cond = $existing['condition'] === 'good' ? 'Bagus' : 'Rusak';
                $this->rowSerialError = "Nomor seri '{$normalizedText}' sudah tercatat pada produk ini (kondisi: {$cond}).";
                return;
            }
        }

        $productId = (int) $this->products[$index]['id'];

        // Check if matching source record exists in DB for this product (any location/status/flag)
        $sourceSerial = ProductSerialNumber::query()
            ->where('product_id', $productId)
            ->where('serial_number', $normalizedText)
            ->with('location')
            ->first();

        $entry = [
            'serial_number' => $normalizedText,
            'condition' => $this->rowSerialCondition,
            'source_serial_id' => $sourceSerial?->id,
            'source_location_id' => $sourceSerial?->location_id,
            'source_location_name' => $sourceSerial?->location?->name,
            'source_status' => $sourceSerial?->status,
            'source_tax_id' => $sourceSerial?->tax_id,
        ];

        $this->products[$index]['serial_numbers'][] = $entry;
        $this->recalculateSerializedCounts($index);

        $this->rowSerialInput = '';
        $this->rowSerialError = null;
    }

    /**
     * Row Serial Dialog: Reclassify serial condition (Good <-> Bad).
     */
    public function reclassifySerial(int $serialIndex, string $newCondition): void
    {
        $rowIndex = $this->selectedRowIndexForSerials;
        if ($rowIndex === null || !isset($this->products[$rowIndex]['serial_numbers'][$serialIndex])) {
            return;
        }

        if (in_array($newCondition, ['good', 'bad'], true)) {
            $this->products[$rowIndex]['serial_numbers'][$serialIndex]['condition'] = $newCondition;
            $this->recalculateSerializedCounts($rowIndex);
        }
    }

    /**
     * Row Serial Dialog: Remove serial entry.
     */
    public function removeRowSerial(int $serialIndex): void
    {
        $rowIndex = $this->selectedRowIndexForSerials;
        if ($rowIndex === null || !isset($this->products[$rowIndex]['serial_numbers'][$serialIndex])) {
            return;
        }

        unset($this->products[$rowIndex]['serial_numbers'][$serialIndex]);
        $this->products[$rowIndex]['serial_numbers'] = array_values($this->products[$rowIndex]['serial_numbers']);
        $this->recalculateSerializedCounts($rowIndex);
    }

    /**
     * Remove entire product row.
     */
    public function removeProduct($key): void
    {
        unset($this->products[$key], $this->quantities[$key], $this->serialNumberErrors[$key]);

        $this->products = array_values($this->products);
        $this->quantities = array_values($this->quantities);
        $this->serialNumberErrors = array_values($this->serialNumberErrors);

        if (count($this->products) === 0) {
            $this->hasAdjustments = false;
        }
    }

    /**
     * Compute count draft JSON payload for form submission.
     */
    public function getCountDraftPayloadProperty(): string
    {
        if (empty($this->locationId) || empty($this->products)) {
            return '';
        }

        $rows = [];
        foreach ($this->products as $product) {
            $isSerialized = (bool) ($product['serial_number_required'] ?? false);
            $rawGood = $product['good_count'] ?? 0;
            $rawBad = $product['bad_count'] ?? 0;

            $rows[] = [
                'product_id' => (int) $product['id'],
                'product_name' => $product['product_name'] ?? '',
                'product_code' => $product['product_code'] ?? '',
                'base_unit' => $product['unit'] ?? '',
                'is_serialized' => $isSerialized,
                'good_count' => $rawGood,
                'bad_count' => $rawBad,
                'serials' => $product['serial_numbers'] ?? [],
                'baseline' => $this->sanitizeBaseline($product['baseline'] ?? []),
            ];
        }

        $payload = [
            'schema_version' => CountDraftService::SCHEMA_VERSION,
            'location_id' => (int) $this->locationId,
            'draft_session_id' => $this->draftSessionId,
            'rows' => $rows,
        ];

        return json_encode($payload);
    }

    // ==========================================
    // Backward Compatibility Helpers for Legacy Tests
    // ==========================================

    protected function mapStoredSerialNumbers(array $storedSerials): array
    {
        if (empty($storedSerials)) {
            return [];
        }

        $ids = collect($storedSerials)->pluck('id')->filter()->toArray();

        $serials = ProductSerialNumber::whereIn('id', $ids)
            ->get(['id', 'serial_number', 'tax_id'])
            ->keyBy('id');

        return collect($storedSerials)->map(function ($item) use ($serials) {
            $id = $item['id'] ?? null;
            $serial = $serials[$id] ?? null;

            return [
                'id' => (int) $id,
                'serial_number' => $serial?->serial_number ?? ($item['serial_number'] ?? ''),
                'tax_id' => $serial?->tax_id,
                'taxable' => (bool) ($item['taxable'] ?? ($serial?->tax_id ? 1 : 0)),
                'condition' => $item['condition'] ?? 'good',
            ];
        })->toArray();
    }

    public function serialNumberSelected($index, $serialNumber): void
    {
        if (!isset($this->products[$index]) || !$this->products[$index]['serial_number_required']) {
            return;
        }

        if (collect($this->products[$index]['serial_numbers'] ?? [])
            ->pluck('id')->contains($serialNumber['id'])) {
            $this->serialNumberErrors[$index] = "Serial number '{$serialNumber['serial_number']}' sudah ada.";
            return;
        }

        unset($this->serialNumberErrors[$index]);

        $serial = ProductSerialNumber::find($serialNumber['id']);
        if (!$serial) {
            return;
        }

        $serialNumber['tax_id'] = $serial->tax_id;
        $serialNumber['taxable'] = (bool) $serial->tax_id;
        $serialNumber['condition'] = 'good';

        $this->products[$index]['serial_numbers'][] = $serialNumber;
        $this->recalculateSerializedCounts($index);

        if (!isset($this->quantities[$index])) {
            $this->quantities[$index] = ['tax' => 0, 'non_tax' => 0];
        }

        if ($serial->tax_id) {
            $this->quantities[$index]['tax']++;
        } else {
            $this->quantities[$index]['non_tax']++;
        }
    }

    public function removeSerialNumber($index, $serialIndex): void
    {
        if (!isset($this->products[$index]['serial_numbers'][$serialIndex])) {
            return;
        }

        $serial = $this->products[$index]['serial_numbers'][$serialIndex];
        $isTaxable = $serial['taxable'] ?? false;

        unset($this->products[$index]['serial_numbers'][$serialIndex]);
        $this->products[$index]['serial_numbers'] = array_values($this->products[$index]['serial_numbers']);
        $this->recalculateSerializedCounts($index);

        if (!isset($this->quantities[$index])) {
            $this->quantities[$index] = ['tax' => 0, 'non_tax' => 0];
        }

        if ($isTaxable) {
            $this->quantities[$index]['tax'] = max(0, $this->quantities[$index]['tax'] - 1);
        } else {
            $this->quantities[$index]['non_tax'] = max(0, $this->quantities[$index]['non_tax'] - 1);
        }
    }

    protected function getSerialNumbers($serial_numbers, $key): array
    {
        if (empty($serial_numbers) || empty($serial_numbers[$key])) {
            return [];
        }

        return $this->getSerialNumberByIds($serial_numbers[$key]);
    }

    protected function getSerialNumberByIds($serialNumberIds): array
    {
        if (empty($serialNumberIds)) {
            return [];
        }

        return ProductSerialNumber::whereIn('id', $serialNumberIds)
            ->get(['id', 'serial_number', 'tax_id'])
            ->map(function ($serial) {
                return [
                    'id' => $serial->id,
                    'serial_number' => $serial->serial_number,
                    'tax_id' => $serial->tax_id,
                    'taxable' => (bool) $serial->tax_id,
                    'condition' => 'good',
                ];
            })
            ->toArray();
    }

    public function getTotalQuantityProperty(): array
    {
        return collect($this->quantities)
            ->map(function ($row) {
                $tax = is_numeric($row['tax'] ?? null) ? (int) $row['tax'] : 0;
                $nonTax = is_numeric($row['non_tax'] ?? null) ? (int) $row['non_tax'] : 0;
                return $tax + $nonTax;
            })
            ->toArray();
    }

    public function toggleSerialTaxable($productIndex, $serialIndex): void
    {
        $serial = &$this->products[$productIndex]['serial_numbers'][$serialIndex];

        if (!isset($this->quantities[$productIndex])) {
            $this->quantities[$productIndex] = ['tax' => 0, 'non_tax' => 0];
        }

        if (!array_key_exists('taxable', $serial)) {
            return;
        }

        if ($serial['taxable']) {
            $this->quantities[$productIndex]['tax']++;
            $this->quantities[$productIndex]['non_tax'] = max(0, $this->quantities[$productIndex]['non_tax'] - 1);
        } else {
            $this->quantities[$productIndex]['non_tax']++;
            $this->quantities[$productIndex]['tax'] = max(0, $this->quantities[$productIndex]['tax'] - 1);
        }
    }
}
