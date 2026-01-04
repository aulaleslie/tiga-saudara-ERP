<?php

namespace Modules\Purchase\Services;

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
                'product_code' => 'IMP-' . strtoupper(substr(md5($cleanName), 0, 8)),
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

            // Group rows by invoice number and marker (tenant)
            $groups = $this->groupRowsByInvoiceAndTenant($rows);

            foreach ($groups as $groupKey => $groupRows) {
                DB::transaction(function () use ($groupRows, $batch) {
                    $this->processInvoiceGroup($groupRows, $batch);
                });
            }

            // Update batch status
            $batch->refresh();
            $batch->update([
                'status' => PurchaseImportBatch::STATUS_COMPLETED,
                'processed_rows' => $batch->rows()->whereIn('status', ['processed', 'invalid'])->count(),
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
    protected function processInvoiceGroup(array $rows, PurchaseImportBatch $batch): void
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
                    'status' => PurchaseImportRow::STATUS_INVALID,
                    'error_message' => "Tenant not found for tag: '{$tag}' or product: '{$productName}'",
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
                return;
            }
        }

        try {
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
                $unitPriceDpp = (float) ($rowData['harga_satuan'] ?? 0); // DPP from CSV

                // Parse discount percentage (e.g., "0.00 %" or "7.26")
                $discountPercent = $this->parseDiscountPercent($rowData['diskon_persen'] ?? '0');
                $discountAmount = $unitPriceDpp * ($discountPercent / 100);
                $dppAfterDiscount = $unitPriceDpp - $discountAmount;

                // Get tax rate from CSV
                $taxRateFromCsv = $this->parseTaxRate($rowData['tarif_pajak'] ?? null);

                // Calculate unit price with tax: Harga Satuan = DPP × (1 + Tarif Pajak%)
                // For taxed items, the final unit price includes tax
                $unitPriceWithTax = $taxRateFromCsv > 0
                    ? $dppAfterDiscount * (1 + ($taxRateFromCsv / 100))
                    : $dppAfterDiscount;

                // Calculate tax amount for this line
                $lineTaxAmount = $taxRateFromCsv > 0
                    ? $dppAfterDiscount * $quantity * ($taxRateFromCsv / 100)
                    : (float) ($rowData['pajak'] ?? 0);

                // Calculate subtotal: Final unit price × quantity
                $subtotalWithTax = $unitPriceWithTax * $quantity;
                $subtotalDpp = $dppAfterDiscount * $quantity;

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
                    'quantity' => $quantity,
                    'unit_price' => $dppAfterDiscount, // Store DPP after discount as base price
                    'unit_price_final' => $unitPriceWithTax, // Final price including tax for ProductPrice updates
                    'subtotal' => $subtotalDpp, // Subtotal based on DPP (base amount)
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

            // Calculate payment status from sisa_tagihan (outstanding balance)
            $totalWithTax = $totalAmount + $totalTaxAmount;
            $sisaTagihan = (float) ($data['sisa_tagihan'] ?? 0);
            $paymentStatus = $sisaTagihan > 0 ? 'UNPAID' : 'PAID';
            $dueAmount = $sisaTagihan;
            $paidAmount = $totalWithTax - $sisaTagihan;

            // Create purchase
            $purchase = new Purchase();
            $purchase->date = $purchaseDate;
            $purchase->due_date = $dueDate;
            $purchase->reference = $reference;
            $purchase->supplier_id = $supplier->id;
            $purchase->payment_term_id = $paymentTerm?->id;
            $purchase->total_amount = $totalWithTax;
            $purchase->tax_amount = $totalTaxAmount;
            $purchase->tax_percentage = $totalAmount > 0 ? round(($totalTaxAmount / $totalAmount) * 100) : 0;
            $purchase->discount_percentage = 0;
            $purchase->discount_amount = 0;
            $purchase->shipping_amount = (float) ($data['biaya_pengiriman'] ?? 0);
            $purchase->paid_amount = $paidAmount;
            $purchase->due_amount = $dueAmount;
            $purchase->status = Purchase::STATUS_RECEIVED;
            $purchase->payment_status = $paymentStatus;
            $purchase->payment_method = $paymentStatus === 'PAID' ? 'Cash' : '';
            $purchase->setting_id = $setting->id;
            $purchase->supplier_purchase_number = $data['no_faktur'] ?? null;
            $purchase->note = $data['memo'] ?? null;
            $purchase->tax_ref_no = $data['nomor_pajak'] ?? null;
            $purchase->save();

            // Sync tag to purchase (matching CreateForm/EditForm pattern)
            if (!empty($tag)) {
                $purchase->syncTags([trim($tag)]);
            }

            // Get first location for this setting
            $location = Location::where('setting_id', $setting->id)->first();
            if (!$location) {
                throw new \Exception("No location found for setting: {$setting->company_name}");
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

                // Get or create ProductStock for this product/location
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

                // Update ProductPrice table for this product/setting
                $productPrice = ProductPrice::firstOrCreate(
                    [
                        'product_id' => $product->id,
                        'setting_id' => $setting->id,
                    ],
                    [
                        'sale_price' => 0,
                        'last_purchase_price' => 0,
                        'average_purchase_price' => 0,
                    ]
                );

                // Calculate new average purchase price (weighted average) using FINAL price (DPP + tax)
                $previousQty = $previousQuantity;
                $currentAvgPrice = $productPrice->average_purchase_price ?? 0;
                $currentTotalValue = $currentAvgPrice * $previousQty;
                $unitPriceFinal = $detail['unit_price_final'];
                $newTotalValue = $unitPriceFinal * $quantity;
                $newTotalQuantity = $previousQty + $quantity;

                if ($newTotalQuantity > 0) {
                    $newAveragePrice = ($currentTotalValue + $newTotalValue) / $newTotalQuantity;
                } else {
                    $newAveragePrice = $unitPriceFinal;
                }

                $productPrice->update([
                    'last_purchase_price' => $unitPriceFinal,
                    'average_purchase_price' => $newAveragePrice,
                ]);

                // Create Transaction log with purchase date
                Transaction::create([
                    'product_id' => $product->id,
                    'setting_id' => $setting->id,
                    'quantity' => $quantity,
                    'current_quantity' => $product->product_quantity,
                    'broken_quantity' => 0,
                    'location_id' => $location->id,
                    'user_id' => auth()->id() ?? 1,
                    'reason' => 'Imported from Purchase #' . $purchase->reference,
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

                // Update row status
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

        } catch (\Exception $e) {
            foreach ($rows as $row) {
                $row->update([
                    'status' => PurchaseImportRow::STATUS_INVALID,
                    'error_message' => $e->getMessage(),
                ]);
                Log::warning('[PurchaseImport] Row error - exception', [
                    'batch_id' => $batch->id,
                    'row_id' => $row->id,
                    'row_number' => $row->row_number,
                    'no_faktur' => $data['no_faktur'] ?? 'Unknown',
                    'error' => $e->getMessage(),
                    'raw_data' => $row->raw_json,
                ]);
                $batch->increment('error_count');
            }

            Log::error('[PurchaseImport] Failed to process invoice group', [
                'invoice' => $data['no_faktur'] ?? 'Unknown',
                'error' => $e->getMessage(),
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
