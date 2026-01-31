<?php

namespace Modules\Sale\Services;

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

        $errors = [];
        foreach ($parentQuantities as $productId => $requestedQty) {
            $product = Product::find($productId);
            if (!$product) {
                $errors[] = "Produk ID {$productId} tidak ditemukan.";
            } elseif ($requestedQty > $product->product_quantity) {
                $errors[] = "Stok produk '{$product->product_name}' tidak mencukupi. Tersedia: {$product->product_quantity}, Diminta: {$requestedQty}";
            }
        }

        foreach ($bundleQuantities as $productId => $requestedQty) {
            $product = Product::find($productId);
            if (!$product) {
                $errors[] = "Produk bundle ID {$productId} tidak ditemukan.";
            } elseif ($requestedQty > $product->product_quantity) {
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
            
            $sale = Sale::create([
                'date' => $data['date'],
                'due_date' => $data['due_date'],
                'customer_id' => $data['customer_id'],
                'customer_name' => $customer->customer_name,
                'tax_id' => $data['tax_id'] ?? null,
                'tax_percentage' => $data['tax_percentage'] ?? 0,
                'tax_amount' => $data['tax_amount'] ?? 0,
                'discount_percentage' => $data['discount_percentage'] ?? 0,
                'discount_amount' => $data['discount_amount'] ?? 0,
                'shipping_amount' => $data['shipping_amount'] ?? 0,
                'total_amount' => $data['total_amount'],
                'due_amount' => $data['due_amount'] ?? $data['total_amount'],
                'paid_amount' => $data['paid_amount'] ?? 0,
                'status' => $data['status'] ?? Sale::STATUS_DRAFTED,
                'payment_status' => $data['payment_status'] ?? 'Unpaid',
                'payment_term_id' => $data['payment_term_id'] ?? null,
                'note' => $data['note'] ?? null,
                'setting_id' => $data['setting_id'],
                'is_tax_included' => $data['is_tax_included'] ?? false,
                'payment_method' => $data['payment_method'] ?? '',
                'tax_ref_no' => $data['tax_ref_no'] ?? null,
            ]);

            if (!empty($data['tags'])) {
                $sale->syncTags($data['tags']);
            }

            $aggregatedItems = SaleCartAggregator::aggregate($cartItems);

            foreach ($aggregatedItems as $item) {
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
                    'tax_id' => $item['tax_id'] ?? null,
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

            $due_amount = round((float) $data['total_amount'] - (float) ($data['paid_amount'] ?? 0), 2);
            $due_amount = max($due_amount, 0);
            $total_amount = round((float) $data['total_amount'], 2);

            if (round($due_amount, 2) >= $total_amount) {
                $payment_status = 'Unpaid';
            } elseif ($due_amount > 0) {
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

            $sale->update([
                'date' => $data['date'],
                'reference' => $data['reference'] ?? $sale->reference,
                'customer_id' => $data['customer_id'],
                'customer_name' => $customer->customer_name,
                'tax_percentage' => $data['tax_percentage'] ?? 0,
                'discount_percentage' => $data['discount_percentage'] ?? 0,
                'shipping_amount' => round((float) ($data['shipping_amount'] ?? 0), 2),
                'paid_amount' => round((float) ($data['paid_amount'] ?? 0), 2),
                'total_amount' => $total_amount,
                'due_amount' => $due_amount,
                'status' => $data['status'] ?? $sale->status,
                'payment_status' => $payment_status,
                'payment_method' => $data['payment_method'] ?? $sale->payment_method,
                'note' => $data['note'] ?? null,
                'tax_amount' => $data['tax_amount'] ?? 0,
                'discount_amount' => $data['discount_amount'] ?? 0,
                'tax_ref_no' => $data['tax_ref_no'] ?? $sale->tax_ref_no,
            ]);

            if (isset($data['tags'])) {
                $sale->syncTags($data['tags']);
            }

            foreach ($cartItems as $cart_item) {
                $productId = $cart_item->options->product_id;
                
                $saleDetail = SaleDetails::create([
                    'sale_id' => $sale->id,
                    'product_id' => $productId,
                    'product_name' => $cart_item->name,
                    'product_code' => $cart_item->options->code,
                    'quantity' => $cart_item->qty,
                    'price' => round((float) $cart_item->price, 2),
                    'unit_price' => round((float) $cart_item->options->unit_price, 2),
                    'sub_total' => round((float) $cart_item->options->sub_total, 2),
                    'product_discount_amount' => round((float) $cart_item->options->product_discount, 2),
                    'product_discount_type' => $cart_item->options->product_discount_type,
                    'product_tax_amount' => round((float) ($cart_item->options->product_tax_amount ?? $cart_item->options->product_tax ?? 0), 2),
                    'tax_id' => $cart_item->options->product_tax ?: null,
                ]);

                if (!empty($cart_item->options->bundle_items)) {
                    foreach ($cart_item->options->bundle_items as $bundleItem) {
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

                // Deduct stock if status changed to DISPATCHED
                if (($data['status'] ?? '') == Sale::STATUS_DISPATCHED) {
                    $product = Product::find($productId);
                    if ($product) {
                        $product->decrement('product_quantity', $cart_item->qty);
                    }
                }
            }

            return $sale;
        });
    }
}
