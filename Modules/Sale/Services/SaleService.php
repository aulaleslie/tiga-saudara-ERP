<?php

namespace Modules\Sale\Services;

use App\Services\DocumentReferenceService;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Product;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleBundleItem;
use Modules\Sale\Entities\SaleDetails;

class SaleService
{
    /**
     * Validate stock requirements for a sale.
     * Only validates stock-managed parent products and stock-managed bundle components.
     *
     * @param iterable $cartItems
     * @return array Array of error messages, empty if valid.
     */
    public function validateStock(iterable $cartItems): array
    {
        $parentQuantities = [];
        $bundleQuantities = [];

        foreach ($cartItems as $cart_item) {
            $productId = $cart_item->options->product_id;
            if (!isset($parentQuantities[$productId])) {
                $parentQuantities[$productId] = 0;
            }
            $parentQuantities[$productId] += $cart_item->qty;

            if (is_array($cart_item->options->bundle_items)) {
                foreach ($cart_item->options->bundle_items as $bundleItem) {
                    $bundleProductId = $bundleItem['product_id'];
                    $bundleQty = $bundleItem['quantity'] * $cart_item->qty;
                    if (!isset($bundleQuantities[$bundleProductId])) {
                        $bundleQuantities[$bundleProductId] = 0;
                    }
                    $bundleQuantities[$bundleProductId] += $bundleQty;
                }
            }
        }

        $allProductIds = array_unique(array_merge(array_keys($parentQuantities), array_keys($bundleQuantities)));
        $products = Product::whereIn('id', $allProductIds)->get()->keyBy('id');

        $errors = [];
        foreach ($parentQuantities as $productId => $requestedQty) {
            $product = $products->get($productId);
            if (!$product) {
                $errors[] = "Produk ID {$productId} tidak ditemukan.";
            } elseif ($product->stock_managed !== false && $requestedQty > $product->product_quantity) {
                $errors[] = "Stok produk '{$product->product_name}' tidak mencukupi. Tersedia: {$product->product_quantity}, Diminta: {$requestedQty}";
            }
        }

        foreach ($bundleQuantities as $productId => $requestedQty) {
            $product = $products->get($productId);
            if (!$product) {
                $errors[] = "Produk bundle ID {$productId} tidak ditemukan.";
            } elseif ($product->stock_managed !== false && $requestedQty > $product->product_quantity) {
                $errors[] = "Stok produk bundle '{$product->product_name}' tidak mencukupi untuk memenuhi pesanan. Tersedia: {$product->product_quantity}, Diminta: {$requestedQty}";
            }
        }

        return $errors;
    }

    /**
     * Store a new sale.
     *
     * @param array $data
     * @param iterable $cartItems
     * @return Sale
     * @throws Exception
     */
    public function createSale(array $data, iterable $cartItems): Sale
    {
        $errors = $this->validateStock($cartItems);
        if (!empty($errors)) {
            throw new Exception(implode("\n", $errors));
        }

        return DB::transaction(function () use ($data, $cartItems) {
            $customer = Customer::findOrFail($data['customer_id']);
            $isPkp = (bool) (\Modules\Setting\Entities\Setting::query()->whereKey((int) $data['setting_id'])->value('is_pkp') ?? false);
            $normalizedSale = app(SaleNormalizer::class)->normalize([
                'tax_id' => $data['tax_id'] ?? null,
                'tax_percentage' => $data['tax_percentage'] ?? 0,
                'discount_percentage' => $data['discount_percentage'] ?? 0,
                'discount_amount' => $data['discount_amount'] ?? 0,
                'shipping_amount' => $data['shipping_amount'] ?? 0,
                'paid_amount' => $data['paid_amount'] ?? 0,
            ], $cartItems, $isPkp);
            $header = $normalizedSale['header'];

            $sale = DocumentReferenceService::createSaleWithReference([
                'date' => $data['date'],
                'due_date' => $data['due_date'],
                'customer_id' => $data['customer_id'],
                'customer_name' => $customer->customer_name,
                'tax_id' => $header['tax_id'],
                'tax_percentage' => $header['tax_percentage'],
                'tax_amount' => $header['tax_amount'],
                'discount_percentage' => $header['discount_percentage'],
                'discount_amount' => $header['discount_amount'],
                'shipping_amount' => $header['shipping_amount'],
                'total_amount' => $header['total_amount'],
                'due_amount' => $header['due_amount'],
                'paid_amount' => $header['paid_amount'],
                'status' => $data['status'] ?? Sale::STATUS_DRAFTED,
                'payment_status' => $data['payment_status'] ?? 'Unpaid',
                'payment_term_id' => $data['payment_term_id'] ?? null,
                'note' => $data['note'] ?? null,
                'setting_id' => $data['setting_id'],
                'is_tax_included' => $data['is_tax_included'] ?? false,
                'payment_method' => $data['payment_method'] ?? '',
                'tax_ref_no' => $isPkp ? ($data['tax_ref_no'] ?? null) : null,
            ]);

            if (!empty($data['tags'])) {
                $sale->syncTags($data['tags']);
            }

            foreach ($normalizedSale['details'] as $item) {
                $saleDetail = SaleDetails::create([
                    'sale_id' => $sale->id,
                    'product_id' => $item['product_id'],
                    'product_name' => $item['product_name'],
                    'product_code' => $item['product_code'],
                    'quantity' => $item['quantity'],
                    'unit_price' => round((float) $item['unit_price'], 2),
                    'price' => round((float) $item['price'], 2),
                    'product_discount_type' => $item['product_discount_type'],
                    'product_discount_amount' => round((float) $item['product_discount_amount'], 2),
                    'sub_total' => round((float) $item['sub_total'], 2),
                    'product_tax_amount' => round((float) $item['product_tax_amount'], 2),
                    'tax_id' => $item['tax_id'],
                    'pricing_source' => strtolower((string) ($item['pricing_source'] ?? 'automatic')),
                ]);

                if (!empty($item['bundle_items'])) {
                    foreach ($item['bundle_items'] as $bundleItem) {
                        SaleBundleItem::create([
                            'sale_detail_id' => $saleDetail->id,
                            'sale_id' => $sale->id,
                            'bundle_id' => $bundleItem['bundle_id'] ?? null,
                            'bundle_item_id' => $bundleItem['bundle_item_id'] ?? null,
                            'product_id' => $bundleItem['product_id'],
                            'name' => $bundleItem['name'],
                            'price' => round((float) ($bundleItem['price'] ?? 0), 2),
                            'quantity' => $bundleItem['quantity'],
                            'sub_total' => round((float) ($bundleItem['sub_total'] ?? 0), 2),
                        ]);
                    }
                }
                
                app(\Modules\Sale\Services\SalesCostSnapshotService::class)->snapshotSaleDetailCost($saleDetail);
                $saleDetail->save();
            }

            return $sale;
        });
    }

    /**
     * Update an existing sale.
     *
     * @param Sale $sale
     * @param array $data
     * @param iterable $cartItems
     * @return Sale
     * @throws Exception
     */
    public function updateSale(Sale $sale, array $data, iterable $cartItems): Sale
    {
        // Permission and status checks (Moved from controller for consistency)
        //
        // Dispatched documents never reach this path: it deletes and recreates
        // sale_details, which would drop dispatch/bundle/serial links and
        // regenerate HPP cost snapshots. Post-dispatch monetary edits go
        // through SaleMonetaryEditService instead.
        if (in_array($sale->status, [Sale::STATUS_DISPATCHED, Sale::STATUS_DISPATCHED_PARTIALLY])) {
            throw new Exception('Tidak dapat memperbarui penjualan yang sudah dikirim barangnya.');
        }

        if ($sale->status === Sale::STATUS_APPROVED && !auth()->user()->can('sales.approved.edit')) {
            throw new Exception('Anda tidak memiliki akses untuk memperbarui penjualan yang sudah disetujui.');
        }

        // Potential stock restoration if switching between dispatched statuses in one update
        // However, standard flow is DRAFTED -> WAITING_APPROVAL -> APPROVED -> (Partially) DISPATCHED.
        // If the status in request is DISPATCHED, we might need to deduct stock.

        return DB::transaction(function () use ($sale, $data, $cartItems) {
            $customer = Customer::findOrFail($data['customer_id']);
            // Determine the normalization PKP setting: use data['setting_id'] if provided, else use sale->setting_id
            $targetSettingId = $data['setting_id'] ?? $sale->setting_id;
            $isPkp = (bool) (\Modules\Setting\Entities\Setting::query()->whereKey((int) $targetSettingId)->value('is_pkp') ?? false);

            // If changing to a different business, atomically allocate and move reference
            $newReference = null;
            if (isset($data['setting_id']) && $data['setting_id'] !== $sale->setting_id) {
                $saleDate = \Carbon\Carbon::parse($data['date'] ?? $sale->date);
                $year = $saleDate->year;
                $month = $saleDate->month;

                // Lock the target Setting row for the entire transaction
                $targetSetting = \Modules\Setting\Entities\Setting::whereKey($targetSettingId)->lockForUpdate()->firstOrFail();

                // Fetch the latest reference for the target setting, year, and month
                $latestReference = Sale::withArchived()
                    ->where('setting_id', $targetSettingId)
                    ->whereYear('date', $year)
                    ->whereMonth('date', $month)
                    ->latest('id')
                    ->value('reference');

                // Calculate next number
                $nextNumber = 1;
                if ($latestReference) {
                    $parts = explode('-', $latestReference);
                    $lastNumber = (int) end($parts);
                    $nextNumber = $lastNumber + 1;
                }

                // Build prefix
                $prefix = (optional($targetSetting)->document_prefix ?: '') . '-'
                    . (optional($targetSetting)->sale_prefix_document ?: 'SL');

                // Generate reference
                $newReference = make_reference_id($prefix, $year, $month, $nextNumber);
            }

            $normalizedSale = app(SaleNormalizer::class)->normalize([
                'tax_id' => $data['tax_id'] ?? $sale->tax_id,
                'tax_percentage' => $data['tax_percentage'] ?? $sale->tax_percentage,
                'discount_percentage' => $data['discount_percentage'] ?? $sale->discount_percentage,
                'discount_amount' => $data['discount_amount'] ?? $sale->discount_amount,
                'shipping_amount' => $data['shipping_amount'] ?? $sale->shipping_amount,
                'paid_amount' => $data['paid_amount'] ?? $sale->paid_amount,
            ], $cartItems, $isPkp);
            $header = $normalizedSale['header'];

            if (round($header['due_amount'], 2) >= $header['total_amount']) {
                $payment_status = 'Unpaid';
            } elseif ($header['due_amount'] > 0) {
                $payment_status = 'Partial';
            } else {
                $payment_status = 'Paid';
            }

            // Handle stock restoration if sale was previously dispatched (unlikely in this edit flow but safe)
            if ($sale->status == Sale::STATUS_DISPATCHED) {
                foreach ($sale->saleDetails as $oldDetail) {
                    $product = Product::find($oldDetail->product_id);
                    if ($product) {
                        $product->increment('product_quantity', $oldDetail->quantity);
                    }
                }
            }

            // Delete old details and bundles
            $sale->saleDetails()->delete();
            SaleBundleItem::where('sale_id', $sale->id)->delete();

            $updateData = [
                'date' => $data['date'],
                'reference' => $newReference ?? ($data['reference'] ?? $sale->reference),
                'customer_id' => $data['customer_id'],
                'customer_name' => $customer->customer_name,
                'tax_id' => $header['tax_id'],
                'tax_percentage' => $header['tax_percentage'],
                'discount_percentage' => $header['discount_percentage'],
                'discount_amount' => $header['discount_amount'],
                'shipping_amount' => $header['shipping_amount'],
                'paid_amount' => $header['paid_amount'],
                'total_amount' => $header['total_amount'],
                'due_amount' => $header['due_amount'],
                'status' => $data['status'] ?? $sale->status,
                'payment_status' => $payment_status,
                'payment_method' => $data['payment_method'] ?? $sale->payment_method,
                'note' => $data['note'] ?? null,
                'tax_amount' => $header['tax_amount'],
                'payment_term_id' => $data['payment_term_id'] ?? $sale->payment_term_id,
                'is_tax_included' => $data['is_tax_included'] ?? $sale->is_tax_included,
                'tax_ref_no' => $isPkp ? ($data['tax_ref_no'] ?? $sale->tax_ref_no) : null,
            ];

            // If changing to a different business, persist the new setting_id
            if (isset($data['setting_id']) && $data['setting_id'] !== $sale->setting_id) {
                $updateData['setting_id'] = $data['setting_id'];
            }

            $sale->update($updateData);

            if (isset($data['tags'])) {
                $sale->syncTags($data['tags']);
            }

            foreach ($normalizedSale['details'] as $item) {
                $saleDetail = SaleDetails::create([
                    'sale_id' => $sale->id,
                    'product_id' => $item['product_id'],
                    'product_name' => $item['product_name'],
                    'product_code' => $item['product_code'],
                    'quantity' => $item['quantity'],
                    'price' => round((float) $item['price'], 2),
                    'unit_price' => round((float) $item['unit_price'], 2),
                    'sub_total' => round((float) $item['sub_total'], 2),
                    'product_discount_amount' => round((float) $item['product_discount_amount'], 2),
                    'product_discount_type' => $item['product_discount_type'],
                    'product_tax_amount' => round((float) $item['product_tax_amount'], 2),
                    'tax_id' => $item['tax_id'],
                    'pricing_source' => strtolower((string) ($item['pricing_source'] ?? 'automatic')),
                ]);

                if (! empty($item['bundle_items'])) {
                    foreach ($item['bundle_items'] as $bundleItem) {
                        SaleBundleItem::create([
                            'sale_detail_id' => $saleDetail->id,
                            'sale_id' => $sale->id,
                            'bundle_id' => $bundleItem['bundle_id'] ?? null,
                            'bundle_item_id' => $bundleItem['bundle_item_id'] ?? null,
                            'product_id' => $bundleItem['product_id'],
                            'name' => $bundleItem['name'],
                            'price' => round((float) ($bundleItem['price'] ?? 0), 2),
                            'quantity' => $bundleItem['quantity'],
                            'sub_total' => round((float) ($bundleItem['sub_total'] ?? 0), 2),
                        ]);
                    }
                }

                app(\Modules\Sale\Services\SalesCostSnapshotService::class)->snapshotSaleDetailCost($saleDetail);
                $saleDetail->save();
            }

            return $sale;
        });
    }
}
