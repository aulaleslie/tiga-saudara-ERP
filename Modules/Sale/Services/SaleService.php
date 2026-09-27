<?php

namespace Modules\Sale\Services;

use App\Services\DocumentReferenceService;
use App\Services\Sequence\DocumentSequenceAllocator;
use App\Services\Sequence\DocumentType;
use Carbon\Carbon;
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
     * Store a new sale.
     *
     * @param array $data
     * @param iterable $cartItems
     * @return Sale
     * @throws Exception
     */
    /**
     * Non-blocking missing-HPP warnings surfaced by the most recent
     * createSale()/updateSale() call, after successful persistence.
     *
     * @var array<int, array{level: string, message: string, product_id: int|null, sale_bundle_item_id: int|null}>
     */
    public array $lastMissingCostWarnings = [];

    public function createSale(array $data, iterable $cartItems): Sale
    {
        $this->lastMissingCostWarnings = [];

        $allocator = app(DocumentSequenceAllocator::class);
        $namespace = $allocator->buildNamespace(
            DocumentType::SALE,
            (int) $data['setting_id'],
            Carbon::parse($data['date'])
        );

        return $allocator->executeWholeOperationWithConflictRetry(function () use ($data, $cartItems) {
            $attemptWarnings = [];
            $customer = Customer::findOrFail($data['customer_id']);
            $isPkp = (bool) (\Modules\Setting\Entities\Setting::query()->whereKey((int) $data['setting_id'])->value('is_pkp') ?? false);
            $normalizedSale = app(SaleNormalizer::class)->normalize([
                'tax_id' => $data['tax_id'] ?? null,
                'tax_percentage' => $data['tax_percentage'] ?? 0,
                'discount_percentage' => $data['discount_percentage'] ?? 0,
                'discount_amount' => $data['discount_amount'] ?? 0,
                'shipping_amount' => $data['shipping_amount'] ?? 0,
                'paid_amount' => $data['paid_amount'] ?? 0,
            ], $cartItems, $isPkp, (int) $data['setting_id']);
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
                            'informational_item_price' => isset($bundleItem['informational_item_price']) && is_numeric($bundleItem['informational_item_price'])
                                ? round((float) $bundleItem['informational_item_price'], 2)
                                : null,
                            'quantity' => $bundleItem['quantity'],
                            'sub_total' => round((float) ($bundleItem['sub_total'] ?? 0), 2),
                        ]);
                    }
                }
                
                $warnings = app(\Modules\Sale\Services\SalesCostSnapshotService::class)->snapshotSaleDetailCost($saleDetail);
                $saleDetail->save();
                $attemptWarnings = array_merge($attemptWarnings, $warnings);
            }

            $this->lastMissingCostWarnings = $attemptWarnings;

            return $sale;
        }, static fn (): array => [$namespace], 3);
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

        $this->lastMissingCostWarnings = [];

        $targetSettingId = (int) ($data['setting_id'] ?? $sale->setting_id);
        $businessChanged = $targetSettingId !== (int) $sale->setting_id;
        $allocator = app(DocumentSequenceAllocator::class);
        $namespace = $businessChanged
            ? $allocator->buildNamespace(DocumentType::SALE, $targetSettingId, Carbon::parse($data['date'] ?? $sale->date))
            : null;

        $operation = function () use ($sale, $data, $cartItems, $businessChanged, $namespace, $allocator) {
            $customer = Customer::findOrFail($data['customer_id']);
            // Determine the normalization PKP setting: use data['setting_id'] if provided, else use sale->setting_id
            $targetSettingId = $data['setting_id'] ?? $sale->setting_id;
            $isPkp = (bool) (\Modules\Setting\Entities\Setting::query()->whereKey((int) $targetSettingId)->value('is_pkp') ?? false);

            // If changing to a different business, atomically allocate and move reference via shared allocator
            $newReference = null;
            if ($businessChanged) {
                if ($sale->status !== Sale::STATUS_DRAFTED) {
                    throw new Exception('Hanya penjualan berstatus drafted yang dapat dipindahkan ke bisnis lain.');
                }

                $allocation = $allocator->allocate($namespace);
                $newReference = $allocation->reference;
            }

            $normalizedSale = app(SaleNormalizer::class)->normalize([
                'tax_id' => $data['tax_id'] ?? $sale->tax_id,
                'tax_percentage' => $data['tax_percentage'] ?? $sale->tax_percentage,
                'discount_percentage' => $data['discount_percentage'] ?? $sale->discount_percentage,
                'discount_amount' => $data['discount_amount'] ?? $sale->discount_amount,
                'shipping_amount' => $data['shipping_amount'] ?? $sale->shipping_amount,
                'paid_amount' => $data['paid_amount'] ?? $sale->paid_amount,
            ], $cartItems, $isPkp, (int) $targetSettingId);
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
            if ($businessChanged) {
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
                            'informational_item_price' => isset($bundleItem['informational_item_price']) && is_numeric($bundleItem['informational_item_price'])
                                ? round((float) $bundleItem['informational_item_price'], 2)
                                : null,
                            'quantity' => $bundleItem['quantity'],
                            'sub_total' => round((float) ($bundleItem['sub_total'] ?? 0), 2),
                        ]);
                    }
                }

                $warnings = app(\Modules\Sale\Services\SalesCostSnapshotService::class)->snapshotSaleDetailCost($saleDetail);
                $saleDetail->save();
                $this->lastMissingCostWarnings = array_merge($this->lastMissingCostWarnings, $warnings);
            }

            return $sale;
        };

        if ($namespace !== null) {
            return $allocator->executeWholeOperationWithConflictRetry(
                $operation,
                static fn (): array => [$namespace],
                3
            );
        }

        return DB::transaction($operation, 3);
    }
}
