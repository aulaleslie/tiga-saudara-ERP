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

            Log::info('[SalesImport] Created new tax', [
                'tax_id' => $tax->id,
                'percentage' => $percentage,
            ]);
        }

        return $tax;
    }

    /**
     * Find or create a customer by name.
     * Note: Customers are global (not scoped by setting_id).
     */
    public function findOrCreateCustomer(string $name, ?string $contactName = null, ?string $phone = null): Customer
    {
        $customer = Customer::whereRaw('LOWER(customer_name) = ?', [strtolower(trim($name))])->first();

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

        return $customer;
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

            Log::info('[SalesImport] Created new product', [
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
    public function processBatch(SalesImportBatch $batch): void
    {
        $batch->update(['status' => SalesImportBatch::STATUS_PROCESSING]);

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
                'status' => SalesImportBatch::STATUS_COMPLETED,
                'processed_rows' => $batch->rows()->whereIn('status', ['processed', 'invalid'])->count(),
            ]);

            Log::info('[SalesImport] Batch completed', [
                'batch_id' => $batch->id,
                'success_count' => $batch->success_count,
                'error_count' => $batch->error_count,
            ]);
        } catch (\Exception $e) {
            $batch->update(['status' => SalesImportBatch::STATUS_FAILED]);
            Log::error('[SalesImport] Batch failed', [
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
            $dueDate = !empty($data['tanggal_jatuh_tempo']) 
                ? $this->parseDate($data['tanggal_jatuh_tempo']) 
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
                $unitPrice = (float) ($rowData['harga_satuan'] ?? 0);
                $taxAmount = (float) ($rowData['pajak'] ?? 0);
                $subtotal = $quantity * $unitPrice;

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

                $details[] = [
                    'row' => $row,
                    'product' => $product,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'subtotal' => $subtotal,
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
            $sale->save();

            // Sync tag to sale
            if (!empty($tag)) {
                $sale->syncTags([trim($tag)]);
            }

            // Get first location for this setting
            $location = Location::where('setting_id', $setting->id)->first();
            if (!$location) {
                throw new \Exception("No location found for setting: {$setting->company_name}");
            }

            // Create sale details
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
            }

            // Auto-dispatch: Create Dispatch and DispatchDetail, decrement stock
            $this->dispatchSale($sale, $details, $location, $setting, $saleDate);

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
    protected function dispatchSale(Sale $sale, array $details, Location $location, Setting $setting, Carbon $saleDate): void
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
                'setting_id' => $setting->id,
                'quantity' => -$quantity,
                'current_quantity' => $product->product_quantity,
                'broken_quantity' => 0,
                'location_id' => $location->id,
                'user_id' => auth()->id() ?? 1,
                'reason' => 'Imported from Sale #' . $sale->reference,
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
}
