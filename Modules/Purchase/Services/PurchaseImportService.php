<?php

namespace Modules\Purchase\Services;

use App\Support\ImportDocumentAdjustmentAllocator;
use App\Support\ImportDocumentAdjustmentResolver;
use App\Support\ImportPaymentSummaryResolver;
use App\Support\ImportSettlementAllocator;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Product;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Entities\PurchaseImportBatch;
use Modules\Purchase\Entities\PurchaseImportRow;
use Modules\Purchase\Entities\PurchasePayment;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Modules\Setting\Entities\Unit;
use Modules\Product\Entities\ProductPrice;
use Modules\Purchase\Entities\PaymentTerm;

class PurchaseImportService
{
    protected ImportDocumentAdjustmentResolver $documentAdjustmentResolver;

    protected ImportPaymentSummaryResolver $paymentSummaryResolver;

    protected ImportDocumentAdjustmentAllocator $documentAdjustmentAllocator;

    protected ImportSettlementAllocator $settlementAllocator;

    /**
     * Entity caches to avoid N+1 queries
     */
    protected array $settingsCache = [];
    protected array $suppliersCache = [];
    protected array $productsCache = [];
    protected array $taxesCache = [];
    protected array $unitsCache = [];

    /**
     * Owner-routing tag mapping (Priority 1).
     * Only these two tags route to a different setting; all others fall back to PERDANA.
     */
    protected array $tagMapping = [
        'cv tiga nusa' => 'CV TIGA NUSA COMPUTER',
        'cv top it' => 'CV TOP IT INTERNUSA',
        'aries' => 'TIGA COMPUTER',
        'rahmat' => 'WHITE KNIGHT COMPUTER',
        'agus' => 'DUNIA COMPUTER',
        'perdana' => 'PERDANA',
    ];



    public function __construct(
        ?ImportPaymentSummaryResolver $paymentSummaryResolver = null,
        ?ImportDocumentAdjustmentResolver $documentAdjustmentResolver = null,
        ?ImportDocumentAdjustmentAllocator $documentAdjustmentAllocator = null,
        ?ImportSettlementAllocator $settlementAllocator = null
    )
    {
        $this->paymentSummaryResolver = $paymentSummaryResolver ?? app(ImportPaymentSummaryResolver::class);
        $this->documentAdjustmentResolver = $documentAdjustmentResolver ?? app(ImportDocumentAdjustmentResolver::class);
        $this->documentAdjustmentAllocator = $documentAdjustmentAllocator ?? app(ImportDocumentAdjustmentAllocator::class);
        $this->settlementAllocator = $settlementAllocator ?? app(ImportSettlementAllocator::class);
    }

    /**
     * Normalize product name for Daizu detection.
     */
    protected function normalizeProductName(string $rawName): string
    {
        $normalized = strtoupper(trim($rawName));
        $normalized = preg_replace('/[^A-Z0-9\s]/', '', $normalized);
        $normalized = preg_replace('/\s+/', ' ', $normalized);
        return $normalized;
    }

    /**
     * Detect if a product name matches Daizu criteria (contains KEDELE, KEDELAI, or RAGI).
     */
    public function isDaizuProduct(string $rawName): bool
    {
        $normalized = $this->normalizeProductName($rawName);
        return preg_match('/\b(KEDELE|KEDELAI|RAGI)\b/', $normalized) === 1;
    }

    /**
     * Parse product name and extract marker type.
     *
     * @return array{clean_name: string, marker: string}
     */
    public function parseProductName(string $rawName): array
    {
        $name = trim($rawName);

        // Check for asterisk prefix
        if (str_starts_with($name, '*')) {
            return [
                'clean_name' => trim(ltrim($name, '* ')),
                'marker' => 'asterisk',
            ];
        }

        // Check for TP suffix
        if (str_ends_with($name, ' TP')) {
            return [
                'clean_name' => trim(substr($name, 0, -3)),
                'marker' => 'tp',
            ];
        }

        // No marker
        return [
            'clean_name' => $name,
            'marker' => 'default',
        ];
    }



    /**
     * Get the setting (tenant) for a given tag value (Priority 1).
     */
    public function getSettingForTag(?string $tag): ?Setting
    {
        if (empty($tag)) {
            return null;
        }

        $normalizedTag = strtolower(trim($tag));
        $companyName = $this->tagMapping[$normalizedTag] ?? null;

        if (!$companyName) {
            return null;
        }

        foreach ($this->settingsCache as $setting) {
            if (stripos($setting->company_name, $companyName) !== false) {
                return $setting;
            }
        }

        return Setting::where('company_name', 'LIKE', "%{$companyName}%")->first();
    }

    /**
     * Get the Daizu Kedelai setting or throw an exception if not found.
     */
    public function getDaizuSetting(): ?Setting
    {
        return Setting::where('company_name', 'LIKE', '%DAIZU%')->first();
    }

    /**
     * Resolve tenant using effective owner priority:
     * Daizu product (Priority 0), then mapped CSV Tag (Priority 1), then Perdana fallback (Priority 2).
     */
    public function resolveTenant(?string $tag, string $productName): ?Setting
    {
        // Priority 0: Check for Daizu product
        if ($this->isDaizuProduct($productName)) {
            $daizuSetting = $this->getDaizuSetting();
            if (!$daizuSetting) {
                throw new \Exception("Daizu Kedelai setting not found for product: {$productName}");
            }
            return $daizuSetting;
        }

        // Priority 1: Mapped CSV Tag
        $tagSetting = $this->getSettingForTag($tag);
        if ($tagSetting) {
            return $tagSetting;
        }

        // Priority 2: Perdana fallback
        return Setting::where('company_name', 'LIKE', '%PERDANA%')->first();
    }

    /**
     * Resolve a stable effective-owner grouping key for a row.
     * Daizu (Priority 0), then mapped CSV Tag (Priority 1), then perdana fallback (Priority 2).
     */
    public function resolveEffectiveOwnerKey(?string $tag, string $productName): string
    {
        if ($this->isDaizuProduct($productName)) {
            return 'daizu';
        }

        $normalizedTag = strtolower(trim((string) $tag));
        if ($normalizedTag !== '' && isset($this->tagMapping[$normalizedTag])) {
            return 'tag:' . $this->tagMapping[$normalizedTag];
        }

        return 'perdana';
    }



    /**
     * Parse tax rate from CSV string (e.g., "10.0" or "10.0 %").
     */
    public function parseTaxRate(?string $taxRateStr): int
    {
        if (empty($taxRateStr)) {
            return 0;
        }

        // Remove percentage sign and whitespace
        $cleaned = trim(str_replace(['%', ' '], '', $taxRateStr));

        if (!is_numeric($cleaned)) {
            return 0;
        }

        return (int) round((float) $cleaned);
    }

    /**
     * Parse discount percentage from CSV string (e.g., "0.00 %" or "7.26").
     */
    public function parseDiscountPercent(?string $discountStr): float
    {
        if (empty($discountStr)) {
            return 0.0;
        }

        // Remove percentage sign and whitespace
        $cleaned = trim(str_replace(['%', ' '], '', $discountStr));

        return is_numeric($cleaned) ? (float) $cleaned : 0.0;
    }

    /**
     * Parse a CSV quantity that may be fractional (e.g. "23.7" or "23,7" KG) into a float.
     * Quantities must not be truncated to an integer or line totals will mismatch the source.
     */
    public function parseQuantity(mixed $value): float
    {
        if ($value === null) {
            return 1.0;
        }

        $normalized = trim((string) $value);
        if ($normalized === '') {
            return 1.0;
        }

        // Treat a lone comma as a decimal separator (e.g. "23,7" -> "23.7").
        if (str_contains($normalized, ',') && !str_contains($normalized, '.')) {
            $normalized = str_replace(',', '.', $normalized);
        } else {
            // Otherwise drop thousands commas (e.g. "1,234.5" -> "1234.5").
            $normalized = str_replace(',', '', $normalized);
        }

        return is_numeric($normalized) ? (float) $normalized : 1.0;
    }

    /**
     * Calculate tax percentage from amounts.
     */
    public function calculateTaxPercentage(float $subtotal, float $taxAmount): int
    {
        if ($subtotal <= 0) {
            return 0;
        }

        return (int) round(($taxAmount / $subtotal) * 100);
    }

    /**
     * Find or create a tax with the given percentage.
     */
    public function findOrCreateTax(int $percentage): Tax
    {
        if (isset($this->taxesCache[$percentage])) {
            return $this->taxesCache[$percentage];
        }

        $tax = Tax::where('value', $percentage)->first();

        if (!$tax) {
            $tax = Tax::create([
                'name' => "PPN {$percentage}%",
                'value' => $percentage,
            ]);

            Log::info('[PurchaseImport] Created new tax', [
                'tax_id' => $tax->id,
                'percentage' => $percentage,
            ]);
        }

        $this->taxesCache[$percentage] = $tax;

        return $tax;
    }

    /**
     * Find or create a supplier by name.
     */
    public function findOrCreateSupplier(string $name, int $settingId, ?string $contactName = null, ?string $phone = null): Supplier
    {
        $normalizedName = strtolower(trim($name));

        if (isset($this->suppliersCache[$normalizedName])) {
            return $this->suppliersCache[$normalizedName];
        }

        $supplier = Supplier::whereRaw('LOWER(supplier_name) = ?', [$normalizedName])->first();

        if (!$supplier) {
            $supplier = Supplier::create([
                'supplier_name' => trim($name),
                'supplier_email' => '',
                'supplier_phone' => $phone ?? '',
                'contact_name' => $contactName,
                'address' => '',
                'city' => '',
                'country' => '',
                'setting_id' => $settingId,
            ]);

            Log::info('[PurchaseImport] Created new supplier', [
                'supplier_id' => $supplier->id,
                'name' => $name,
            ]);
        }

        $this->suppliersCache[$normalizedName] = $supplier;

        return $supplier;
    }

    /**
     * Find or create a product by cleaned name.
     */
    public function findOrCreateProduct(string $cleanName, string $unitName, int $settingId, ?string $description = null): Product
    {
        $normalizedName = strtolower(trim($cleanName));

        if (isset($this->productsCache[$normalizedName])) {
            return $this->productsCache[$normalizedName];
        }

        $product = Product::whereRaw('LOWER(product_name) = ?', [$normalizedName])->first();

        if (!$product) {
            $normalizedUnitName = strtolower(trim($unitName));
            $unit = $this->unitsCache[$normalizedUnitName] ?? null;

            if (!$unit) {
                $unit = Unit::whereRaw('LOWER(short_name) = ?', [$normalizedUnitName])->first();
                if (!$unit) {
                    $unit = Unit::create([
                        'name' => ucfirst(strtolower($unitName)),
                        'short_name' => strtoupper($unitName),
                    ]);
                }
                $this->unitsCache[$normalizedUnitName] = $unit;
            }

            $product = Product::create([
                'product_name' => trim($cleanName),
                'product_code' => 'SKU-' . strtoupper(substr(md5($cleanName), 0, 8)),
                'unit_id' => $unit->id,
                'setting_id' => $settingId,
                'product_cost' => 0,
                'product_price' => 0,
                'product_quantity' => 0,
                'stock_managed' => 1,
                'is_purchased' => 1,
                'is_sold' => 1,
                'product_note' => $description,
            ]);

            Log::info('[PurchaseImport] Created new product', [
                'product_id' => $product->id,
                'name' => $cleanName,
            ]);
        }

        $this->productsCache[$normalizedName] = $product;

        return $product;
    }

    /**
     * Parse date from DD/MM/YYYY format.
     */
    public function parseDate(string $dateStr): Carbon
    {
        return Carbon::createFromFormat('d/m/Y', trim($dateStr));
    }

    /**
     * Pre-load all settings and cache them.
     */
    protected function preloadSettings(): void
    {
        if (empty($this->settingsCache)) {
            $settings = Setting::all();
            foreach ($settings as $setting) {
                $this->settingsCache[$setting->id] = $setting;
            }
            Log::info('[PurchaseImport] Pre-loaded settings', ['count' => count($this->settingsCache)]);
        }
    }

    /**
     * Pre-load all taxes and cache them.
     */
    protected function preloadTaxes(): void
    {
        if (empty($this->taxesCache)) {
            $taxes = Tax::all();
            foreach ($taxes as $tax) {
                $this->taxesCache[$tax->value] = $tax;
            }
            Log::info('[PurchaseImport] Pre-loaded taxes', ['count' => count($this->taxesCache)]);
        }
    }

    /**
     * Pre-load all units and cache them.
     */
    protected function preloadUnits(): void
    {
        if (empty($this->unitsCache)) {
            $units = Unit::all();
            foreach ($units as $unit) {
                $this->unitsCache[strtolower($unit->short_name)] = $unit;
            }
            Log::info('[PurchaseImport] Pre-loaded units', ['count' => count($this->unitsCache)]);
        }
    }

    /**
     * Pre-load existing suppliers from the batch rows.
     */
    protected function preloadSuppliersForBatch(Collection $rows): void
    {
        $supplierNames = $rows->map(function ($row) {
            return trim($row->raw_json['supplier'] ?? '');
        })->filter()->unique()->values()->toArray();

        if (empty($supplierNames)) {
            return;
        }

        $suppliers = Supplier::whereIn('supplier_name', $supplierNames)->get();

        foreach ($suppliers as $supplier) {
            $this->suppliersCache[strtolower($supplier->supplier_name)] = $supplier;
        }

        Log::info('[PurchaseImport] Pre-loaded suppliers', ['count' => count($this->suppliersCache)]);
    }

    /**
     * Pre-load existing products from the batch rows.
     */
    protected function preloadProductsForBatch(Collection $rows): void
    {
        $productNames = $rows->map(function ($row) {
            $parsed = $this->parseProductName($row->raw_json['produk'] ?? '');
            return trim($parsed['clean_name']);
        })->filter()->unique()->values()->toArray();

        if (empty($productNames)) {
            return;
        }

        $products = Product::whereIn('product_name', $productNames)->get();

        foreach ($products as $product) {
            $this->productsCache[strtolower($product->product_name)] = $product;
        }

        Log::info('[PurchaseImport] Pre-loaded products', ['count' => count($this->productsCache)]);
    }

    /**
     * Process a batch of import rows.
     * Optimized with smart chunking and entity pre-loading to avoid N+1 queries
     * and memory issues with large batches.
     */
    public function processBatch(PurchaseImportBatch $batch): void
    {
        $startTime = microtime(true);
        $batch->update(['status' => PurchaseImportBatch::STATUS_PROCESSING]);

        try {
            // Pre-load all static entities ONCE
            $this->preloadSettings();
            $this->preloadTaxes();
            $this->preloadUnits();

            Log::info('[PurchaseImport] Starting batch processing', [
                'batch_id' => $batch->id,
                'total_rows' => $batch->total_rows,
                'preload_time' => round(microtime(true) - $startTime, 2) . 's',
            ]);

            $totalPending = $batch->pendingRows()->count();
            if ($totalPending === 0) {
                $batch->update(['status' => PurchaseImportBatch::STATUS_COMPLETED]);
                return;
            }

            $targetChunkSize = 500;
            $processedChunks = 0;
            $totalGroupsProcessed = 0;

            while (true) {
                $chunkStartTime = microtime(true);

                // Load initial chunk
                $initialRows = $batch->pendingRows()
                    ->orderBy('row_number')
                    ->take($targetChunkSize)
                    ->get();

                if ($initialRows->isEmpty()) {
                    break;
                }

                // Get the last row's invoice info to check for split groups
                $lastRow = $initialRows->last();
                $lastRowData = $lastRow->raw_json;
                $lastInvoiceNo = $lastRowData['no_faktur'] ?? '';

                $additionalRows = collect([]);
                if (!empty($lastInvoiceNo)) {
                    $lastRowNumber = $lastRow->row_number;

                    $additionalRows = $batch->pendingRows()
                        ->where('row_number', '>', $lastRowNumber)
                        ->orderBy('row_number')
                        ->get()
                        ->takeWhile(function ($row) use ($lastInvoiceNo) {
                            $rowData = $row->raw_json;
                            $rowInvoiceNo = $rowData['no_faktur'] ?? '';

                            return $rowInvoiceNo === $lastInvoiceNo;
                        });
                }

                $rows = $initialRows->merge($additionalRows);
                $actualChunkSize = $rows->count();
                $processedChunks++;

                Log::info('[PurchaseImport] Processing chunk', [
                    'batch_id' => $batch->id,
                    'chunk_number' => $processedChunks,
                    'target_size' => $targetChunkSize,
                    'actual_size' => $actualChunkSize,
                    'additional_rows' => $additionalRows->count(),
                ]);

                // Pre-load specific to this chunk
                $this->preloadSuppliersForBatch($rows);
                $this->preloadProductsForBatch($rows);

                // Group rows
                $groups = $this->groupRowsByInvoiceAndTenant($rows);

                // Process groups
                $groupsProcessed = $this->processGroupsInBatches($groups, $batch, 50);
                $totalGroupsProcessed += $groupsProcessed;

                Log::info('[PurchaseImport] Chunk completed', [
                    'batch_id' => $batch->id,
                    'chunk_number' => $processedChunks,
                    'groups_in_chunk' => count($groups),
                    'chunk_time' => round(microtime(true) - $chunkStartTime, 2) . 's',
                    'cumulative_time' => round(microtime(true) - $startTime, 2) . 's',
                ]);

                // Clear chunk caches
                $this->suppliersCache = [];
                $this->productsCache = [];
                gc_collect_cycles();
            }

            // Update batch status
            $batch->refresh();
            $batch->update([
                'status' => PurchaseImportBatch::STATUS_COMPLETED,
                'processed_rows' => $batch->rows()->whereIn('status', ['processed', 'invalid', 'skipped'])->count(),
            ]);

            $totalTime = microtime(true) - $startTime;
            $totalRows = $batch->total_rows;
            $rowsPerSecond = $totalRows > 0 ? round($totalRows / $totalTime, 2) : 0;

            Log::info('[PurchaseImport] Batch completed', [
                'batch_id' => $batch->id,
                'success_count' => $batch->success_count,
                'error_count' => $batch->error_count,
                'total_chunks' => $processedChunks,
                'total_groups' => $totalGroupsProcessed,
                'total_time' => round($totalTime, 2) . 's',
                'rows_per_second' => $rowsPerSecond,
            ]);
        } catch (\Exception $e) {
            $batch->update(['status' => PurchaseImportBatch::STATUS_FAILED]);
            Log::error('[PurchaseImport] Batch failed', [
                'batch_id' => $batch->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Process source invoices in transaction batches to reduce overhead.
     * Each source invoice is reconciled and its owner groups created within one transaction
     * so a payment mismatch rolls back every owner group for that invoice.
     */
    protected function processGroupsInBatches(array $groups, PurchaseImportBatch $batch, int $batchSize): int
    {
        $invoices = $this->groupOwnerGroupsBySourceInvoice($groups);

        $invoiceChunks = array_chunk($invoices, $batchSize, true);

        $processedCount = 0;
        $chunkSuccessCount = 0;
        $chunkErrorCount = 0;

        foreach ($invoiceChunks as $invoiceChunk) {
            foreach ($invoiceChunk as $invoiceNo => $ownerGroups) {
                $invoicePriceUpdates = [];
                $invoiceSuccessCount = 0;
                try {
                    DB::transaction(function () use ($ownerGroups, $batch, &$invoicePriceUpdates, &$invoiceSuccessCount) {
                        $this->processSourceInvoice($ownerGroups, $batch, $invoicePriceUpdates, $invoiceSuccessCount);
                    });
                    $processedCount += count($ownerGroups);
                    $chunkSuccessCount += $invoiceSuccessCount;
                } catch (\Exception $e) {
                    foreach ($ownerGroups as $groupRows) {
                        $this->markInvoiceGroupInvalid($groupRows, $batch, $e, $chunkErrorCount);
                    }
                }
            }
        }

        if ($chunkSuccessCount > 0) {
            $batch->increment('success_count', $chunkSuccessCount);
        }

        if ($chunkErrorCount > 0) {
            $batch->increment('error_count', $chunkErrorCount);
        }

        return $processedCount;
    }

    /**
     * Group rows by invoice number and effective owner key.
     * Effective owner: mapped CSV Tag. Unmapped tags route to default PERDANA.
     */
    protected function groupRowsByInvoiceAndTenant(Collection $rows): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $data = $row->raw_json;
            $invoiceNo = $data['no_faktur'] ?? '';
            $productName = $data['produk'] ?? '';
            $tag = $data['tag'] ?? null;

            $tenantKey = $this->resolveEffectiveOwnerKey($tag, $productName);

            $key = "{$invoiceNo}|{$tenantKey}";

            if (!isset($groups[$key])) {
                $groups[$key] = [];
            }
            $groups[$key][] = $row;
        }

        return $groups;
    }

    /**
     * Reorganize "invoice|owner" groups into a nested map keyed by source invoice number.
     *
     * @param  array<string, array<int, PurchaseImportRow>>  $groups
     * @return array<string, array<string, array<int, PurchaseImportRow>>>
     */
    protected function groupOwnerGroupsBySourceInvoice(array $groups): array
    {
        $invoices = [];

        foreach ($groups as $groupKey => $groupRows) {
            $invoiceNo = $groupRows[0]->raw_json['no_faktur'] ?? '';
            $invoices[$invoiceNo][$groupKey] = $groupRows;
        }

        return $invoices;
    }

    /**
     * Calculate an owner group's gross document total (line totals plus tax) using the same line
     * rules as document creation, BEFORE any document-level discount or shipping adjustment.
     *
     * Document-level Diskon/Biaya Pengiriman are invoice-scoped values repeated on every source
     * row, so they are allocated across owner groups at source-invoice scope rather than applied
     * in full to each group (which would double-count them on a split-owner invoice).
     *
     * @param  array<int, PurchaseImportRow>  $rows
     */
    protected function calculateGroupGrossTotal(array $rows): float
    {
        $totalAmount = 0.0;
        $totalTaxAmount = 0.0;

        foreach ($rows as $row) {
            $rowData = $row->raw_json;

            $quantity = $this->parseQuantity($rowData['kuantitas'] ?? null);
            $unitPriceDpp = (float) ($rowData['harga_satuan'] ?? 0);

            $discountPercent = $this->parseDiscountPercent($rowData['diskon_persen'] ?? '0');
            $dppAfterDiscount = $unitPriceDpp - ($unitPriceDpp * ($discountPercent / 100));

            $csvPajakStr = $rowData['pajak'] ?? null;
            $hasCsvPajak = $csvPajakStr !== null && trim($csvPajakStr) !== '' && (float) $csvPajakStr != 0;

            if ($hasCsvPajak) {
                $lineTaxAmount = (float) $csvPajakStr;
            } else {
                $taxRateFromCsv = $this->parseTaxRate($rowData['tarif_pajak'] ?? null);
                $lineTaxAmount = $taxRateFromCsv > 0 ? $dppAfterDiscount * $quantity * ($taxRateFromCsv / 100) : 0;
            }

            $totalAmount += $dppAfterDiscount * $quantity;
            $totalTaxAmount += $lineTaxAmount;
        }

        return round($totalAmount + $totalTaxAmount, 2);
    }

    /**
     * Process all owner groups for a single source invoice: reconcile invoice-level payment
     * fields once, allocate paid/due pro-rata across positive-total owner groups, and create
     * each owner document. A reconciliation failure throws and rolls back the whole invoice.
     *
     * @param  array<string, array<int, PurchaseImportRow>>  $ownerGroups
     */
    protected function processSourceInvoice(array $ownerGroups, PurchaseImportBatch $batch, array &$invoicePriceUpdates = [], int &$invoiceSuccessCount = 0): void
    {
        // Compute each owner group's gross total and reconcile payment at source-invoice scope.
        $groupGrossTotals = [];
        $allRows = [];
        foreach ($ownerGroups as $groupKey => $groupRows) {
            $groupGrossTotals[$groupKey] = $this->calculateGroupGrossTotal($groupRows);
            foreach ($groupRows as $row) {
                $allRows[] = $row->raw_json;
            }
        }

        // Document-level Diskon/Biaya Pengiriman are invoice-scoped: resolve once across all rows,
        // then allocate pro-rata across owner groups so the summed owner documents reconcile back
        // to the source invoice total.
        $documentDiscount = $this->documentAdjustmentResolver->resolve($allRows, 'diskon', 'Diskon');
        $documentShipping = $this->documentAdjustmentResolver->resolve($allRows, 'biaya_pengiriman', 'Biaya Pengiriman');

        $discountAllocations = $this->documentAdjustmentAllocator->allocate($groupGrossTotals, $documentDiscount);
        $shippingAllocations = $this->documentAdjustmentAllocator->allocate($groupGrossTotals, $documentShipping);

        $grossSum = array_sum($groupGrossTotals);
        $totalA = round($grossSum + $documentShipping, 2);
        $totalB = round($grossSum - $documentDiscount + $documentShipping, 2);

        $errorA = null;
        $errorB = null;
        $summaryB = null;

        try {
            $summaryB = $this->paymentSummaryResolver->resolveForPurchase($allRows, $totalB);
        } catch (\RuntimeException $e) {
            $errorB = $e;
        }

        try {
            $this->paymentSummaryResolver->resolveForPurchase($allRows, $totalA);
        } catch (\RuntimeException $e) {
            $errorA = $e;
        }

        $applyDiscountToTotal = true;
        if ($errorB && !$errorA) {
            $applyDiscountToTotal = false;
        } elseif (!$errorB && !$errorA) {
            $sourceTotal = $summaryB['source_total'] ?? null;
            if ($sourceTotal !== null) {
                if (abs($sourceTotal - $totalA) < abs($sourceTotal - $totalB)) {
                    $applyDiscountToTotal = false;
                }
            }
        } elseif ($errorB && $errorA) {
            throw $errorB;
        }

        $groupTotals = [];
        $appliedDiscounts = [];
        foreach ($groupGrossTotals as $groupKey => $grossTotal) {
            $appliedDiscount = $applyDiscountToTotal ? ($discountAllocations[$groupKey] ?? 0.0) : 0.0;
            $appliedDiscounts[$groupKey] = $appliedDiscount;
            $groupTotals[$groupKey] = round(
                $grossTotal - $appliedDiscount + ($shippingAllocations[$groupKey] ?? 0.0),
                2
            );
        }

        $sourceInvoiceTotal = round(array_sum($groupTotals), 2);
        $paymentSummary = $this->paymentSummaryResolver->resolveForPurchase($allRows, $sourceInvoiceTotal);

        // Purchase imports treat CSV Total as the document-level source of truth. Line totals still
        // create the item details, then any document-total drift is carried as an import-level
        // discount/shipping adjustment before settlement is allocated.
        $sourceTotal = $paymentSummary['source_total'] ?? null;
        if ($sourceTotal !== null) {
            $sourceDelta = round($sourceTotal - $sourceInvoiceTotal, 2);

            if (abs($sourceDelta) > 0.01) {
                $sourceTotalAdjustments = $this->documentAdjustmentAllocator->allocate($groupTotals, abs($sourceDelta));

                foreach ($groupTotals as $groupKey => $groupTotal) {
                    $adjustment = round($sourceTotalAdjustments[$groupKey] ?? 0.0, 2);

                    if ($sourceDelta > 0) {
                        $shippingAllocations[$groupKey] = round(($shippingAllocations[$groupKey] ?? 0.0) + $adjustment, 2);
                        $groupTotals[$groupKey] = round($groupTotal + $adjustment, 2);
                    } else {
                        $appliedDiscounts[$groupKey] = round(($appliedDiscounts[$groupKey] ?? 0.0) + $adjustment, 2);
                        $groupTotals[$groupKey] = round($groupTotal - $adjustment, 2);
                    }

                    if ($groupTotals[$groupKey] < -0.01) {
                        throw new \RuntimeException('Payment total mismatch: source Total adjustment would make an owner document total negative.');
                    }
                }

                $postAdjustmentTotal = round(array_sum($groupTotals), 2);
                $remainder = round($sourceTotal - $postAdjustmentTotal, 2);
                if (abs($remainder) > 0.0) {
                    $remainderKey = array_key_first($groupTotals);
                    foreach ($groupTotals as $groupKey => $groupTotal) {
                        if ($groupTotal > ($groupTotals[$remainderKey] ?? -INF)) {
                            $remainderKey = $groupKey;
                        }
                    }

                    if ($remainder > 0) {
                        $shippingAllocations[$remainderKey] = round(($shippingAllocations[$remainderKey] ?? 0.0) + $remainder, 2);
                        $groupTotals[$remainderKey] = round($groupTotals[$remainderKey] + $remainder, 2);
                    } else {
                        $appliedDiscounts[$remainderKey] = round(($appliedDiscounts[$remainderKey] ?? 0.0) + abs($remainder), 2);
                        $groupTotals[$remainderKey] = round($groupTotals[$remainderKey] + $remainder, 2);
                    }

                    if ($groupTotals[$remainderKey] < -0.01) {
                        throw new \RuntimeException('Payment total mismatch: source Total adjustment would make an owner document total negative.');
                    }
                }

                $sourceInvoiceTotal = round(array_sum($groupTotals), 2);
                $paymentSummary = $this->paymentSummaryResolver->resolveForPurchase($allRows, $sourceInvoiceTotal);
            }
        }

        // Split the settlement (cash Pembayaran, non-cash Jumlah Pemotongan credit, outstanding
        // due) across owner groups so each owner satisfies cash + deduction + due == group total
        // with all three non-negative. The settlement allocator derives the components from a
        // shared pro-rata base so a tiny group can never be over-settled into a negative due.
        $settlements = $this->settlementAllocator->allocate(
            $groupTotals,
            $paymentSummary['paid_amount'],
            $paymentSummary['deduction_amount'] ?? 0.0
        );

        foreach ($ownerGroups as $groupKey => $groupRows) {
            $settlement = $settlements[$groupKey] ?? ['cash' => 0.0, 'deduction' => 0.0, 'due' => 0.0];

            $this->processInvoiceGroup(
                $groupRows,
                $batch,
                $settlement['cash'],
                $settlement['due'],
                $appliedDiscounts[$groupKey] ?? 0.0,
                $shippingAllocations[$groupKey] ?? 0.0,
                $settlement['deduction'],
                $invoicePriceUpdates,
                $invoiceSuccessCount
            );
        }

        if (!empty($invoicePriceUpdates)) {
            $this->flushPurchasePriceUpdatesAcrossSettings($invoicePriceUpdates);
        }
    }

    /**
     * Process a group of rows belonging to the same invoice and effective owner.
     * Paid/due amounts are pre-allocated at source-invoice scope.
     */
    protected function processInvoiceGroup(array $rows, PurchaseImportBatch $batch, float $allocatedPaid = 0.0, float $allocatedDue = 0.0, float $allocatedDiscount = 0.0, float $allocatedShipping = 0.0, float $allocatedDeduction = 0.0, array &$invoicePriceUpdates = [], int &$invoiceSuccessCount = 0): void
    {
        if (empty($rows)) {
            return;
        }

        $firstRow = $rows[0];
        $data = $firstRow->raw_json;

        // Collect all distinct non-empty tags from every row in the group (rows may carry different tags)
        $allTags = array_values(array_unique(array_filter(array_map(
            fn($r) => trim($r->raw_json['tag'] ?? ''),
            $rows
        ))));

        // Resolve tenant using Tag (owner-routing rule)
        $tag = $data['tag'] ?? null;
        $productName = $data['produk'] ?? '';
        $setting = $this->resolveTenant($tag, $productName);

        if (!$setting) {
            throw new \Exception("Tenant not found for product: '{$productName}'");
        }

            // Check for duplicate purchase (same supplier_purchase_number + setting_id)
            $invoiceNo = $data['no_faktur'] ?? null;
            if ($invoiceNo) {
                $existingPurchase = Purchase::where('supplier_purchase_number', $invoiceNo)
                    ->where('setting_id', $setting->id)
                    ->first();

                if ($existingPurchase) {
                    $rowIds = array_map(fn($r) => $r->id, $rows);
                    PurchaseImportRow::whereIn('id', $rowIds)->update([
                        'status' => PurchaseImportRow::STATUS_SKIPPED,
                        'error_message' => "Skipped: Purchase with invoice #{$invoiceNo} already exists (ID: {$existingPurchase->id})",
                        'purchase_id' => $existingPurchase->id,
                    ]);
                    Log::info('[PurchaseImport] Skipped duplicate purchase', [
                        'batch_id' => $batch->id,
                        'no_faktur' => $invoiceNo,
                        'existing_purchase_id' => $existingPurchase->id,
                        'setting_id' => $setting->id,
                        'rows_skipped' => count($rows),
                    ]);
                    // Include skipped rows in processed_rows without incrementing success_count
                    // success_count is only incremented for rows that create or update records
                    return;
                }
            }
            // Validate Daizu setting and location early
            $isDaizuInvoice = false;
            foreach ($rows as $row) {
                $rowData = $row->raw_json;
                if ($this->isDaizuProduct($rowData['produk'] ?? '')) {
                    $isDaizuInvoice = true;
                    break;
                }
            }

            if ($isDaizuInvoice) {
                if (!$setting || !str_contains(strtolower($setting->company_name ?? ''), 'daizu')) {
                    throw new \Exception("Daizu product detected but setting is not Daizu Kedelai");
                }
            }



            // Parse dates
            $purchaseDate = $this->parseDate($data['tanggal']);
            $dueDate = !empty($data['tanggal_jatuh_tempo'])
                ? $this->parseDate($data['tanggal_jatuh_tempo'])
                : $purchaseDate;

            // Find or create supplier with additional fields
            $supplier = $this->findOrCreateSupplier(
                $data['supplier'],
                $setting->id,
                $data['nama_perusahaan'] ?? null,
                $data['nomor_telepon'] ?? null
            );

            // Document-level Diskon/Biaya Pengiriman are reconciled and allocated pro-rata at
            // source-invoice scope; this group receives its allocated share.
            $documentDiscount = round($allocatedDiscount, 2);
            $documentShipping = round($allocatedShipping, 2);

            // Calculate totals
            $totalAmount = 0;
            $totalTaxAmount = 0;
            $details = [];

            foreach ($rows as $row) {
                $rowData = $row->raw_json;
                $parsedProduct = $this->parseProductName($rowData['produk'] ?? '');
                $rawProductName = $rowData['produk'] ?? '';

                // Find or create product with description
                $product = $this->findOrCreateProduct(
                    $parsedProduct['clean_name'],
                    $rowData['satuan'] ?? 'PCS',
                    $setting->id,
                    $rowData['deskripsi'] ?? null
                );

                $quantity = $this->parseQuantity($rowData['kuantitas'] ?? null);
                $unitPriceDpp = (float) ($rowData['harga_satuan'] ?? 0); // DPP from CSV

                // Parse discount percentage (e.g., "0.00 %" or "7.26")
                $discountPercent = $this->parseDiscountPercent($rowData['diskon_persen'] ?? '0');
                $discountAmount = $unitPriceDpp * ($discountPercent / 100);
                $dppAfterDiscount = $unitPriceDpp - $discountAmount;

                $csvPajakStr = $rowData['pajak'] ?? null;
                $hasCsvPajak = $csvPajakStr !== null && trim($csvPajakStr) !== '' && (float) $csvPajakStr != 0;

                if ($hasCsvPajak) {
                    $lineTaxAmount = (float) $csvPajakStr;
                } else {
                    $taxRateFromCsv = $this->parseTaxRate($rowData['tarif_pajak'] ?? null);
                    $lineTaxAmount = $taxRateFromCsv > 0 ? $dppAfterDiscount * $quantity * ($taxRateFromCsv / 100) : 0;
                }

                // Calculate subtotals
                $subtotalDpp = $dppAfterDiscount * $quantity;
                $subtotalWithTax = $subtotalDpp + $lineTaxAmount;

                // Calculate unit price with tax (Inclusive)
                // If quantity is 0, avoid division by zero (though quantity defaults to 1)
                $unitTaxAmount = $quantity > 0 ? ($lineTaxAmount / $quantity) : 0;
                $unitPriceWithTax = $dppAfterDiscount + $unitTaxAmount;

                $totalAmount += $subtotalDpp; // Base amount (DPP) for totals
                $totalTaxAmount += $lineTaxAmount;

                $tax = null;
                $taxRateFromCsv = $this->parseTaxRate($rowData['tarif_pajak'] ?? null);
                if ($taxRateFromCsv > 0) {
                    $tax = $this->findOrCreateTax($taxRateFromCsv);
                } else {
                    // Fallback: calculate from actual tax amount
                    if ($lineTaxAmount > 0 && $subtotalDpp > 0) {
                        $taxPercentage = $this->calculateTaxPercentage($subtotalDpp, $lineTaxAmount);
                        $tax = $taxPercentage > 0 ? $this->findOrCreateTax($taxPercentage) : null;
                    }
                }

                $details[] = [
                    'row' => $row,
                    'product' => $product,
                    'raw_product_name' => $rawProductName,
                    'quantity' => $quantity,
                    'unit_price' => $unitPriceWithTax, // Store Tax Included Price as requested
                    'unit_price_final' => $unitPriceWithTax, // Final price including tax for ProductPrice updates
                    'subtotal' => $subtotalWithTax, // Subtotal Tax Included
                    'tax_id' => $tax?->id,
                    'tax_amount' => $lineTaxAmount,
                    'discount_percent' => $discountPercent,
                    'discount_amount' => $discountAmount * $quantity, // Total discount for this line
                ];
            }

            // Generate reference number based on purchase date
            $reference = $this->generateReference($setting, $purchaseDate);

            // Find payment term with longevity = 0 (immediate payment)
            $paymentTerm = PaymentTerm::where('longevity', 0)->first();

            $totalWithTax = $totalAmount + $totalTaxAmount;
            $adjustedTotalWithTax = round($totalWithTax - $allocatedDiscount + $allocatedShipping, 2);

            // Payment is reconciled once at source-invoice scope and allocated to this owner group.
            // Cash payment (Pembayaran) and the non-cash deduction (Jumlah Pemotongan) both settle
            // the invoice: header paid_amount = cash + deduction so paid + due = total, with each
            // positive settlement component recorded as its own active payment row.
            $cashPaidAmount = round($allocatedPaid, 2);
            $deductionAmount = round($allocatedDeduction, 2);
            $dueAmount = round($allocatedDue, 2);
            $paidAmount = round($cashPaidAmount + $deductionAmount, 2);
            $needsPayment = $cashPaidAmount > 0.01;

            $cashPaymentMethod = null;
            if ($needsPayment) {
                $cashPaymentMethod = $this->paymentSummaryResolver->resolveCashPaymentMethod();

                if (! $cashPaymentMethod) {
                    throw new \RuntimeException('Cash payment method is required for paid imports.');
                }
            }

            $paymentStatus = $dueAmount <= 0.01 ? 'PAID' : ($paidAmount > 0.01 ? 'PARTIAL' : 'UNPAID');

            // Create purchase
            $purchase = new Purchase();
            $purchase->date = $purchaseDate;
            $purchase->due_date = $dueDate;
            $purchase->reference = $reference;
            $purchase->supplier_id = $supplier->id;
            $purchase->payment_term_id = $paymentTerm?->id;
            $purchase->total_amount = $adjustedTotalWithTax;
            $purchase->tax_amount = $totalTaxAmount;
            $purchase->tax_percentage = $totalAmount > 0 ? round(($totalTaxAmount / $totalAmount) * 100) : 0;
            $purchase->discount_percentage = 0;
            $purchase->discount_amount = $documentDiscount;
            $purchase->shipping_amount = $documentShipping;
            $purchase->paid_amount = $paidAmount;
            $purchase->due_amount = $dueAmount;
            $purchase->status = Purchase::STATUS_RECEIVED;
            $purchase->payment_status = $paymentStatus;
            $purchase->payment_method = $cashPaymentMethod?->name ?? '';
            $purchase->setting_id = $setting->id;
            $purchase->supplier_purchase_number = $data['no_faktur'] ?? null;
            $purchase->note = $data['memo'] ?? null;
            $purchase->tax_ref_no = $data['nomor_pajak'] ?? null;
            $purchase->is_tax_included = $totalTaxAmount > 0;
            $purchase->save();

            // Sync all distinct tags from every row in the group
            if (!empty($allTags)) {
                $purchase->syncTags($allTags);
            }

            // Imports are accounting/document imports and do not mutate stock

            // Create purchase details and collect purchase-price updates.
            foreach ($details as $detail) {
                $purchaseDetail = PurchaseDetail::create([
                    'purchase_id' => $purchase->id,
                    'product_id' => $detail['product']->id,
                    'product_name' => $detail['product']->product_name,
                    'product_code' => $detail['product']->product_code,
                    'quantity' => $detail['quantity'],
                    'price' => $detail['unit_price'],
                    'unit_price' => $detail['unit_price'],
                    'sub_total' => $detail['subtotal'],
                    'product_discount_amount' => $detail['discount_amount'] ?? 0,
                    'product_discount_type' => ($detail['discount_percent'] ?? 0) > 0 ? 'percentage' : 'fixed',
                    'product_tax_amount' => $detail['tax_amount'],
                    'tax_id' => $detail['tax_id'],
                ]);

                $product = $detail['product'];
                $unitPriceFinal = $detail['unit_price_final'];

                // Accumulate purchase-price updates for invoice-level deduplication
                // existing average_purchase_price is preserved during upsert; new rows default to 0.
                if (!isset($invoicePriceUpdates[$setting->id])) {
                    $invoicePriceUpdates[$setting->id] = [];
                }
                $invoicePriceUpdates[$setting->id][$product->id] = [
                    'last_purchase_price' => $unitPriceFinal,
                    'average_purchase_price' => 0.0,
                ];
            }

            if ($needsPayment && $cashPaymentMethod) {
                PurchasePayment::create([
                    'purchase_id' => $purchase->id,
                    'payment_method_id' => $cashPaymentMethod->id,
                    'amount' => $cashPaidAmount,
                    'date' => $purchaseDate,
                    'reference' => $purchase->reference,
                    'payment_method' => $cashPaymentMethod->name,
                ]);
            }

            // Record the Jumlah Pemotongan as a separate non-cash settlement credit so reports,
            // which derive paid from active payment rows, see the invoice as fully settled.
            if ($deductionAmount > 0.01) {
                $deductionPaymentMethod = $this->paymentSummaryResolver->resolveDeductionPaymentMethod();
                PurchasePayment::create([
                    'purchase_id' => $purchase->id,
                    'payment_method_id' => $deductionPaymentMethod->id,
                    'amount' => $deductionAmount,
                    'date' => $purchaseDate,
                    'reference' => $purchase->reference,
                    'payment_method' => $deductionPaymentMethod->name,
                ]);
            }

            $rowIds = array_map(function ($detail) {
                return $detail['row']->id;
            }, $details);

            if (!empty($rowIds)) {
                PurchaseImportRow::whereIn('id', $rowIds)->update([
                    'status' => PurchaseImportRow::STATUS_PROCESSED,
                    'purchase_id' => $purchase->id,
                ]);
                $invoiceSuccessCount += count($rowIds);
            }

            Log::info('[PurchaseImport] Created purchase', [
                'purchase_id' => $purchase->id,
                'reference' => $reference,
                'setting_id' => $setting->id,
                'details_count' => count($details),
            ]);
    }

    protected function markInvoiceGroupInvalid(array $rows, PurchaseImportBatch $batch, \Exception $e, int &$chunkErrorCount = 0): void
    {
        $invoice = $rows[0]->raw_json['no_faktur'] ?? 'Unknown';
        $rowIds = [];

        foreach ($rows as $row) {
            $rowIds[] = $row->id;
            Log::warning('[PurchaseImport] Row error - exception', [
                'batch_id' => $batch->id,
                'row_id' => $row->id,
                'row_number' => $row->row_number,
                'no_faktur' => $invoice,
                'error' => $e->getMessage(),
                'raw_data' => $row->raw_json,
            ]);
        }

        if (!empty($rowIds)) {
            PurchaseImportRow::whereIn('id', $rowIds)->update([
                'status' => PurchaseImportRow::STATUS_INVALID,
                'error_message' => substr($e->getMessage(), 0, 255),
            ]);
            $chunkErrorCount += count($rowIds);
        }

        Log::error('[PurchaseImport] Failed to process invoice group', [
            'invoice' => $invoice,
            'error' => $e->getMessage(),
        ]);
    }



    protected array $allSettingIdsCache = [];

    protected function getAllSettingIds(): array
    {
        if (empty($this->allSettingIdsCache)) {
            $this->allSettingIdsCache = Setting::pluck('id')->toArray();
        }
        return $this->allSettingIdsCache;
    }

    /**
     * Upsert accumulated purchase-price fields across requested settings for a chunk.
     */
    public function flushPurchasePriceUpdatesAcrossSettings(array $chunkPriceUpdatesBySetting): void
    {
        $productIds = [];
        foreach ($chunkPriceUpdatesBySetting as $settingId => $chunkPriceUpdates) {
            foreach ($chunkPriceUpdates as $productId => $prices) {
                // Keep the latest prices for each product regardless of the setting it was imported for
                $productIds[$productId] = $prices;
            }
        }

        $currentAverages = \Modules\Product\Entities\ProductPrice::whereIn('product_id', array_keys($productIds))
            ->whereNotNull('average_purchase_price')
            ->pluck('average_purchase_price', 'product_id');

        $records = [];
        $now = now();
        $allSettings = $this->getAllSettingIds();

        foreach ($productIds as $productId => $prices) {
            foreach ($allSettings as $settingId) {
                $records[] = [
                    'product_id' => $productId,
                    'setting_id' => $settingId,
                    'sale_price' => 0,
                    'tier_1_price' => 0,
                    'tier_2_price' => 0,
                    'last_purchase_price' => $prices['last_purchase_price'],
                    'average_purchase_price' => $currentAverages[$productId] ?? 0.0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if (!empty($records)) {
            foreach (array_chunk($records, 1000) as $recordChunk) {
                ProductPrice::upsert(
                    $recordChunk,
                    ['product_id', 'setting_id'],
                    ['last_purchase_price', 'updated_at']
                );
            }
        }
    }

    /**
     * Generate reference number based on purchase date.
     */
    protected function generateReference(Setting $setting, Carbon $date): string
    {
        $year = $date->year;
        $month = $date->month;

        // Get latest reference for this setting, year, month
        $latestRef = Purchase::where('setting_id', $setting->id)
            ->whereYear('date', $year)
            ->whereMonth('date', $month)
            ->latest('id')
            ->value('reference');

        $nextNumber = 1;
        if ($latestRef) {
            $parts = explode('-', $latestRef);
            $lastNumber = (int) end($parts);
            $nextNumber = $lastNumber + 1;
        }

        $prefix = ($setting->document_prefix ?? '') . '-'
            . ($setting->purchase_prefix_document ?? 'PR');

        return make_reference_id($prefix, $year, $month, $nextNumber);
    }
}
