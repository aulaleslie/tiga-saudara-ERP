<?php

namespace Modules\Purchase\Services;

use App\Support\ImportDocumentAdjustmentAllocator;
use App\Support\ImportDocumentAdjustmentResolver;
use App\Support\ImportPaymentAllocator;
use App\Support\ImportPaymentSummaryResolver;
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
use Modules\Setting\Entities\Location;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\Transaction;
use Modules\Purchase\Entities\PaymentTerm;

class PurchaseImportService
{
    protected ImportDocumentAdjustmentResolver $documentAdjustmentResolver;

    protected ImportPaymentSummaryResolver $paymentSummaryResolver;

    protected ImportPaymentAllocator $paymentAllocator;

    protected ImportDocumentAdjustmentAllocator $documentAdjustmentAllocator;

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
        ?ImportPaymentAllocator $paymentAllocator = null,
        ?ImportDocumentAdjustmentAllocator $documentAdjustmentAllocator = null
    )
    {
        $this->paymentSummaryResolver = $paymentSummaryResolver ?? app(ImportPaymentSummaryResolver::class);
        $this->documentAdjustmentResolver = $documentAdjustmentResolver ?? app(ImportDocumentAdjustmentResolver::class);
        $this->paymentAllocator = $paymentAllocator ?? app(ImportPaymentAllocator::class);
        $this->documentAdjustmentAllocator = $documentAdjustmentAllocator ?? app(ImportDocumentAdjustmentAllocator::class);
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
     * Get the setting (tenant) for a given marker.
     */
    public function getSettingForMarker(string $marker): ?Setting
    {
        $companyName = $this->tenantMapping[$marker] ?? $this->tenantMapping['default'];

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
     * Daizu product (Priority 0), then mapped CSV Tag (Priority 1), then product marker fallback (Priority 2).
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

        // Priority 2: Product marker fallback
        $parsed = $this->parseProductName($productName);
        return $this->getSettingForMarker($parsed['marker']);
    }

    /**
     * Resolve a stable effective-owner grouping key for a row.
     * Daizu (Priority 0), then mapped CSV Tag (Priority 1), then product marker (Priority 2).
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

        $parsed = $this->parseProductName($productName);
        return 'marker:' . $parsed['marker'];
    }

    /**
     * Resolve the Setting (Tenant) where stock should be affected.
     * Uses the effective owner rule: Daizu (Priority 0), mapped CSV Tag (Priority 1),
     * then product-name marker fallback (Priority 2).
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

        // Rule 1: Mapped CSV Tag
        $tagSetting = $this->getSettingForTag($tag);
        if ($tagSetting) {
            return $tagSetting;
        }

        $parsed = $this->parseProductName($productName);
        $marker = $parsed['marker'];

        // Rule 2: Product marker fallback
        if ($marker === 'asterisk') {
            $setting = Setting::where('company_name', 'LIKE', '%CV TIGA NUSA COMPUTER%')->first();
            if ($setting) return $setting;
        }

        if ($marker === 'tp') {
            $setting = Setting::where('company_name', 'LIKE', '%CV TOP IT INTERNUSA%')->first();
            if ($setting) return $setting;
        }

        // No marker — Perdana fallback
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

        return $tax;
    }

    /**
     * Find or create a supplier by name.
     */
    public function findOrCreateSupplier(string $name, int $settingId, ?string $contactName = null, ?string $phone = null): Supplier
    {
        $supplier = Supplier::whereRaw('LOWER(supplier_name) = ?', [strtolower(trim($name))])->first();

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

        return $supplier;
    }

    /**
     * Find or create a product by cleaned name.
     */
    public function findOrCreateProduct(string $cleanName, string $unitName, int $settingId, ?string $description = null): Product
    {
        $product = Product::whereRaw('LOWER(product_name) = ?', [strtolower(trim($cleanName))])->first();

        if (!$product) {
            // Find or create unit
            $unit = Unit::whereRaw('LOWER(short_name) = ?', [strtolower(trim($unitName))])->first();
            if (!$unit) {
                $unit = Unit::create([
                    'name' => ucfirst(strtolower($unitName)),
                    'short_name' => strtoupper($unitName),
                ]);
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
     * Process a batch of import rows.
     */
    public function processBatch(PurchaseImportBatch $batch): void
    {
        $batch->update(['status' => PurchaseImportBatch::STATUS_PROCESSING]);

        try {
            // Load all pending rows
            $rows = $batch->pendingRows()->orderBy('row_number')->get();

            // Group rows by invoice number and effective owner key
            $groups = $this->groupRowsByInvoiceAndTenant($rows);

            // Reorganize owner groups by source invoice so invoice-level payment fields
            // are reconciled once and allocated across owner documents.
            $invoices = $this->groupOwnerGroupsBySourceInvoice($groups);

            foreach ($invoices as $invoiceNo => $ownerGroups) {
                try {
                    DB::transaction(function () use ($ownerGroups, $batch) {
                        $this->processSourceInvoice($ownerGroups, $batch);
                    });
                } catch (\Exception $e) {
                    foreach ($ownerGroups as $groupRows) {
                        $this->markInvoiceGroupInvalid($groupRows, $batch, $e);
                    }
                }
            }

            // Update batch status
            $batch->refresh();
            $batch->update([
                'status' => PurchaseImportBatch::STATUS_COMPLETED,
                'processed_rows' => $batch->rows()->whereIn('status', ['processed', 'invalid', 'skipped'])->count(),
            ]);

            Log::info('[PurchaseImport] Batch completed', [
                'batch_id' => $batch->id,
                'success_count' => $batch->success_count,
                'error_count' => $batch->error_count,
            ]);
        } catch (\Exception $e) {
            $batch->update(['status' => PurchaseImportBatch::STATUS_FAILED]);
            Log::error('[PurchaseImport] Batch failed', [
                'batch_id' => $batch->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
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

            $quantity = (int) ($rowData['kuantitas'] ?? 1);
            $unitPriceDpp = (float) ($rowData['harga_satuan'] ?? 0);

            $discountPercent = $this->parseDiscountPercent($rowData['diskon_persen'] ?? '0');
            $dppAfterDiscount = $unitPriceDpp - ($unitPriceDpp * ($discountPercent / 100));

            $taxRateFromCsv = $this->parseTaxRate($rowData['tarif_pajak'] ?? null);
            if ($taxRateFromCsv > 0) {
                $lineTaxAmount = $dppAfterDiscount * $quantity * ($taxRateFromCsv / 100);
            } else {
                $lineTaxAmount = (float) ($rowData['pajak'] ?? 0);
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
    protected function processSourceInvoice(array $ownerGroups, PurchaseImportBatch $batch): void
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

        $groupTotals = [];
        foreach ($groupGrossTotals as $groupKey => $grossTotal) {
            $groupTotals[$groupKey] = round(
                $grossTotal - ($discountAllocations[$groupKey] ?? 0.0) + ($shippingAllocations[$groupKey] ?? 0.0),
                2
            );
        }

        $sourceInvoiceTotal = round(array_sum($groupTotals), 2);
        $paymentSummary = $this->paymentSummaryResolver->resolve($allRows, $sourceInvoiceTotal);

        $allocations = $this->paymentAllocator->allocate(
            $groupTotals,
            $paymentSummary['paid_amount'],
            $paymentSummary['outstanding_balance']
        );

        foreach ($ownerGroups as $groupKey => $groupRows) {
            $allocation = $allocations[$groupKey] ?? ['paid' => 0.0, 'due' => 0.0];
            $this->processInvoiceGroup(
                $groupRows,
                $batch,
                $allocation['paid'],
                $allocation['due'],
                $discountAllocations[$groupKey] ?? 0.0,
                $shippingAllocations[$groupKey] ?? 0.0
            );
        }
    }

    /**
     * Process a group of rows belonging to the same invoice and effective owner.
     * Paid/due amounts are pre-allocated at source-invoice scope.
     */
    protected function processInvoiceGroup(array $rows, PurchaseImportBatch $batch, float $allocatedPaid = 0.0, float $allocatedDue = 0.0, float $allocatedDiscount = 0.0, float $allocatedShipping = 0.0): void
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

            // Resolve tenant using Tag (Priority 1) then product marker (Priority 2), or Daizu product
            $tag = $data['tag'] ?? null;
            $productName = $data['produk'] ?? '';
            $setting = $this->resolveTenant($tag, $productName);

            if (!$setting) {
                foreach ($rows as $row) {
                    $row->update([
                        'status' => PurchaseImportRow::STATUS_INVALID,
                        'error_message' => "Tenant not found for product: '{$productName}'",
                    ]);
                    Log::warning('[PurchaseImport] Row error - tenant not found', [
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

            // Check for duplicate purchase (same supplier_purchase_number + setting_id)
            $invoiceNo = $data['no_faktur'] ?? null;
            if ($invoiceNo) {
                $existingPurchase = Purchase::where('supplier_purchase_number', $invoiceNo)
                    ->where('setting_id', $setting->id)
                    ->first();

                if ($existingPurchase) {
                    foreach ($rows as $row) {
                        $row->update([
                            'status' => PurchaseImportRow::STATUS_SKIPPED,
                            'error_message' => "Skipped: Purchase with invoice #{$invoiceNo} already exists (ID: {$existingPurchase->id})",
                            'purchase_id' => $existingPurchase->id,
                        ]);
                    }
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

                $daizuLocation = Location::where('setting_id', $setting->id)->first();
                if (!$daizuLocation) {
                    throw new \Exception("Daizu Kedelai setting exists but no usable stock location found");
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

                $quantity = (int) ($rowData['kuantitas'] ?? 1);
                $unitPriceDpp = (float) ($rowData['harga_satuan'] ?? 0); // DPP from CSV

                // Parse discount percentage (e.g., "0.00 %" or "7.26")
                $discountPercent = $this->parseDiscountPercent($rowData['diskon_persen'] ?? '0');
                $discountAmount = $unitPriceDpp * ($discountPercent / 100);
                $dppAfterDiscount = $unitPriceDpp - $discountAmount;

                // Get tax rate from CSV
                $taxRateFromCsv = $this->parseTaxRate($rowData['tarif_pajak'] ?? null);

                // Calculate tax amount for this line
                $lineTaxAmount = 0;
                if ($taxRateFromCsv > 0) {
                    $lineTaxAmount = $dppAfterDiscount * $quantity * ($taxRateFromCsv / 100);
                } else {
                    $lineTaxAmount = (float) ($rowData['pajak'] ?? 0);
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

                // Find or create tax record
                $tax = null;
                if ($taxRateFromCsv > 0) {
                    $tax = $this->findOrCreateTax($taxRateFromCsv);
                } else {
                    // Fallback: calculate from pajak column if present
                    $csvTaxAmount = (float) ($rowData['pajak'] ?? 0);
                    if ($csvTaxAmount > 0 && $subtotalDpp > 0) {
                        $taxPercentage = $this->calculateTaxPercentage($subtotalDpp, $csvTaxAmount);
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
            $adjustedTotalWithTax = round($totalWithTax - $documentDiscount + $documentShipping, 2);

            // Payment is reconciled once at source-invoice scope and allocated to this owner group.
            $paidAmount = round($allocatedPaid, 2);
            $dueAmount = round($allocatedDue, 2);
            $needsPayment = $paidAmount > 0.01;

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

            // Create purchase details and update stock
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

                // Update product stock
                $product = $detail['product'];
                $quantity = $detail['quantity'];

                // Resolve stock setting (Target Tenant for stock movement) PER PRODUCT using detail's raw product name
                $stockSetting = $this->resolveStockSetting($tag, $detail['raw_product_name'], $setting, $product);
                
                // Get location for the resolved STOCK setting
                $location = Location::where('setting_id', $stockSetting->id)->first();
                
                if (!$location) {
                     // Fallback to source setting location
                     $location = Location::where('setting_id', $setting->id)->first();
                }
                
                if (!$location) {
                    throw new \Exception("No location found for setting: {$stockSetting->company_name}");
                }

                // Get or create ProductStock for this product/location (Target Location)
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

                // Capture previous quantities (default to 0 for new products)
                // Note: previousQuantity is global, previousQuantityAtLocation is specific to the TARGET location
                $previousQuantity = $product->product_quantity ?? 0;
                $previousQuantityAtLocation = $productStock->quantity ?? 0;

                // Increment stock
                $productStock->increment('quantity', $quantity);
                if ($detail['tax_id']) {
                    $productStock->increment('quantity_tax', $quantity);
                } else {
                    $productStock->increment('quantity_non_tax', $quantity);
                }

                // Increment product global quantity
                $product->increment('product_quantity', $quantity);

                // Calculate new average purchase price (weighted average) using FINAL price (DPP + tax)
                $unitPriceFinal = $detail['unit_price_final'];
                $newAveragePrice = $this->calculateWeightedAveragePurchasePrice(
                    $product->id,
                    $product->setting_id,
                    $previousQuantity,
                    $unitPriceFinal,
                    $quantity
                );

                // Synchronize purchase-price fields (last_purchase_price, average_purchase_price)
                // across ALL settings. Selling price fields are never touched here.
                $this->syncPurchasePricesAcrossSettings($product->id, $unitPriceFinal, $newAveragePrice);

                // Create Transaction log with purchase date
                Transaction::create([
                    'product_id' => $product->id,
                    'setting_id' => $stockSetting->id, // Log transaction against the Stock Owner
                    'quantity' => $quantity,
                    'current_quantity' => $product->product_quantity,
                    'broken_quantity' => 0,
                    'location_id' => $location->id, // Resolved Location
                    'user_id' => auth()->id() ?? 1,
                    'reason' => 'Imported from Purchase #' . $purchase->reference . ' (Source: ' . $setting->company_name . ')',
                    'type' => 'BUY',
                    'previous_quantity' => $previousQuantity,
                    'after_quantity' => $product->product_quantity,
                    'previous_quantity_at_location' => $previousQuantityAtLocation,
                    'after_quantity_at_location' => $productStock->quantity,
                    'quantity_non_tax' => $detail['tax_id'] ? 0 : $quantity,
                    'quantity_tax' => $detail['tax_id'] ? $quantity : 0,
                    'broken_quantity_non_tax' => 0,
                    'broken_quantity_tax' => 0,
                    'created_at' => $purchaseDate,
                    'updated_at' => $purchaseDate,
                ]);
            }

            if ($needsPayment && $cashPaymentMethod) {
                PurchasePayment::create([
                    'purchase_id' => $purchase->id,
                    'payment_method_id' => $cashPaymentMethod->id,
                    'amount' => $paidAmount,
                    'date' => $purchaseDate,
                    'reference' => $purchase->reference,
                    'payment_method' => $cashPaymentMethod->name,
                ]);
            }

            foreach ($details as $detail) {
                $detail['row']->update([
                    'status' => PurchaseImportRow::STATUS_PROCESSED,
                    'purchase_id' => $purchase->id,
                ]);

                $batch->increment('success_count');
            }

            Log::info('[PurchaseImport] Created purchase', [
                'purchase_id' => $purchase->id,
                'reference' => $reference,
                'setting_id' => $setting->id,
                'location_id' => $location->id,
                'details_count' => count($details),
            ]);
    }

    protected function markInvoiceGroupInvalid(array $rows, PurchaseImportBatch $batch, \Exception $e): void
    {
        $invoice = $rows[0]->raw_json['no_faktur'] ?? 'Unknown';

        foreach ($rows as $row) {
            $row->update([
                'status' => PurchaseImportRow::STATUS_INVALID,
                'error_message' => $e->getMessage(),
            ]);
            Log::warning('[PurchaseImport] Row error - exception', [
                'batch_id' => $batch->id,
                'row_id' => $row->id,
                'row_number' => $row->row_number,
                'no_faktur' => $invoice,
                'error' => $e->getMessage(),
                'raw_data' => $row->raw_json,
            ]);
            $batch->increment('error_count');
        }

        Log::error('[PurchaseImport] Failed to process invoice group', [
            'invoice' => $invoice,
            'error' => $e->getMessage(),
        ]);
    }

    /**
     * Calculate weighted average purchase price given current product quantity and the incoming batch.
     */
    protected function calculateWeightedAveragePurchasePrice(
        int $productId,
        int $ownerSettingId,
        int $previousQuantity,
        float $unitPriceFinal,
        int $incomingQuantity
    ): float {
        // Read the baseline average from the product's owner-setting row for a deterministic result.
        // product_quantity is a global field on the product, so the canonical prior average must
        // come from a single consistent row — the owner-setting record is that anchor.
        // If the owner-setting row is missing or stale (null/zero) but other rows exist with a
        // positive average — e.g. products imported before this all-settings sync change — fall
        // back to the MAX existing average so we don't understate the weighted average.
        $existingPrice = ProductPrice::where('product_id', $productId)
            ->where('setting_id', $ownerSettingId)
            ->value('average_purchase_price');
        $currentAvg = (float) ($existingPrice ?? 0);
        if ($currentAvg <= 0) {
            $fallback = ProductPrice::where('product_id', $productId)->max('average_purchase_price');
            $currentAvg = (float) ($fallback ?? 0);
        }

        $currentTotalValue = $currentAvg * $previousQuantity;
        $newTotalValue = $unitPriceFinal * $incomingQuantity;
        $newTotalQuantity = $previousQuantity + $incomingQuantity;

        return $newTotalQuantity > 0
            ? ($currentTotalValue + $newTotalValue) / $newTotalQuantity
            : $unitPriceFinal;
    }

    /**
     * Upsert purchase-price fields (last_purchase_price, average_purchase_price) across every setting.
     * Selling price fields (sale_price, tier_1_price, tier_2_price) are never modified.
     */
    protected function syncPurchasePricesAcrossSettings(int $productId, float $lastPurchasePrice, float $averagePurchasePrice): void
    {
        $allSettingIds = Setting::pluck('id');

        foreach ($allSettingIds as $settingId) {
            $productPrice = ProductPrice::firstOrCreate(
                ['product_id' => $productId, 'setting_id' => $settingId],
                ['sale_price' => 0, 'last_purchase_price' => 0, 'average_purchase_price' => 0]
            );

            $productPrice->update([
                'last_purchase_price'    => $lastPurchasePrice,
                'average_purchase_price' => $averagePurchasePrice,
            ]);
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
