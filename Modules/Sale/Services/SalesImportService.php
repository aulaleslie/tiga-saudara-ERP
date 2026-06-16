<?php

namespace Modules\Sale\Services;

use App\Support\ImportDocumentAdjustmentAllocator;
use App\Support\ImportDocumentAdjustmentResolver;
use App\Support\ImportPaymentSummaryResolver;
use App\Support\ImportSettlementAllocator;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\Transaction;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;
use Modules\Sale\Entities\SaleDetails;
use Modules\Sale\Entities\SalesImportBatch;
use Modules\Sale\Entities\SalesImportRow;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Modules\Setting\Entities\Unit;
use Modules\Setting\Entities\Location;

class SalesImportService
{
    protected ImportDocumentAdjustmentResolver $documentAdjustmentResolver;

    protected ImportPaymentSummaryResolver $paymentSummaryResolver;

    protected ImportDocumentAdjustmentAllocator $documentAdjustmentAllocator;

    protected ImportSettlementAllocator $settlementAllocator;

    /**
     * Entity caches to avoid N+1 queries
     */
    protected array $settingsCache = [];
    protected array $customersCache = [];
    protected array $productsCache = [];
    protected array $taxesCache = [];
    protected array $unitsCache = [];
    protected array $locationsCache = [];
    protected array $productPricesCache = [];
    protected array $productStocksCache = [];

    /**
     * Tag-based tenant mapping (Priority 1).
     * Maps Tag column values to company names.
     */
    protected array $tagMapping = [
        'cv tiga nusa' => 'CV TIGA NUSA COMPUTER',
        'cv top it' => 'CV TOP IT INTERNUSA',
        'aries' => 'TIGA COMPUTER',
        'rahmat' => 'WHITE KNIGHT COMPUTER',
        'agus' => 'DUNIA COMPUTER',
        'perdana' => 'PERDANA',
    ];

    /**
     * Tenant mapping based on product name markers (Priority 2 - fallback).
     */
    protected array $tenantMapping = [
        'asterisk' => 'CV TIGA NUSA COMPUTER',   // * prefix
        'tp'       => 'CV TOP IT INTERNUSA',     // TP suffix
        'default'  => 'PERDANA',                 // no marker
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
     * Get the setting (tenant) for a given marker.
     */
    public function getSettingForMarker(string $marker): ?Setting
    {
        $companyName = $this->tenantMapping[$marker] ?? $this->tenantMapping['default'];

        // Check cache first
        foreach ($this->settingsCache as $setting) {
            if (stripos($setting->company_name, $companyName) !== false) {
                return $setting;
            }
        }

        return Setting::where('company_name', 'LIKE', "%{$companyName}%")->first();
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

        // Check cache first
        foreach ($this->settingsCache as $setting) {
            if (stripos($setting->company_name, $companyName) !== false) {
                return $setting;
            }
        }

        return Setting::where('company_name', 'LIKE', "%{$companyName}%")->first();
    }

    /**
     * Normalize product name: uppercase, remove punctuation, collapse whitespace.
     */
    public function normalizeProductName(string $rawName): string
    {
        $normalized = strtoupper($rawName);
        $normalized = preg_replace('/[^\w\s]/', ' ', $normalized);
        $normalized = preg_replace('/\s+/', ' ', $normalized);
        return trim($normalized);
    }

    /**
     * Detect if a product name matches Daizu criteria (contains whole-word KEDELE, KEDELAI, or RAGI).
     */
    public function isDaizuProduct(string $rawName): bool
    {
        $normalized = $this->normalizeProductName($rawName);
        return preg_match('/\b(KEDELE|KEDELAI|RAGI)\b/', $normalized) === 1;
    }

    /**
     * Get the Daizu Kedelai setting or return null if not found.
     */
    public function getDaizuSetting(): ?Setting
    {
        return Setting::where('company_name', 'LIKE', '%DAIZU%')->first();
    }

    /**
     * Resolve a Daizu location by name or return the default Daizu location.
     * Returns null if location cannot be found and no default is available.
     */
    public function resolveDaizuLocation(?string $gudangName, Setting $daizuSetting): ?Location
    {
        // If gudang name is provided, try to find it within Daizu
        if (!empty($gudangName)) {
            $location = Location::where('setting_id', $daizuSetting->id)
                ->where('name', 'LIKE', "%{$gudangName}%")
                ->first();

            if ($location) {
                return $location;
            }

            // If specified gudang is not found, return null (don't fall back)
            return null;
        }

        // Blank gudang: use the default available Daizu location (first one ordered by ID)
        return Location::where('setting_id', $daizuSetting->id)
            ->orderBy('id')
            ->first();
    }

    public function resolveTenant(?string $tag, string $productName): ?Setting
    {
        // Priority 0: Check for Daizu product
        if ($this->isDaizuProduct($productName)) {
            return $this->getDaizuSetting();
        }

        // Priority 1: Product marker (* or TP suffix)
        $parsed = $this->parseProductName($productName);
        return $this->getSettingForMarker($parsed['marker']);
    }

    /**
     * Resolve a stable effective-owner grouping key for a row.
     * Daizu (Priority 0), then product marker (Priority 1).
     */
    public function resolveEffectiveOwnerKey(?string $tag, string $productName): string
    {
        if ($this->isDaizuProduct($productName)) {
            return 'daizu';
        }

        $parsed = $this->parseProductName($productName);
        return 'marker:' . $parsed['marker'];
    }

    /**
     * Resolve the Setting (Tenant) where stock should be affected.
     * Uses the effective owner rule: Daizu (Priority 0), then product-name marker (Priority 1).
     */
    public function resolveStockSetting(?string $tag, string $productName, Setting $sourceSetting, ?Product $product = null): Setting
    {
        // Rule 0: Daizu products always route to Daizu, fail explicitly if missing
        if ($this->isDaizuProduct($productName)) {
            $daizuSetting = $this->getDaizuSetting();
            if (!$daizuSetting) {
                throw new \Exception("Daizu Kedelai setting not found for product: {$productName}");
            }

            return $daizuSetting;
        }

        $parsed = $this->parseProductName($productName);
        $marker = $parsed['marker'];

        // Rule 1: Product marker
        if ($marker === 'asterisk') {
            $setting = Setting::where('company_name', 'LIKE', '%CV TIGA NUSA COMPUTER%')->first();
            if ($setting) {
                return $setting;
            }
        }

        if ($marker === 'tp') {
            $setting = Setting::where('company_name', 'LIKE', '%CV TOP IT INTERNUSA%')->first();
            if ($setting) {
                return $setting;
            }
        }

        // Rule 2: No marker — always Perdana (no history fallback)
        $perdana = Setting::where('company_name', 'LIKE', '%PERDANA%')->first();
        return $perdana ?? $sourceSetting;
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
        // Check cache first
        if (isset($this->taxesCache[$percentage])) {
            return $this->taxesCache[$percentage];
        }

        $tax = Tax::where('value', $percentage)->first();

        if (!$tax) {
            $tax = Tax::create([
                'name' => "PPN {$percentage}%",
                'value' => $percentage,
            ]);

            Log::info('[SalesImport] Created new tax', [
                'tax_id' => $tax->id,
                'percentage' => $percentage,
            ]);
        }

        // Cache it
        $this->taxesCache[$percentage] = $tax;

        return $tax;
    }

    /**
     * Find or create a customer by name.
     * Note: Customers are global (not scoped by setting_id).
     */
    public function findOrCreateCustomer(string $name, ?string $contactName = null, ?string $phone = null): Customer
    {
        $normalizedName = strtolower(trim($name));

        // Check cache first
        if (isset($this->customersCache[$normalizedName])) {
            return $this->customersCache[$normalizedName];
        }

        $customer = Customer::where('customer_name', trim($name))->first();

        if (!$customer) {
            $customer = Customer::create([
                'customer_name' => trim($name),
                'customer_email' => '',
                'customer_phone' => !empty($phone) ? $phone : null,
                'contact_name' => $contactName,
                'address' => '',
                'city' => '',
                'country' => '',
            ]);

            Log::info('[SalesImport] Created new customer', [
                'customer_id' => $customer->id,
                'name' => $name,
            ]);
        }

        // Cache it
        $this->customersCache[$normalizedName] = $customer;

        return $customer;
    }

    /**
     * Find or create a product by cleaned name.
     */
    public function findOrCreateProduct(string $cleanName, string $unitName, int $settingId, ?string $description = null): Product
    {
        $normalizedName = strtolower(trim($cleanName));

        // Check cache first
        if (isset($this->productsCache[$normalizedName])) {
            return $this->productsCache[$normalizedName];
        }

        $product = Product::whereRaw('LOWER(product_name) = ?', [$normalizedName])->first();

        if (!$product) {
            // Find or create unit (use cache)
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
                // Cache the unit
                $this->unitsCache[$normalizedUnitName] = $unit;
            }

            $product = Product::create([
                'product_name' => trim($cleanName),
                'product_code' => $this->importProductCode($cleanName),
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

            Log::info('[SalesImport] Created new product', [
                'product_id' => $product->id,
                'name' => $cleanName,
            ]);
        } elseif (blank($product->product_code)) {
            $product->product_code = $this->importProductCode($cleanName);
            $product->save();
        }

        // Cache it
        $this->productsCache[$normalizedName] = $product;

        return $product;
    }

    protected function importProductCode(string $cleanName): string
    {
        return 'SKU-' . strtoupper(substr(md5($cleanName), 0, 8));
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
            Log::info('[SalesImport] Pre-loaded settings', ['count' => count($this->settingsCache)]);
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
            Log::info('[SalesImport] Pre-loaded taxes', ['count' => count($this->taxesCache)]);
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
            Log::info('[SalesImport] Pre-loaded units', ['count' => count($this->unitsCache)]);
        }
    }

    /**
     * Pre-load all locations and cache them by setting_id.
     */
    protected function preloadLocations(): void
    {
        if (empty($this->locationsCache)) {
            $locations = Location::all();
            foreach ($locations as $location) {
                if (!isset($this->locationsCache[$location->setting_id])) {
                    $this->locationsCache[$location->setting_id] = $location;
                }
            }
            Log::info('[SalesImport] Pre-loaded locations', ['count' => count($this->locationsCache)]);
        }
    }

    /**
     * Pre-load existing customers from the batch rows.
     */
    protected function preloadCustomersForBatch(Collection $rows): void
    {
        // Extract unique customer names from rows (preserve case for index usage)
        $customerNames = $rows->map(function ($row) {
            return trim($row->raw_json['customer'] ?? '');
        })->filter()->unique()->values()->toArray();

        if (empty($customerNames)) {
            return;
        }

        // Load customers in a single query using index
        $customers = Customer::whereIn('customer_name', $customerNames)->get();

        foreach ($customers as $customer) {
            $this->customersCache[strtolower($customer->customer_name)] = $customer;
        }

        Log::info('[SalesImport] Pre-loaded customers', ['count' => count($this->customersCache)]);
    }

    /**
     * Pre-load existing products from the batch rows.
     */
    protected function preloadProductsForBatch(Collection $rows): void
    {
        // Extract unique product names from rows (preserve case for index usage)
        $productNames = $rows->map(function ($row) {
            $parsed = $this->parseProductName($row->raw_json['produk'] ?? '');
            return trim($parsed['clean_name']);
        })->filter()->unique()->values()->toArray();

        if (empty($productNames)) {
            return;
        }

        // Load products in a single query using index
        $products = Product::whereIn('product_name', $productNames)->get();

        foreach ($products as $product) {
            $this->productsCache[strtolower($product->product_name)] = $product;
        }

        Log::info('[SalesImport] Pre-loaded products', ['count' => count($this->productsCache)]);
    }

    /**
     * Pre-load product prices for a given setting.
     */
    protected function preloadProductPricesForSetting(int $settingId): void
    {
        $cacheKey = "setting_{$settingId}";

        if (!isset($this->productPricesCache[$cacheKey])) {
            $productPrices = ProductPrice::where('setting_id', $settingId)->get();

            $this->productPricesCache[$cacheKey] = [];
            foreach ($productPrices as $price) {
                $this->productPricesCache[$cacheKey][$price->product_id] = $price;
            }
        }
    }

    /**
     * Pre-load product stocks for a given location.
     */
    protected function preloadProductStocksForLocation(int $locationId): void
    {
        $cacheKey = "location_{$locationId}";

        if (!isset($this->productStocksCache[$cacheKey])) {
            $productStocks = ProductStock::where('location_id', $locationId)->get();

            $this->productStocksCache[$cacheKey] = [];
            foreach ($productStocks as $stock) {
                $this->productStocksCache[$cacheKey][$stock->product_id] = $stock;
            }
        }
    }

    /**
     * Process a batch of import rows.
     * Optimized with smart chunking and entity pre-loading to avoid N+1 queries
     * and memory issues with large batches.
     */
    public function processBatch(SalesImportBatch $batch): void
    {
        $startTime = microtime(true);
        $batch->update(['status' => SalesImportBatch::STATUS_PROCESSING]);

        try {
            // Pre-load all static entities ONCE (these are small and don't change)
            $this->preloadSettings();
            $this->preloadTaxes();
            $this->preloadUnits();
            $this->preloadLocations();

            Log::info('[SalesImport] Starting batch processing', [
                'batch_id' => $batch->id,
                'total_rows' => $batch->total_rows,
                'preload_time' => round(microtime(true) - $startTime, 2) . 's',
            ]);

            // Check if there are any pending rows
            $totalPending = $batch->pendingRows()->count();
            if ($totalPending === 0) {
                $batch->update(['status' => SalesImportBatch::STATUS_COMPLETED]);
                return;
            }

            // Process in chunks to avoid memory issues with large batches
            // Use invoice-aware chunking to prevent splitting invoice groups
            $targetChunkSize = 500; // Target size, actual may be slightly larger
            $processedChunks = 0;
            $totalGroupsProcessed = 0;
            // $offset = 0; // Offset is not needed as processed rows are removed from pendingRows() query

            while (true) {
                $chunkStartTime = microtime(true);

                // Load initial chunk
                // ALWAYS take from the beginning (offset 0) because previously processed rows
                // satisfy the 'pending' status check and effectively disappear from this query.
                $initialRows = $batch->pendingRows()
                    ->orderBy('row_number')
                    // ->skip($offset) // REMOVED: Using offset causes skipping rows in dynamic lists
                    ->take($targetChunkSize)
                    ->get();

                if ($initialRows->isEmpty()) {
                    break; // No more rows to process
                }

                // Collect distinct invoice numbers from the initial pending row window
                $invoiceNumbers = $initialRows->map(function ($row) {
                    return trim($row->raw_json['no_faktur'] ?? '');
                })->filter(fn($val) => $val !== '')->unique()->values()->toArray();

                // Load all pending rows for the selected invoice numbers to ensure complete source invoices are processed
                if (!empty($invoiceNumbers)) {
                    $rowsWithInvoice = $batch->pendingRows()
                        ->whereIn('raw_json->no_faktur', $invoiceNumbers)
                        ->orderBy('row_number')
                        ->get();

                    $rowsWithoutInvoice = $initialRows->filter(function ($row) {
                        return trim($row->raw_json['no_faktur'] ?? '') === '';
                    });

                    $rows = $rowsWithInvoice->merge($rowsWithoutInvoice)
                        ->unique('id')
                        ->sortBy('row_number')
                        ->values();
                } else {
                    $rows = $initialRows;
                }

                $actualChunkSize = $rows->count();
                $processedChunks++;

                // Log chunk processing details including any expanded rows beyond initial window
                $expandedRowsCount = $actualChunkSize - $initialRows->count();

                Log::info('[SalesImport] Processing chunk', [
                    'batch_id' => $batch->id,
                    'chunk_number' => $processedChunks,
                    'target_size' => $targetChunkSize,
                    'initial_rows' => $initialRows->count(),
                    'actual_size' => $actualChunkSize,
                    'invoice_count' => count($invoiceNumbers),
                    'expanded_rows' => $expandedRowsCount > 0 ? $expandedRowsCount : 0,
                ]);

                // Pre-load customers and products specific to THIS chunk
                $this->preloadCustomersForBatch($rows);
                $this->preloadProductsForBatch($rows);

                // Group rows by invoice number and tenant
                $groups = $this->groupRowsByInvoiceAndTenant($rows);

                // Process groups in transaction batches (reduce transaction overhead)
                $groupsProcessed = $this->processGroupsInBatches($groups, $batch, $batchSize = 50);
                $totalGroupsProcessed += $groupsProcessed;

                Log::info('[SalesImport] Chunk completed', [
                    'batch_id' => $batch->id,
                    'chunk_number' => $processedChunks,
                    'groups_in_chunk' => count($groups),
                    'chunk_time' => round(microtime(true) - $chunkStartTime, 2) . 's',
                    'cumulative_time' => round(microtime(true) - $startTime, 2) . 's',
                ]);

                // Clear chunk-specific caches to free memory
                $this->customersCache = [];
                $this->productsCache = [];
                $this->productPricesCache = [];
                $this->productStocksCache = [];

                // Force garbage collection
                gc_collect_cycles();

                // Move offset forward by actual processed rows
                // $offset += $actualChunkSize; // REMOVED
            }

            // Update batch status
            $batch->refresh();
            $batch->update([
                'status' => SalesImportBatch::STATUS_COMPLETED,
                'processed_rows' => $batch->rows()->whereIn('status', ['processed', 'invalid', 'skipped'])->count(),
            ]);

            $totalTime = microtime(true) - $startTime;
            $totalRows = $batch->total_rows;
            $rowsPerSecond = $totalRows > 0 ? round($totalRows / $totalTime, 2) : 0;

            Log::info('[SalesImport] Batch completed', [
                'batch_id' => $batch->id,
                'success_count' => $batch->success_count,
                'error_count' => $batch->error_count,
                'total_chunks' => $processedChunks,
                'total_groups' => $totalGroupsProcessed,
                'total_time' => round($totalTime, 2) . 's',
                'rows_per_second' => $rowsPerSecond,
            ]);
        } catch (\Exception $e) {
            $batch->update(['status' => SalesImportBatch::STATUS_FAILED]);
            Log::error('[SalesImport] Batch failed', [
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
    protected function processGroupsInBatches(array $groups, SalesImportBatch $batch, int $batchSize): int
    {
        $invoices = $this->groupOwnerGroupsBySourceInvoice($groups);

        $invoiceChunks = array_chunk($invoices, $batchSize, true);
        $totalProcessed = 0;


        $chunkSuccessCount = 0;
        $chunkErrorCount = 0;

        foreach ($invoiceChunks as $chunkIndex => $invoiceChunk) {
            foreach ($invoiceChunk as $invoiceNo => $ownerGroups) {
                $invoicePriceUpdates = [];
                $invoiceSuccessCount = 0;
                try {
                    DB::transaction(function () use ($ownerGroups, $batch, &$invoicePriceUpdates, &$invoiceSuccessCount) {
                        $this->processSourceInvoice($ownerGroups, $batch, $invoicePriceUpdates, $invoiceSuccessCount);
                    });
                    $totalProcessed++;
                    $chunkSuccessCount += $invoiceSuccessCount;
                } catch (\Exception $e) {
                    foreach ($ownerGroups as $groupRows) {
                        $this->markInvoiceGroupInvalid($groupRows, $batch, $e, $chunkErrorCount);
                    }
                }
            }

            // Log progress every 10 transaction batches
            if (($chunkIndex + 1) % 10 === 0) {
                Log::info('[SalesImport] Transaction batch progress', [
                    'batch_id' => $batch->id,
                    'invoices_processed' => $totalProcessed,
                ]);
            }
        }

        if ($chunkSuccessCount > 0) {
            $batch->increment('success_count', $chunkSuccessCount);
        }

        if ($chunkErrorCount > 0) {
            $batch->increment('error_count', $chunkErrorCount);
        }

        return $totalProcessed;
    }

    /**
     * Process all owner groups for a single source invoice: reconcile invoice-level payment
     * fields once, allocate paid/due pro-rata across positive-total owner groups, and create
     * each owner document. A reconciliation failure throws and rolls back the whole invoice.
     *
     * @param  array<string, array<int, SalesImportRow>>  $ownerGroups
     */
    protected function processSourceInvoice(array $ownerGroups, SalesImportBatch $batch, array &$invoicePriceUpdates = [], int &$invoiceSuccessCount = 0): void
    {
        $groupGrossTotals = [];
        $allRows = [];
        $rowIds = [];
        foreach ($ownerGroups as $groupKey => $groupRows) {
            $groupGrossTotals[$groupKey] = $this->calculateGroupGrossTotal($groupRows);
            foreach ($groupRows as $row) {
                $allRows[] = $row->raw_json;
                $rowIds[] = $row->id;
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
            $summaryB = $this->paymentSummaryResolver->resolveForSales($allRows, $totalB);
        } catch (\RuntimeException $e) {
            try {
                $summaryB = $this->paymentSummaryResolver->resolveForSalesWithPrecisionDrift($allRows, $totalB);
            } catch (\RuntimeException $driftError) {
                $errorB = $e;
            }
        }

        try {
            $this->paymentSummaryResolver->resolveForSales($allRows, $totalA);
        } catch (\RuntimeException $e) {
            try {
                $this->paymentSummaryResolver->resolveForSalesWithPrecisionDrift($allRows, $totalA);
            } catch (\RuntimeException $driftError) {
                $errorA = $e;
            }
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

        $appliedDriftAllocations = [];
        $driftAccepted = false;

        $firstRowData = is_array($allRows[0]) ? $allRows[0] : $allRows[0]->raw_json;
        $sourceTotal = $this->paymentSummaryResolver->parseMoney($firstRowData['source_total'] ?? null);

        if ($sourceTotal !== null) {
            $sourceDelta = round($sourceTotal - $sourceInvoiceTotal, 2);

            if (abs($sourceDelta) > 0.0) {
                $paymentSummary = $this->paymentSummaryResolver->resolveForSalesWithPrecisionDrift(
                    $allRows,
                    $sourceInvoiceTotal
                );
                $driftAccepted = true;

                $driftAllocations = $this->documentAdjustmentAllocator->allocate($groupTotals, abs($sourceDelta));
                $driftSign = $sourceDelta <=> 0;

                foreach ($groupTotals as $groupKey => $groupTotal) {
                    $appliedDriftAllocations[$groupKey] = round($driftAllocations[$groupKey] * $driftSign, 2);
                    $groupTotals[$groupKey] = round($groupTotal + $appliedDriftAllocations[$groupKey], 2);
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

                    $appliedDriftAllocations[$remainderKey] = round(($appliedDriftAllocations[$remainderKey] ?? 0.0) + $remainder, 2);
                    $groupTotals[$remainderKey] = round($groupTotals[$remainderKey] + $remainder, 2);
                }

                $sourceInvoiceTotal = round(array_sum($groupTotals), 2);

                Log::info('[SalesImport] Accepted precision drift for source invoice', [
                    'batch_id' => $batch->id,
                    'invoice_number' => $firstRowData['no_faktur'] ?? 'unknown',
                    'source_total' => $sourceInvoiceTotal,
                    'recomputed_adjusted_total' => round($sourceInvoiceTotal - $sourceDelta, 2),
                    'drift_amount' => $sourceDelta,
                    'row_ids' => $rowIds,
                ]);
            } else {
                $paymentSummary = $this->paymentSummaryResolver->resolveForSales(
                    $allRows,
                    $sourceInvoiceTotal
                );
            }
        } else {
            $paymentSummary = $this->paymentSummaryResolver->resolveForSales(
                $allRows,
                $sourceInvoiceTotal
            );
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
                $invoiceSuccessCount,
                $groupTotals[$groupKey] ?? 0.0
            );
        }

        if (!empty($invoicePriceUpdates)) {
            $this->flushSalePriceUpdatesAcrossSettings($invoicePriceUpdates);
        }
    }


    /**
     * Group rows by invoice number and effective owner key.
     * Effective owner: Daizu (Priority 0), mapped CSV Tag (Priority 1), product marker (Priority 2).
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
     * @param  array<string, array<int, SalesImportRow>>  $groups
     * @return array<string, array<string, array<int, SalesImportRow>>>
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
     * Calculate an owner group's gross document total (line totals plus tax) BEFORE any
     * document-level discount or shipping adjustment. Document-level Diskon/Biaya Pengiriman are
     * invoice-scoped and allocated across owner groups at source-invoice scope.
     *
     * @param  array<int, SalesImportRow>  $rows
     */
    protected function calculateGroupGrossTotal(array $rows): float
    {
        $totalAmount = 0.0;
        $totalTaxAmount = 0.0;

        if (empty($rows)) {
            return 0.0;
        }

        $firstRowData = $rows[0]->raw_json;
        $setting = $this->resolveTenant($firstRowData['tag'] ?? null, $firstRowData['produk'] ?? '');
        $isPkp = $setting ? ($setting->is_pkp ?? false) : false;

        foreach ($rows as $row) {
            $rowData = $row->raw_json;

            $quantity = $this->parseQuantity($rowData['kuantitas'] ?? null);
            $unitPriceDpp = (float) ($rowData['harga_satuan'] ?? 0);
            $csvPajakStr = $rowData['pajak'] ?? null;
            $hasCsvPajak = $csvPajakStr !== null && trim($csvPajakStr) !== '' && (float) $csvPajakStr != 0;

            if ($hasCsvPajak) {
                $taxAmount = (float) $csvPajakStr;
            } else {
                $taxRateFromCsv = $this->parseTaxRate($rowData['tarif_pajak'] ?? null);
                $taxAmount = $taxRateFromCsv > 0 ? ($quantity * $unitPriceDpp) * ($taxRateFromCsv / 100) : 0;
            }

            $totalAmount += $quantity * $unitPriceDpp;
            $totalTaxAmount += $taxAmount;
        }

        return round($totalAmount + $totalTaxAmount, 2);
    }

    /**
     * Process a group of rows belonging to the same invoice and effective owner.
     * Paid/due amounts are pre-allocated at source-invoice scope.
     */
    protected function processInvoiceGroup(array $rows, SalesImportBatch $batch, float $allocatedPaid = 0.0, float $allocatedDue = 0.0, float $allocatedDiscount = 0.0, float $allocatedShipping = 0.0, float $allocatedDeduction = 0.0, array &$invoicePriceUpdates = [], int &$invoiceSuccessCount = 0, float $canonicalTotal = 0.0): void
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

        // Resolve tenant using Daizu (Priority 0), Tag (Priority 1), then product marker (Priority 2)
        $tag = $data['tag'] ?? null;
        $productName = $data['produk'] ?? '';
        $isDaizu = $this->isDaizuProduct($productName);
        $setting = $this->resolveTenant($tag, $productName);

        $isPkp = $setting->is_pkp ?? false;

        // For Daizu products, validate that the setting exists
        if ($isDaizu && !$setting) {
            throw new \Exception("Daizu Kedelai setting not found for product: '{$productName}'");
        }

        if (!$setting) {
            throw new \Exception("Tenant not found for product: '{$productName}'");
        }

        // Check for duplicate sale (same imported_sales_reference_number + setting_id)
        $invoiceNo = $data['no_faktur'] ?? null;
        if ($invoiceNo) {
            $existingSale = Sale::where('imported_sales_reference_number', $invoiceNo)
                ->where('setting_id', $setting->id)
                ->first();

            if ($existingSale) {
                $rowIds = array_map(fn($r) => $r->id, $rows);
                SalesImportRow::whereIn('id', $rowIds)->update([
                    'status' => SalesImportRow::STATUS_SKIPPED,
                    'error_message' => "Skipped: Sale with invoice #{$invoiceNo} already exists (ID: {$existingSale->id})",
                    'sale_id' => $existingSale->id,
                ]);
                Log::info('[SalesImport] Skipped duplicate sale', [
                    'batch_id' => $batch->id,
                    'no_faktur' => $invoiceNo,
                    'existing_sale_id' => $existingSale->id,
                    'setting_id' => $setting->id,
                    'rows_skipped' => count($rows),
                ]);
                // Skipped rows are processed but do not count as successes
                return;
            }

            // For Daizu products, also check for legacy non-Daizu sales with same invoice
            // that contain Daizu-matched products (conflict detection)
            if ($isDaizu) {
                $legacySale = Sale::where('imported_sales_reference_number', $invoiceNo)
                    ->where('setting_id', '!=', $setting->id)
                    ->with('saleDetails.product')
                    ->first();

                if ($legacySale) {
                    // Check if this legacy sale contains any Daizu-matched products
                    $hasDaizuProduct = false;
                    $daizuProductName = null;

                    foreach ($legacySale->saleDetails as $detail) {
                        if ($detail->product && $this->isDaizuProduct($detail->product->product_name)) {
                            $hasDaizuProduct = true;
                            $daizuProductName = $detail->product->product_name;
                            break;
                        }
                    }

                    if ($hasDaizuProduct) {
                        throw new \Exception("Conflict: Invoice #{$invoiceNo} already exists under different setting (ID: {$legacySale->id}) with Daizu-matched product '{$daizuProductName}'. This is a legacy ownership conflict.");
                    }
                }
            }
        }

        // For Daizu products, validate that the location can be resolved
        if ($isDaizu) {
            $gudangName = $data['gudang'] ?? null;
            $daizuLocation = $this->resolveDaizuLocation($gudangName, $setting);
            if (!$daizuLocation) {
                $gudangDesc = $gudangName ? "'{$gudangName}'" : "(blank - default location)";
                throw new \Exception("Daizu location not found for gudang {$gudangDesc}");
            }
        }

        // Parse dates
        $saleDate = $this->parseDate($data['tanggal']);

            $dueDateStr = isset($data['tanggal_jatuh_tempo']) ? trim($data['tanggal_jatuh_tempo']) : '';
            $dueDate = !empty($dueDateStr)
                ? $this->parseDate($dueDateStr)
                : $saleDate;

            // Find or create customer (global, not scoped by setting)
            $customer = $this->findOrCreateCustomer(
                $data['customer'],
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

                // Find or create product with description
                $product = $this->findOrCreateProduct(
                    $parsedProduct['clean_name'],
                    $rowData['satuan'] ?? 'PCS',
                    $setting->id,
                    $rowData['deskripsi'] ?? null
                );

                $quantity = $this->parseQuantity($rowData['kuantitas'] ?? null);
                $unitPriceDpp = (float) ($rowData['harga_satuan'] ?? 0);
                $csvPajakStr = $rowData['pajak'] ?? null;
                $hasCsvPajak = $csvPajakStr !== null && trim($csvPajakStr) !== '' && (float) $csvPajakStr != 0;
                $subtotalDpp = $quantity * $unitPriceDpp;

                if ($hasCsvPajak) {
                    $taxAmount = (float) $csvPajakStr;
                } else {
                    $taxRateFromCsv = $this->parseTaxRate($rowData['tarif_pajak'] ?? null);
                    $taxAmount = $taxRateFromCsv > 0 ? $subtotalDpp * ($taxRateFromCsv / 100) : 0;
                }

                $totalAmount += $subtotalDpp;
                $totalTaxAmount += $taxAmount;

                // Get tax: prefer tarif_pajak from CSV, fallback to calculated percentage
                $taxRateFromCsv = $this->parseTaxRate($rowData['tarif_pajak'] ?? null);
                if ($taxRateFromCsv > 0) {
                    $tax = $this->findOrCreateTax($taxRateFromCsv);
                } else {
                    if ($taxAmount > 0 && $subtotalDpp > 0) {
                        $taxPercentage = $this->calculateTaxPercentage($subtotalDpp, $taxAmount);
                        $tax = $taxPercentage > 0 ? $this->findOrCreateTax($taxPercentage) : null;
                    } else {
                        $tax = null;
                    }
                }

                // Calculate final unit price (including tax) using exact tax amount
                $unitTaxAmount = $quantity > 0 ? ($taxAmount / $quantity) : 0;
                $unitPriceFinal = $unitPriceDpp + $unitTaxAmount;

                // Recalculate Subtotal based on final price
                $subtotal = $unitPriceFinal * $quantity;
                $rawProductName = $rowData['produk'] ?? $product->product_name;

                $details[] = [
                    'row' => $row,
                    'product' => $product,
                    'quantity' => $quantity,
                    'unit_price' => $unitPriceFinal, // Store Tax Included Price
                    'unit_price_final' => $unitPriceFinal,
                    'subtotal' => $subtotal, // Subtotal Tax Included
                    'tax_id' => $tax?->id,
                    'tax_amount' => $taxAmount,
                    'raw_product_name' => $rawProductName,
                ];
            }

            $totalWithTax = $totalAmount + $totalTaxAmount;
            $adjustedTotalWithTax = $canonicalTotal;

            // Payment is reconciled once at source-invoice scope and allocated to this owner group.
            // Cash payment (Pembayaran) and the non-cash deduction (Jumlah Pemotongan) both settle
            // the invoice: header paid_amount = cash + deduction so paid + due = total, while only
            // the cash portion becomes an actual payment row.
            $cashPaidAmount = round($allocatedPaid, 2);
            $deductionAmount = round($allocatedDeduction, 2);
            $dueAmount = round($allocatedDue, 2);
            $groupPaidAmount = round($cashPaidAmount + $deductionAmount, 2);
            $needsPayment = $cashPaidAmount > 0.01;

            if (abs(($groupPaidAmount + $dueAmount) - $adjustedTotalWithTax) > 0.01) {
                Log::error('[SalesImport] Payment mismatch', [
                    'group_paid' => $groupPaidAmount,
                    'due' => $dueAmount,
                    'adjusted_total' => $adjustedTotalWithTax,
                    'diff' => ($groupPaidAmount + $dueAmount) - $adjustedTotalWithTax,
                ]);
                throw new \RuntimeException('Payment total mismatch: settlement fields do not reconcile with adjusted document total.');
            }

            $cashPaymentMethod = null;
            if ($needsPayment) {
                $cashPaymentMethod = $this->paymentSummaryResolver->resolveCashPaymentMethod();

                if (! $cashPaymentMethod) {
                    throw new \RuntimeException('Cash payment method is required for paid imports.');
                }
            }

            $paymentStatus = $dueAmount <= 0.01 ? 'Paid' : ($groupPaidAmount > 0.01 ? 'Partial' : 'Unpaid');

            // Create sale
            $sale = new Sale();
            $sale->date = $saleDate;
            $sale->due_date = $dueDate;
            $sale->customer_id = $customer->id;
            $sale->customer_name = $customer->customer_name;
            $sale->total_amount = $adjustedTotalWithTax;
            $sale->tax_amount = $totalTaxAmount;
            $sale->tax_percentage = $totalAmount > 0 ? round(($totalTaxAmount / $totalAmount) * 100) : 0;
            $sale->discount_percentage = 0;
            $sale->discount_amount = $documentDiscount;
            $sale->shipping_amount = $documentShipping;
            $sale->paid_amount = $groupPaidAmount;
            $sale->due_amount = $dueAmount;
            $sale->status = Sale::STATUS_DISPATCHED;
            $sale->payment_status = $paymentStatus;
            $sale->payment_method = $cashPaymentMethod?->name ?? '';
            $sale->setting_id = $setting->id;
            $sale->imported_sales_reference_number = $data['no_faktur'] ?? null;
            $sale->note = $data['memo'] ?? null;
            $sale->is_tax_included = $totalTaxAmount > 0;
            $sale->save();

            // Sync all distinct tags from every row in the group
            if (!empty($allTags)) {
                $sale->syncTags($allTags);
            }

            // Get first location for this setting (use cache)
            $location = $this->locationsCache[$setting->id] ?? null;
            if (!$location) {
                $location = Location::where('setting_id', $setting->id)->first();
                if (!$location) {
                    throw new \Exception("No location found for setting: {$setting->company_name}");
                }
                $this->locationsCache[$setting->id] = $location;
            }

            // Pre-load ProductPrice and ProductStock for this setting/location
            $this->preloadProductPricesForSetting($setting->id);
            $this->preloadProductStocksForLocation($location->id);

            // Create sale details and update ProductPrice with sale_price
            foreach ($details as $detail) {
                SaleDetails::create([
                    'sale_id' => $sale->id,
                    'product_id' => $detail['product']->id,
                    'product_name' => $detail['product']->product_name,
                    'product_code' => $detail['product']->product_code,
                    'quantity' => $detail['quantity'],
                    'price' => $detail['unit_price'],
                    'unit_price' => $detail['unit_price'],
                    'sub_total' => $detail['subtotal'],
                    'product_discount_amount' => 0,
                    'product_discount_type' => 'fixed',
                    'product_tax_amount' => $detail['tax_amount'],
                    'tax_id' => $detail['tax_id'],
                ]);

                // Accumulate selling-price updates for invoice-level deduplication
                $unitPriceFinal = $detail['unit_price_final'];
                if ($unitPriceFinal > 0) {
                    $invoicePriceUpdates[$detail['product']->id] = $unitPriceFinal;
                }
            }

            // Auto-dispatch: Create Dispatch and DispatchDetail, decrement stock
            // Note: Location resolution is now done inside dispatchSale per product
            $this->dispatchSale($sale, $details, $setting, $tag, $saleDate, $isDaizu, $data['gudang'] ?? null);

            if ($needsPayment && $cashPaymentMethod) {
                SalePayment::create([
                    'sale_id' => $sale->id,
                    'payment_method_id' => $cashPaymentMethod->id,
                    'amount' => $cashPaidAmount,
                    'date' => $saleDate,
                    'reference' => $sale->reference,
                    'payment_method' => $cashPaymentMethod->name,
                ]);
            }

            // Record the Jumlah Pemotongan as a separate non-cash settlement credit so reports,
            // which derive paid from active payment rows, see the invoice as fully settled.
            if ($deductionAmount > 0.01) {
                $deductionPaymentMethod = $this->paymentSummaryResolver->resolveDeductionPaymentMethod();
                SalePayment::create([
                    'sale_id' => $sale->id,
                    'payment_method_id' => $deductionPaymentMethod->id,
                    'amount' => $deductionAmount,
                    'date' => $saleDate,
                    'reference' => $sale->reference,
                    'payment_method' => $deductionPaymentMethod->name,
                ]);
            }

            // Update row statuses
            $rowIds = array_map(function ($detail) {
                return $detail['row']->id;
            }, $details);

            if (!empty($rowIds)) {
                SalesImportRow::whereIn('id', $rowIds)->update([
                    'status' => SalesImportRow::STATUS_PROCESSED,
                    'sale_id' => $sale->id,
                ]);
                $invoiceSuccessCount += count($rowIds);
            }

        Log::info('[SalesImport] Created sale', [
            'sale_id' => $sale->id,
            'reference' => $sale->reference,
            'setting_id' => $setting->id,
            'location_id' => $location->id,
            'details_count' => count($details),
        ]);
    }

    protected function markInvoiceGroupInvalid(array $groupRows, SalesImportBatch $batch, \Exception $e, int &$chunkErrorCount): void
    {
        // Log the failure
        $invoice = $groupRows[0]->raw_json['no_faktur'] ?? 'Unknown';
        $rowIds = [];

        foreach ($groupRows as $row) {
            $rowIds[] = $row->id;
            Log::warning('[SalesImport] Row error - exception', [
                'batch_id' => $batch->id,
                'row_id' => $row->id,
                'row_number' => $row->row_number,
                'no_faktur' => $invoice,
                'error' => $e->getMessage(),
                'raw_data' => $row->raw_json,
            ]);
        }

        if (!empty($rowIds)) {
            SalesImportRow::whereIn('id', $rowIds)->update([
                'status' => SalesImportRow::STATUS_INVALID,
                'error_message' => substr($e->getMessage(), 0, 255),
            ]);
            $chunkErrorCount += count($rowIds);
        }

        Log::error('[SalesImport] Failed to process invoice group', [
            'invoice' => $invoice,
            'error' => $e->getMessage(),
        ]);
    }

    /**
     * Create dispatch and decrement stock for the sale.
     */
    protected function dispatchSale(Sale $sale, array $details, Setting $setting, ?string $tag, Carbon $saleDate, bool $isDaizu = false, ?string $gudang = null): void
    {
        // Create Dispatch record
        $dispatch = Dispatch::create([
            'sale_id' => $sale->id,
            'dispatch_date' => $saleDate,
        ]);

        foreach ($details as $detail) {
            $product = $detail['product'];
            $quantity = $detail['quantity'];
            $taxId = $detail['tax_id'];
            $rawProductName = $detail['raw_product_name'] ?? $product->product_name;

            // Resolve stock setting (Target Tenant for stock movement) PER PRODUCT
            $stockSetting = $this->resolveStockSetting($tag, $rawProductName, $setting, $product);

            // Get location for the resolved STOCK setting
            $location = null;

            // For Daizu products, resolve location from Daizu setting using gudang parameter
            if ($this->isDaizuProduct($rawProductName) && $stockSetting->company_name && stripos($stockSetting->company_name, 'DAIZU') !== false) {
                $location = $this->resolveDaizuLocation($gudang, $stockSetting);
                if (!$location) {
                    $gudangDesc = $gudang ? "'{$gudang}'" : "(blank - default location)";
                    throw new \Exception("Daizu location not found for gudang {$gudangDesc} in Daizu Kedelai setting");
                }
            } else {
                // Non-Daizu: use cache if possible
                $location = $this->locationsCache[$stockSetting->id] ?? null;
                if (!$location) {
                    $location = Location::where('setting_id', $stockSetting->id)->first();
                    if (!$location) {
                        // Fallback to source setting location
                        $location = Location::where('setting_id', $setting->id)->first();
                    }
                    if (!$location) {
                        throw new \Exception("No location found for setting: {$stockSetting->company_name}");
                    }
                    // Cache it
                    $this->locationsCache[$stockSetting->id] = $location;
                }
            }



            // Create DispatchDetail
            DispatchDetail::create([
                'dispatch_id' => $dispatch->id,
                'sale_id' => $sale->id,
                'tax_id' => $taxId,
                'product_id' => $product->id,
                'dispatched_quantity' => $quantity,
                'location_id' => $location->id,
                'serial_numbers' => json_encode([]),
            ]);


        }

        Log::info('[SalesImport] Created dispatch', [
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'details_count' => count($details),
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
     * Upsert accumulated selling-price fields across every setting for a chunk.
     */
    protected function flushSalePriceUpdatesAcrossSettings(array $chunkPriceUpdates): void
    {
        $settingIds = $this->getAllSettingIds();
        $records = [];
        $now = now();

        foreach ($chunkPriceUpdates as $productId => $salePrice) {
            foreach ($settingIds as $settingId) {
                $records[] = [
                    'product_id' => $productId,
                    'setting_id' => $settingId,
                    'sale_price' => $salePrice,
                    'tier_1_price' => $salePrice,
                    'tier_2_price' => $salePrice,
                    'last_purchase_price' => 0,
                    'average_purchase_price' => 0,
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
                    ['sale_price', 'tier_1_price', 'tier_2_price', 'updated_at']
                );
            }
        }
    }

    /**
     * Normalize header names.
     */

    public function normalizeHeaders(array $rawHeaders): array
    {
        $aliases = [
            // Date
            'tanggal' => 'tanggal',
            'date' => 'tanggal',
            // Customer
            'customer' => 'customer',
            'customer name' => 'customer',
            'nama panggilan' => 'customer',
            // Invoice number
            'no faktur' => 'no_faktur',
            'no. faktur' => 'no_faktur',
            'invoice' => 'no_faktur',
            'invoice no' => 'no_faktur',
            'nomor transaksi' => 'no_faktur',
            // Product
            'produk' => 'produk',
            'product' => 'produk',
            'product name' => 'produk',
            'nama produk' => 'produk',
            // Quantity
            'kuantitas' => 'kuantitas',
            'quantity' => 'kuantitas',
            'qty' => 'kuantitas',
            // Unit
            'satuan' => 'satuan',
            'unit' => 'satuan',
            // Unit price
            'harga satuan' => 'harga_satuan',
            'harga per unit' => 'harga_satuan',
            'unit price' => 'harga_satuan',
            'price' => 'harga_satuan',
            // Line total (fallback for unit price)
            'jumlah per baris' => 'line_total',
            'jumlah kena pajak per baris' => 'line_total',
            // Tax amount per line
            'pajak' => 'pajak',
            'tax' => 'pajak',
            'tax amount' => 'pajak',
            'jumlah pajak' => 'pajak',
            // Tax rate
            'tarif pajak' => 'tarif_pajak',
            'tax rate' => 'tarif_pajak',
            // Product description
            'deskripsi' => 'deskripsi',
            'description' => 'deskripsi',
            // Tag (for tenant selection)
            'tag' => 'tag',
            // Memo/notes
            'memo' => 'memo',
            // Current payment/document status
            'status hari ini' => 'status_hari_ini',
            // Due date
            'tanggal jatuh tempo' => 'tanggal_jatuh_tempo',
            'due date' => 'tanggal_jatuh_tempo',
            // Outstanding balance
            'sisa tagihan hari ini' => 'sisa_tagihan_hari_ini',
            'sisa tagihan' => 'sisa_tagihan',
            // Payment amount
            'pembayaran' => 'pembayaran',
            'payment' => 'pembayaran',
            // Non-cash settlement reduction / credit
            'jumlah pemotongan' => 'jumlah_pemotongan',
            // Source document total
            'total' => 'source_total',
            // Shipping
            'biaya pengiriman' => 'biaya_pengiriman',
            'shipping' => 'biaya_pengiriman',
            // Customer company name
            'nama perusahaan' => 'nama_perusahaan',
            'company name' => 'nama_perusahaan',
            // Phone
            'nomor telepon' => 'nomor_telepon',
            'phone' => 'nomor_telepon',
            // Discount
            'diskon' => 'diskon',
            'diskon %' => 'diskon_document_persen',
            'diskon per baris %' => 'diskon_persen',
            // Location/warehouse
            'gudang' => 'gudang',
            'warehouse' => 'gudang',
        ];

        $map = [];
        foreach ($rawHeaders as $header) {
            $norm = strtolower(trim(preg_replace('/\s+/', ' ', $header)));
            if (isset($aliases[$norm])) {
                $map[$aliases[$norm]] = $header;
            }
        }

        return $map;
    }

    public function parseNumericFallback(mixed $value): float
    {
        $normalized = preg_replace('/[^0-9,.-]/', '', (string) $value) ?? '';
        if ($normalized === '') return 0.0;

        $lastComma = strrpos($normalized, ',');
        $lastDot = strrpos($normalized, '.');

        if ($lastComma !== false && $lastDot !== false) {
            if ($lastComma > $lastDot) {
                $normalized = str_replace('.', '', $normalized);
                $normalized = str_replace(',', '.', $normalized);
            } else {
                $normalized = str_replace(',', '', $normalized);
            }
        } elseif ($lastComma !== false) {
            if (preg_match('/,\d{1,2}$/', $normalized)) {
                $normalized = str_replace(',', '.', $normalized);
            } else {
                $normalized = str_replace(',', '', $normalized);
            }
        } elseif ($lastDot !== false) {
            if (preg_match('/\.\d{1,2}$/', $normalized)) {
                // Keep the dot
            } else {
                $normalized = str_replace('.', '', $normalized);
            }
        }

        return (float) $normalized;
    }

    /**
     * Map CSV row to normalized structure.
     */
    public function mapCsvRow(array $record, array $normalizedHeaders): array
    {
        $get = function (string $canonical) use ($record, $normalizedHeaders) {
            if (!isset($normalizedHeaders[$canonical])) {
                return null;
            }
            $actual = $normalizedHeaders[$canonical];
            return array_key_exists($actual, $record) ? trim((string) $record[$actual]) : null;
        };

        $hargaSatuan = $get('harga_satuan');
        $qtyStr = $get('kuantitas');

        if (empty($hargaSatuan) || $this->parseNumericFallback($hargaSatuan) == 0) {
            $qty = $this->parseNumericFallback($qtyStr);
            if ($qty > 0) {
                $lineTotal = $this->parseNumericFallback($get('line_total'));
                if ($lineTotal > 0) {
                    $hargaSatuan = (string) round($lineTotal / $qty, 5);
                }
            }
        }

        return [
            'tanggal' => $get('tanggal'),
            'customer' => $get('customer'),
            'no_faktur' => $get('no_faktur'),
            'produk' => $get('produk'),
            'kuantitas' => $qtyStr,
            'satuan' => $get('satuan'),
            'harga_satuan' => $hargaSatuan,
            'pajak' => $get('pajak') ?: '0',
            // Additional fields
            'tag' => $get('tag'),
            'tarif_pajak' => $get('tarif_pajak'),
            'deskripsi' => $get('deskripsi'),
            'memo' => $get('memo'),
            'status_hari_ini' => $get('status_hari_ini'),
            'tanggal_jatuh_tempo' => $get('tanggal_jatuh_tempo'),
            'sisa_tagihan_hari_ini' => $get('sisa_tagihan_hari_ini'),
            'sisa_tagihan' => $get('sisa_tagihan'),
            'pembayaran' => $get('pembayaran'),
            'jumlah_pemotongan' => $get('jumlah_pemotongan'),
            'source_total' => $get('source_total'),
            'biaya_pengiriman' => $get('biaya_pengiriman'),
            'nama_perusahaan' => $get('nama_perusahaan'),
            'nomor_telepon' => $get('nomor_telepon'),
            'diskon' => $get('diskon'),
            'diskon_document_persen' => $get('diskon_document_persen'),
            'diskon_persen' => $get('diskon_persen'),
            'gudang' => $get('gudang'),
        ];
    }
}
