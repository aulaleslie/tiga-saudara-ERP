<?php

namespace Modules\Sale\Services;

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
use Modules\Sale\Entities\SaleDetails;
use Modules\Sale\Entities\SalesImportBatch;
use Modules\Sale\Entities\SalesImportRow;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Modules\Setting\Entities\Unit;
use Modules\Setting\Entities\Location;

class SalesImportService
{
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
     * Resolve tenant using Tag (Priority 1) then product marker (Priority 2).
     */
    public function resolveTenant(?string $tag, string $productName): ?Setting
    {
        // Priority 1: Try Tag-based lookup
        $setting = $this->getSettingForTag($tag);
        if ($setting) {
            return $setting;
        }

        // Priority 2: Fallback to product marker
        $parsed = $this->parseProductName($productName);
        return $this->getSettingForMarker($parsed['marker']);
    }

    /**
     * Resolve the Setting (Tenant) where stock should be affected.
     * Rules:
     * 1. Markers (*, TP) always override, pointing to CV Tiga Nusa or CV Top IT.
     * 2. No marker:
     *    - Try to find 'Last Tenant' (other than CV Tiga Nusa) that purchased this product.
     *    - If found, use that tenant.
     *    - Else, use Source Tenant.
     */
    public function resolveStockSetting(?string $tag, string $productName, Setting $sourceSetting, ?Product $product = null): Setting
    {
        $parsed = $this->parseProductName($productName);
        $marker = $parsed['marker'];

        // Rule 1: Markers are absolute
        if ($marker === 'asterisk') {
            // * -> CV TIGA NUSA COMPUTER
            $setting = Setting::where('company_name', 'LIKE', '%CV TIGA NUSA COMPUTER%')->first();
            if ($setting) return $setting;
        }

        if ($marker === 'tp') {
            // TP -> CV TOP IT INTERNUSA
            $setting = Setting::where('company_name', 'LIKE', '%CV TOP IT INTERNUSA%')->first();
            if ($setting) return $setting;
        }

        // Rule 2: No marker (or marker tenant not found/fallback)
        // Find CV Tiga Nusa ID to exclude it
        $tigaNusa = Setting::where('company_name', 'LIKE', '%CV TIGA NUSA COMPUTER%')->first();
        $tigaNusaId = $tigaNusa ? $tigaNusa->id : 0;

        // If product doesn't exist yet, we can't check history, so default to source
        if (!$product) {
            return $sourceSetting;
        }

        // Check history: Last Tenant other than CV Tiga Nusa that performed a purchase (Transaction type BUY)
        $lastTransaction = Transaction::where('product_id', $product->id)
            ->where('type', 'BUY')
            ->where('setting_id', '!=', $tigaNusaId)
            ->latest('id')
            ->first();

        if ($lastTransaction) {
            $lastSetting = Setting::find($lastTransaction->setting_id);
            if ($lastSetting) {
                return $lastSetting;
            }
        }

        // Fallback: Use Source Tenant
        return $sourceSetting;
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

        $product = Product::where('product_name', trim($cleanName))->first();

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

            Log::info('[SalesImport] Created new product', [
                'product_id' => $product->id,
                'name' => $cleanName,
            ]);
        }

        // Cache it
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

                // Get the last row's invoice info to check for split groups
                $lastRow = $initialRows->last();
                $lastRowData = $lastRow->raw_json;
                $lastInvoiceNo = $lastRowData['no_faktur'] ?? '';
                $lastTag = $lastRowData['tag'] ?? '';
                $lastProductName = $lastRowData['produk'] ?? '';

                // Check if there are more rows with the same invoice after this chunk
                $additionalRows = collect([]);
                if (!empty($lastInvoiceNo)) {
                    // Load additional rows that belong to the same invoice group
                    $lastRowNumber = $lastRow->row_number;
                    
                    $additionalRows = $batch->pendingRows()
                        ->where('row_number', '>', $lastRowNumber)
                        ->orderBy('row_number')
                        ->get()
                        ->takeWhile(function ($row) use ($lastInvoiceNo, $lastTag, $lastProductName) {
                            $rowData = $row->raw_json;
                            $rowInvoiceNo = $rowData['no_faktur'] ?? '';
                            $rowTag = $rowData['tag'] ?? '';
                            
                            // Check if this row belongs to same invoice group
                            if ($rowInvoiceNo !== $lastInvoiceNo) {
                                return false; // Different invoice, stop here
                            }
                            
                            // Same invoice number, check if same tenant
                            if (!empty($lastTag) && !empty($rowTag)) {
                                return strtolower(trim($rowTag)) === strtolower(trim($lastTag));
                            }
                            
                            // Fallback to product marker matching
                            $rowProductName = $rowData['produk'] ?? '';
                            $lastParsed = $this->parseProductName($lastProductName);
                            $rowParsed = $this->parseProductName($rowProductName);
                            
                            return $lastParsed['marker'] === $rowParsed['marker'];
                        });
                }

                // Merge initial chunk with additional rows to keep invoice groups together
                $rows = $initialRows->merge($additionalRows);
                $actualChunkSize = $rows->count();
                $processedChunks++;

                Log::info('[SalesImport] Processing chunk', [
                    'batch_id' => $batch->id,
                    'chunk_number' => $processedChunks,
                    'target_size' => $targetChunkSize,
                    'actual_size' => $actualChunkSize,
                    'additional_rows' => $additionalRows->count(),
                    // 'offset' => $offset,
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
     * Process invoice groups in transaction batches to reduce overhead.
     * Processing multiple groups per transaction is much more efficient.
     */
    protected function processGroupsInBatches(array $groups, SalesImportBatch $batch, int $batchSize): int
    {
        // Split groups into batches
        $groupChunks = array_chunk($groups, $batchSize, true);
        $totalProcessed = 0;

        foreach ($groupChunks as $chunkIndex => $groupChunk) {
            // Process multiple invoice groups in ONE transaction
            DB::transaction(function () use ($groupChunk, $batch, &$totalProcessed) {
                foreach ($groupChunk as $groupKey => $groupRows) {
                    $this->processInvoiceGroup($groupRows, $batch);
                    $totalProcessed++;
                }
            });

            // Log progress every 10 transaction batches (500 invoice groups)
            if (($chunkIndex + 1) % 10 === 0) {
                Log::info('[SalesImport] Transaction batch progress', [
                    'batch_id' => $batch->id,
                    'groups_processed' => $totalProcessed,
                ]);
            }
        }

        return $totalProcessed;
    }


    /**
     * Group rows by invoice number and tenant (using Tag or marker).
     */
    protected function groupRowsByInvoiceAndTenant(Collection $rows): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $data = $row->raw_json;
            $invoiceNo = $data['no_faktur'] ?? '';
            $tag = $data['tag'] ?? '';
            
            // Use tag if present, otherwise fall back to product marker
            if (!empty($tag)) {
                $tenantKey = 'tag:' . strtolower(trim($tag));
            } else {
                $parsed = $this->parseProductName($data['produk'] ?? '');
                $tenantKey = 'marker:' . $parsed['marker'];
            }

            $key = "{$invoiceNo}|{$tenantKey}";

            if (!isset($groups[$key])) {
                $groups[$key] = [];
            }
            $groups[$key][] = $row;
        }

        return $groups;
    }

    /**
     * Process a group of rows belonging to the same invoice and tenant.
     */
    protected function processInvoiceGroup(array $rows, SalesImportBatch $batch): void
    {
        if (empty($rows)) {
            return;
        }

        $firstRow = $rows[0];
        $data = $firstRow->raw_json;

        // Resolve tenant using Tag (Priority 1) then product marker (Priority 2)
        $tag = $data['tag'] ?? null;
        $productName = $data['produk'] ?? '';
        $setting = $this->resolveTenant($tag, $productName);

        if (!$setting) {
            foreach ($rows as $row) {
                $row->update([
                    'status' => SalesImportRow::STATUS_INVALID,
                    'error_message' => "Tenant not found for tag: '{$tag}' or product: '{$productName}'",
                ]);
                Log::warning('[SalesImport] Row error - tenant not found', [
                    'batch_id' => $batch->id,
                    'row_id' => $row->id,
                    'row_number' => $row->row_number,
                    'tag' => $tag,
                    'product' => $productName,
                    'no_faktur' => $data['no_faktur'] ?? 'Unknown',
                ]);
                $batch->increment('error_count');
            }
            return;
        }

        // Check for duplicate sale (same imported_sales_reference_number + setting_id)
        $invoiceNo = $data['no_faktur'] ?? null;
        if ($invoiceNo) {
            $existingSale = Sale::where('imported_sales_reference_number', $invoiceNo)
                ->where('setting_id', $setting->id)
                ->first();
            
            if ($existingSale) {
                foreach ($rows as $row) {
                    $row->update([
                        'status' => SalesImportRow::STATUS_SKIPPED,
                        'error_message' => "Skipped: Sale with invoice #{$invoiceNo} already exists (ID: {$existingSale->id})",
                        'sale_id' => $existingSale->id,
                    ]);
                }
                Log::info('[SalesImport] Skipped duplicate sale', [
                    'batch_id' => $batch->id,
                    'no_faktur' => $invoiceNo,
                    'existing_sale_id' => $existingSale->id,
                    'setting_id' => $setting->id,
                    'rows_skipped' => count($rows),
                ]);
                return;
            }
        }

        try {
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

                $quantity = (int) ($rowData['kuantitas'] ?? 1);
                $unitPriceDpp = (float) ($rowData['harga_satuan'] ?? 0);
                $taxAmount = (float) ($rowData['pajak'] ?? 0);
                $subtotal = $quantity * $unitPriceDpp;

                $totalAmount += $subtotal;
                $totalTaxAmount += $taxAmount;

                // Get tax: prefer tarif_pajak from CSV, fallback to calculated percentage
                $taxRateFromCsv = $this->parseTaxRate($rowData['tarif_pajak'] ?? null);
                if ($taxRateFromCsv > 0) {
                    $tax = $this->findOrCreateTax($taxRateFromCsv);
                } else {
                    $taxPercentage = $this->calculateTaxPercentage($subtotal, $taxAmount);
                    $tax = $taxPercentage > 0 ? $this->findOrCreateTax($taxPercentage) : null;
                }

                // Calculate effective tax rate
                $effectiveTaxRate = $taxRateFromCsv > 0 ? $taxRateFromCsv : ($tax?->value ?? 0);

                // Calculate final unit price (including tax)
                $unitPriceFinal = $effectiveTaxRate > 0
                    ? $unitPriceDpp * (1 + ($effectiveTaxRate / 100))
                    : $unitPriceDpp;

                // If tax rate was 0 but we have tax amount fallback, recalculate unitPriceFinal from total tax
                if ($effectiveTaxRate == 0 && $taxAmount > 0 && $quantity > 0) {
                     $unitTaxAmount = $taxAmount / $quantity;
                     $unitPriceFinal = $unitPriceDpp + $unitTaxAmount;
                }

                // Recalculate Subtotal based on final price
                $subtotal = $unitPriceFinal * $quantity;

                $details[] = [
                    'row' => $row,
                    'product' => $product,
                    'quantity' => $quantity,
                    'unit_price' => $unitPriceFinal, // Store Tax Included Price
                    'unit_price_final' => $unitPriceFinal,
                    'subtotal' => $subtotal, // Subtotal Tax Included
                    'tax_id' => $tax?->id,
                    'tax_amount' => $taxAmount,
                ];
            }

            // Calculate payment status from sisa_tagihan (outstanding balance)
            $totalWithTax = $totalAmount + $totalTaxAmount;
            $sisaTagihan = (float) ($data['sisa_tagihan'] ?? 0);
            $paymentStatus = $sisaTagihan > 0 ? 'Unpaid' : 'Paid';
            $dueAmount = $sisaTagihan;
            $paidAmount = $totalWithTax - $sisaTagihan;

            // Create sale
            $sale = new Sale();
            $sale->date = $saleDate;
            $sale->due_date = $dueDate;
            $sale->customer_id = $customer->id;
            $sale->customer_name = $customer->customer_name;
            $sale->total_amount = $totalWithTax;
            $sale->tax_amount = $totalTaxAmount;
            $sale->tax_percentage = $totalAmount > 0 ? round(($totalTaxAmount / $totalAmount) * 100) : 0;
            $sale->discount_percentage = 0;
            $sale->discount_amount = 0;
            $sale->shipping_amount = (float) ($data['biaya_pengiriman'] ?? 0);
            $sale->paid_amount = $paidAmount;
            $sale->due_amount = $dueAmount;
            $sale->status = Sale::STATUS_DISPATCHED;
            $sale->payment_status = $paymentStatus;
            $sale->payment_method = $paymentStatus === 'Paid' ? 'Cash' : '';
            $sale->setting_id = $setting->id;
            $sale->imported_sales_reference_number = $data['no_faktur'] ?? null;
            $sale->note = $data['memo'] ?? null;
            $sale->is_tax_included = $totalTaxAmount > 0;
            $sale->save();

            // Sync tag to sale
            if (!empty($tag)) {
                $sale->syncTags([trim($tag)]);
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

                // Update ProductPrice with sale_price (final price including tax)
                $settingCacheKey = "setting_{$setting->id}";
                $productPrice = $this->productPricesCache[$settingCacheKey][$detail['product']->id] ?? null;
                
                if (!$productPrice) {
                    $productPrice = ProductPrice::firstOrCreate(
                        [
                            'product_id' => $detail['product']->id,
                            'setting_id' => $setting->id,
                        ],
                        [
                            'sale_price' => 0,
                            'last_purchase_price' => 0,
                            'average_purchase_price' => 0,
                        ]
                    );
                    // Cache it
                    $this->productPricesCache[$settingCacheKey][$detail['product']->id] = $productPrice;
                }

                // Update sale_price if current is 0 or if new price is higher (latest price)
                $unitPriceFinal = $detail['unit_price_final'];
                if (($productPrice->sale_price ?? 0) == 0 || $unitPriceFinal > 0) {
                    $productPrice->update([
                        'sale_price' => $unitPriceFinal,
                    ]);
                }
            }

            // Auto-dispatch: Create Dispatch and DispatchDetail, decrement stock
            // Note: Location resolution is now done inside dispatchSale per product
            $this->dispatchSale($sale, $details, $setting, $tag, $saleDate);

            // Update row statuses
            foreach ($details as $detail) {
                $detail['row']->update([
                    'status' => SalesImportRow::STATUS_PROCESSED,
                    'sale_id' => $sale->id,
                ]);

                $batch->increment('success_count');
            }

            Log::info('[SalesImport] Created sale', [
                'sale_id' => $sale->id,
                'reference' => $sale->reference,
                'setting_id' => $setting->id,
                'location_id' => $location->id,
                'details_count' => count($details),
            ]);

        } catch (\Exception $e) {
            foreach ($rows as $row) {
                $row->update([
                    'status' => SalesImportRow::STATUS_INVALID,
                    'error_message' => $e->getMessage(),
                ]);
                Log::warning('[SalesImport] Row error - exception', [
                    'batch_id' => $batch->id,
                    'row_id' => $row->id,
                    'row_number' => $row->row_number,
                    'no_faktur' => $data['no_faktur'] ?? 'Unknown',
                    'error' => $e->getMessage(),
                    'raw_data' => $row->raw_json,
                ]);
                $batch->increment('error_count');
            }

            Log::error('[SalesImport] Failed to process invoice group', [
                'invoice' => $data['no_faktur'] ?? 'Unknown',
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Create dispatch and decrement stock for the sale.
     */
    /**
     * Create dispatch and decrement stock for the sale.
     */
    protected function dispatchSale(Sale $sale, array $details, Setting $setting, ?string $tag, Carbon $saleDate): void
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

            // Resolve stock setting (Target Tenant for stock movement) PER PRODUCT
            $stockSetting = $this->resolveStockSetting($tag, $product->product_name, $setting, $product);
            
            // Get location for the resolved STOCK setting
            // Use cache if possible? We only cache by setting_id in locationsCache
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

            // Get or create ProductStock for this product/location (use cache)
            $locationCacheKey = "location_{$location->id}";
            $productStock = $this->productStocksCache[$locationCacheKey][$product->id] ?? null;
            
            if (!$productStock) {
                $productStock = ProductStock::firstOrCreate(
                    [
                        'product_id' => $product->id,
                        'location_id' => $location->id,
                    ],
                    [
                        'quantity' => 0,
                        'quantity_tax' => 0,
                        'quantity_non_tax' => 0,
                        'broken_quantity' => 0,
                        'broken_quantity_tax' => 0,
                        'broken_quantity_non_tax' => 0,
                    ]
                );
                // Cache it
                $this->productStocksCache[$locationCacheKey][$product->id] = $productStock;
            }

            // Capture previous quantities
            $previousQuantity = $product->product_quantity ?? 0;
            $previousQuantityAtLocation = $productStock->quantity ?? 0;

            // Decrement stock (can go negative for imported historical data)
            $productStock->decrement('quantity', $quantity);
            if ($taxId) {
                $productStock->decrement('quantity_tax', $quantity);
            } else {
                $productStock->decrement('quantity_non_tax', $quantity);
            }

            // Decrement product global quantity
            $product->decrement('product_quantity', $quantity);

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

            // Create Transaction log with sale date
            Transaction::create([
                'product_id' => $product->id,
                'setting_id' => $stockSetting->id, // Log transaction against the Stock Owner
                'quantity' => -$quantity,
                'current_quantity' => $product->product_quantity,
                'broken_quantity' => 0,
                'location_id' => $location->id, // Resolved Location
                'user_id' => auth()->id() ?? 1,
                'reason' => 'Imported from Sale #' . $sale->reference . ' (Source: ' . $setting->company_name . ')',
                'type' => 'DISPATCH',
                'previous_quantity' => $previousQuantity,
                'after_quantity' => $product->product_quantity,
                'previous_quantity_at_location' => $previousQuantityAtLocation,
                'after_quantity_at_location' => $productStock->quantity,
                'quantity_non_tax' => $taxId ? 0 : $quantity,
                'quantity_tax' => $taxId ? $quantity : 0,
                'broken_quantity_non_tax' => 0,
                'broken_quantity_tax' => 0,
                'created_at' => $saleDate,
                'updated_at' => $saleDate,
            ]);
        }

        Log::info('[SalesImport] Created dispatch', [
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'details_count' => count($details),
        ]);
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
            // Due date
            'tanggal jatuh tempo' => 'tanggal_jatuh_tempo',
            'due date' => 'tanggal_jatuh_tempo',
            // Outstanding balance
            'sisa tagihan hari ini' => 'sisa_tagihan',
            'sisa tagihan' => 'sisa_tagihan',
            // Payment amount
            'pembayaran' => 'pembayaran',
            'payment' => 'pembayaran',
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

        return [
            'tanggal' => $get('tanggal'),
            'customer' => $get('customer'),
            'no_faktur' => $get('no_faktur'),
            'produk' => $get('produk'),
            'kuantitas' => $get('kuantitas'),
            'satuan' => $get('satuan'),
            'harga_satuan' => $get('harga_satuan'),
            'pajak' => $get('pajak') ?: '0',
            // Additional fields
            'tag' => $get('tag'),
            'tarif_pajak' => $get('tarif_pajak'),
            'deskripsi' => $get('deskripsi'),
            'memo' => $get('memo'),
            'tanggal_jatuh_tempo' => $get('tanggal_jatuh_tempo'),
            'sisa_tagihan' => $get('sisa_tagihan') ?: '0',
            'pembayaran' => $get('pembayaran') ?: '0',
            'biaya_pengiriman' => $get('biaya_pengiriman') ?: '0',
            'nama_perusahaan' => $get('nama_perusahaan'),
            'nomor_telepon' => $get('nomor_telepon'),
            'diskon_persen' => $get('diskon_persen') ?: '0',
            'gudang' => $get('gudang'),
        ];
    }
}
