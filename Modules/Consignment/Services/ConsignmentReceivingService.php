<?php

namespace Modules\Consignment\Services;

use Exception;
use Illuminate\Support\Facades\DB;
use Modules\Consignment\Entities\ConsignmentReceival;
use Modules\Consignment\Entities\ConsignmentReceivalLine;
use Modules\Consignment\Entities\ConsignmentReceiving;
use Modules\Consignment\Entities\ConsignmentReceivingDetail;
use Modules\Consignment\Entities\ConsignmentReceivingDetailSerialNumber;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\SerialNumberHistory;
use Modules\Product\Entities\Transaction;
use Modules\Setting\Entities\Location;

class ConsignmentReceivingService
{
    /**
     * Create a pending ConsignmentReceiving note.
     */
    public function createPendingReceiving(ConsignmentReceival $receival, array $input, int $userId): ConsignmentReceiving
    {
        return DB::transaction(function () use ($receival, $input, $userId) {
            $lockedReceival = ConsignmentReceival::with('lines')
                ->whereKey($receival->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedReceival->status !== ConsignmentReceival::STATUS_APPROVED) {
                throw new Exception("Penerimaan fisik hanya dapat dibuat untuk dokumen konsinyasi yang telah disetujui (APPROVED).");
            }

            // Enforce: exactly one active receiving (PENDING or APPROVED)
            $hasActiveReceiving = ConsignmentReceiving::where('consignment_receival_id', $lockedReceival->id)
                ->whereIn('status', [ConsignmentReceiving::STATUS_PENDING, ConsignmentReceiving::STATUS_APPROVED])
                ->exists();

            if ($hasActiveReceiving) {
                throw new Exception("Dokumen konsinyasi ini sudah memiliki penerimaan fisik yang aktif atau telah disetujui.");
            }

            $locationId = (int) ($input['location_id'] ?? 0);
            $location = Location::whereKey($locationId)->lockForUpdate()->first();

            if (!$location || $location->setting_id !== $lockedReceival->setting_id || !$location->is_consignment || !$location->is_active) {
                throw new Exception("Lokasi penerimaan tidak valid, tidak aktif, atau bukan merupakan lokasi konsinyasi pada bisnis ini.");
            }

            $detailsInput = $input['details'] ?? [];
            $lines = $lockedReceival->lines->keyBy('id');

            if (count($detailsInput) !== $lines->count()) {
                throw new Exception("Semua baris dokumen konsinyasi harus dicatat pada penerimaan penuh ini.");
            }

            $receiving = ConsignmentReferenceService::createReceivingWithReference([
                'consignment_receival_id' => $lockedReceival->id,
                'setting_id' => $lockedReceival->setting_id,
                'location_id' => $location->id,
                'external_delivery_number' => $input['external_delivery_number'] ?? null,
                'date' => $input['date'] ?? now()->toDateString(),
                'status' => ConsignmentReceiving::STATUS_PENDING,
                'note' => $input['note'] ?? null,
                'received_by' => $userId,
                'received_at' => now(),
            ]);

            $allSubmittedSerials = [];

            foreach ($detailsInput as $lineId => $detailData) {
                $line = $lines->get($lineId);
                if (!$line) {
                    throw new Exception("Baris dokumen konsinyasi #{$lineId} tidak valid.");
                }

                $quantityReceived = (float) ($detailData['quantity_received'] ?? 0);
                if (abs($quantityReceived - (float) $line->quantity) > 0.0001) {
                    throw new Exception("Penerimaan konsinyasi harus penuh ({$line->quantity} unit) untuk produk '{$line->product_name}'. Penerimaan parsial tidak diizinkan; buat dokumen penerimaan baru jika pengiriman terpisah.");
                }

                $pendingSerials = null;
                if ($line->is_serialized) {
                    $rawSerials = $detailData['serial_numbers'] ?? [];
                    if (!is_array($rawSerials)) {
                        $rawSerials = preg_split('/[\r\n,]+/', (string) $rawSerials);
                    }

                    $cleanSerials = array_values(array_filter(array_map('trim', $rawSerials)));

                    if (count($cleanSerials) !== (int) $line->quantity || count($cleanSerials) !== (int) $quantityReceived) {
                        throw new Exception("Jumlah nomor seri (" . count($cleanSerials) . ") harus persis sama dengan jumlah disetujui untuk produk '{$line->product_name}' ({$line->quantity}).");
                    }

                    // Check uniqueness within line input
                    if (count($cleanSerials) !== count(array_unique($cleanSerials))) {
                        throw new Exception("Terdapat duplikasi nomor seri dalam input untuk produk '{$line->product_name}'.");
                    }

                    // Check for cross-product duplicates in this receiving
                    $validationService = app(\Modules\Product\Services\ReceivingSerialNumberValidationService::class);
                    foreach ($cleanSerials as $s) {
                        if (in_array($s, $allSubmittedSerials, true)) {
                            throw new Exception("Nomor seri '{$s}' digunakan lebih dari satu kali dalam penerimaan ini.");
                        }
                        $allSubmittedSerials[] = $s;

                        $valRes = $validationService->validateForReceiving((int) $line->product_id, $s);
                        if (!$valRes['valid']) {
                            throw new Exception($valRes['message']);
                        }
                    }

                    $pendingSerials = $cleanSerials;
                } else {
                    if (!empty($detailData['serial_numbers'])) {
                        throw new Exception("Payload nomor seri tidak diizinkan untuk produk non-serial '{$line->product_name}'.");
                    }
                }

                ConsignmentReceivingDetail::create([
                    'consignment_receiving_id' => $receiving->id,
                    'consignment_receival_line_id' => $line->id,
                    'product_id' => $line->product_id,
                    'quantity_received' => $quantityReceived,
                    'unit_cost' => $line->unit_cost,
                    'unit_dpp' => $line->unit_dpp,
                    'tax_id' => $line->tax_id,
                    'tax_rate' => $line->tax_rate,
                    'tax_amount' => $line->tax_amount,
                    'pending_serial_numbers' => $pendingSerials,
                    'notes' => $detailData['notes'] ?? null,
                ]);
            }

            $notificationService = app(\App\Services\Notification\DocumentNotificationService::class);
            $notificationService->notifyApprovalNeeded($receiving, $receiving->receiving_number, $receiving->setting_id);
            $notificationService->resolveRevision($receiving);

            return $receiving->fresh(['details.receivalLine', 'location', 'receival']);
        });
    }

    /**
     * Reject a pending ConsignmentReceiving note.
     */
    public function rejectPendingReceiving(ConsignmentReceiving $receiving, int $userId, string $reason): ConsignmentReceiving
    {
        $reason = trim($reason);
        if (empty($reason)) {
            throw new Exception("Alasan penolakan penerimaan fisik wajib diisi.");
        }

        return DB::transaction(function () use ($receiving, $userId, $reason) {
            $lockedReceiving = ConsignmentReceiving::whereKey($receiving->id)->lockForUpdate()->firstOrFail();

            if ($lockedReceiving->status !== ConsignmentReceiving::STATUS_PENDING) {
                throw new Exception("Hanya penerimaan berstatus 'PENDING' yang dapat ditolak.");
            }

            $lockedReceiving->update([
                'status' => ConsignmentReceiving::STATUS_REJECTED,
                'rejected_by' => $userId,
                'rejected_at' => now(),
                'rejection_reason' => $reason,
            ]);

            $notificationService = app(\App\Services\Notification\DocumentNotificationService::class);
            $notificationService->notifyRevisionNeeded($lockedReceiving, $lockedReceiving->receiving_number, $lockedReceiving->setting_id, $reason);
            $notificationService->resolveApproval($lockedReceiving);

            return $lockedReceiving;
        });
    }

    /**
     * Atomically approve a pending ConsignmentReceiving note.
     */
    public function approveReceiving(ConsignmentReceiving $receiving, int $userId): ConsignmentReceiving
    {
        return DB::transaction(function () use ($receiving, $userId) {
            $lockedReceiving = ConsignmentReceiving::with(['details.receivalLine', 'receival.setting'])
                ->whereKey($receiving->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedReceiving->status !== ConsignmentReceiving::STATUS_PENDING) {
                throw new Exception("Penerimaan fisik ini tidak berstatus PENDING (status saat ini: {$lockedReceiving->status}).");
            }

            $settingId = $lockedReceiving->setting_id;
            $locationId = $lockedReceiving->location_id;

            // Global Consignment lock hierarchy: Receival -> Receival lines -> Receiving details -> Location -> Products -> Serials
            $lockedReceival = ConsignmentReceival::whereKey($lockedReceiving->consignment_receival_id)->lockForUpdate()->firstOrFail();
            ConsignmentReceivalLine::where('consignment_receival_id', $lockedReceival->id)->orderBy('id')->lockForUpdate()->get();
            ConsignmentReceivingDetail::where('consignment_receiving_id', $lockedReceiving->id)->orderBy('id')->lockForUpdate()->get();

            // Lock location
            $location = Location::whereKey($locationId)->lockForUpdate()->firstOrFail();
            if (!$location->is_consignment || $location->setting_id !== $settingId || !$location->is_active) {
                throw new Exception("Lokasi penerimaan tidak valid, tidak aktif, atau bukan merupakan lokasi konsinyasi.");
            }

            $settingLocationIds = Location::where('setting_id', $settingId)->pluck('id');
            $productIds = $lockedReceiving->details->pluck('product_id')->unique()->sort()->values();

            // Lock products in deterministic ID order
            $products = Product::whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $productPrices = ProductPrice::whereIn('product_id', $productIds)
                ->where('setting_id', $settingId)
                ->orderBy('product_id')
                ->lockForUpdate()
                ->get()
                ->keyBy('product_id');

            // Lock existing product stocks for location
            $productStocks = ProductStock::whereIn('product_id', $productIds)
                ->where('location_id', $locationId)
                ->orderBy('product_id')
                ->lockForUpdate()
                ->get()
                ->keyBy('product_id');

            // Collect all pending serials to lock existing ProductSerialNumber rows in deterministic ID order
            $allPendingSerials = [];
            foreach ($lockedReceiving->details as $detail) {
                if ($detail->receivalLine->is_serialized && !empty($detail->pending_serial_numbers)) {
                    foreach ($detail->pending_serial_numbers as $psn) {
                        $allPendingSerials[] = $psn;
                    }
                }
            }
            if (!empty($allPendingSerials)) {
                ProductSerialNumber::whereIn('serial_number', array_unique($allPendingSerials))
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
            }

            // Re-validate details & pending serials under lock
            $validationService = app(\Modules\Product\Services\ReceivingSerialNumberValidationService::class);
            foreach ($lockedReceiving->details as $detail) {
                $line = $detail->receivalLine;
                $qtyReceived = (float) $detail->quantity_received;

                if (abs($qtyReceived - (float) $line->quantity) > 0.0001) {
                    throw new Exception("Jumlah diterima tidak sesuai dengan jumlah dokumen disetujui untuk produk '{$line->product_name}'.");
                }

                if ($line->is_serialized) {
                    $pendingSerials = $detail->pending_serial_numbers ?? [];
                    if (count($pendingSerials) !== (int) $line->quantity || count($pendingSerials) !== (int) $qtyReceived) {
                        throw new Exception("Jumlah nomor seri pending tidak sesuai dengan jumlah produk '{$line->product_name}'.");
                    }
                    foreach ($pendingSerials as $s) {
                        $valRes = $validationService->validateForReceiving((int) $detail->product_id, $s);
                        if (!$valRes['valid']) {
                            throw new Exception("Nomor seri '{$s}' tidak lagi valid saat persetujuan: {$valRes['message']}");
                        }
                    }
                } else {
                    if (!empty($detail->pending_serial_numbers)) {
                        throw new Exception("Payload nomor seri tidak diizinkan untuk produk non-serial '{$line->product_name}'.");
                    }
                }
            }

            foreach ($lockedReceiving->details as $detail) {
                $product = $products->get($detail->product_id);
                if (!$product) {
                    throw new Exception("Produk #{$detail->product_id} tidak ditemukan.");
                }

                $qtyReceived = (float) $detail->quantity_received;

                // 1. Get or create ProductStock row under location lock
                $stock = $productStocks->get($detail->product_id);
                if (!$stock) {
                    $stock = ProductStock::create([
                        'product_id' => $detail->product_id,
                        'location_id' => $locationId,
                        'quantity' => 0,
                        'quantity_tax' => 0,
                        'quantity_non_tax' => 0,
                        'broken_quantity' => 0,
                        'broken_quantity_tax' => 0,
                        'broken_quantity_non_tax' => 0,
                    ]);
                    $productStocks->put($detail->product_id, $stock);
                }

                // Snapshots before
                $stockBefore = (float) $stock->quantity;
                $stockTaxBefore = (float) $stock->quantity_tax;
                $stockNonTaxBefore = (float) $stock->quantity_non_tax;

                $settingQtyBefore = (float) ProductStock::where('product_id', $detail->product_id)
                    ->whereIn('location_id', $settingLocationIds)
                    ->sum('quantity');

                $productPrice = $productPrices->get($detail->product_id);
                if (!$productPrice) {
                    $productPrice = ProductPrice::create([
                        'product_id' => $detail->product_id,
                        'setting_id' => $settingId,
                        'average_purchase_price' => 0,
                        'last_purchase_price' => 0,
                    ]);
                    $productPrices->put($detail->product_id, $productPrice);
                }

                $settingAvgCostBefore = (float) ($productPrice->average_purchase_price ?? 0);

                // 2. Increment Stock Quantities
                $hasTax = !empty($detail->tax_id);
                $stock->increment('quantity', $qtyReceived);
                if ($hasTax) {
                    $stock->increment('quantity_tax', $qtyReceived);
                } else {
                    $stock->increment('quantity_non_tax', $qtyReceived);
                }
                $stock->refresh();

                // Increment aggregate Product quantity
                $product->increment('product_quantity', $qtyReceived);

                $stockAfter = (float) $stock->quantity;
                $stockTaxAfter = (float) $stock->quantity_tax;
                $stockNonTaxAfter = (float) $stock->quantity_non_tax;
                $settingQtyAfter = $settingQtyBefore + $qtyReceived;

                // 3. Operational Weighted Average Cost Calculation
                // Unit DPP is used for average cost
                $unitDpp = (float) $detail->unit_dpp;
                if ($settingQtyBefore <= 0 || $settingAvgCostBefore <= 0) {
                    $settingAvgCostAfter = $unitDpp;
                } else {
                    $totalPriorVal = $settingQtyBefore * $settingAvgCostBefore;
                    $incomingVal = $qtyReceived * $unitDpp;
                    $settingAvgCostAfter = round(($totalPriorVal + $incomingVal) / $settingQtyAfter, 2);
                }

                $productPrice->update([
                    'average_purchase_price' => $settingAvgCostAfter,
                ]);

                // 4. Create CONSIGNMENT_RECEIPT inventory transaction
                $transaction = Transaction::create([
                    'product_id' => $detail->product_id,
                    'setting_id' => $settingId,
                    'type' => 'CONSIGNMENT_RECEIPT',
                    'quantity' => $qtyReceived,
                    'current_quantity' => $settingQtyAfter,
                    'broken_quantity' => 0,
                    'broken_quantity_tax' => 0,
                    'broken_quantity_non_tax' => 0,
                    'location_id' => $locationId,
                    'user_id' => $userId,
                    'reason' => "Penerimaan Konsinyasi {$lockedReceiving->receiving_number} (Ref: {$lockedReceiving->receival->reference})",
                    'previous_quantity' => $settingQtyBefore,
                    'after_quantity' => $settingQtyAfter,
                    'previous_quantity_at_location' => $stockBefore,
                    'after_quantity_at_location' => $stockAfter,
                    'quantity_tax' => $hasTax ? $qtyReceived : 0,
                    'quantity_non_tax' => !$hasTax ? $qtyReceived : 0,
                    'consignment_receiving_detail_id' => $detail->id,
                ]);

                // 5. Process Serial Numbers if serialized
                if ($detail->receivalLine->is_serialized && !empty($detail->pending_serial_numbers)) {
                    foreach ($detail->pending_serial_numbers as $serialString) {
                        $serialNumber = ProductSerialNumber::where('serial_number', $serialString)
                            ->where('product_id', $detail->product_id)
                            ->first();

                        if ($serialNumber) {
                            if ($serialNumber->status === ProductSerialNumber::STATUS_ACTIVE) {
                                throw new Exception("Nomor seri '{$serialString}' sudah aktif di sistem.");
                            }
                            $serialNumber->update([
                                'location_id' => $locationId,
                                'status' => ProductSerialNumber::STATUS_ACTIVE,
                                'tax_id' => $detail->tax_id,
                                'consignment_receiving_detail_id' => $detail->id,
                                'is_broken' => false,
                                'is_in_return_process' => false,
                            ]);
                        } else {
                            $serialNumber = ProductSerialNumber::create([
                                'product_id' => $detail->product_id,
                                'location_id' => $locationId,
                                'serial_number' => $serialString,
                                'status' => ProductSerialNumber::STATUS_ACTIVE,
                                'tax_id' => $detail->tax_id,
                                'consignment_receiving_detail_id' => $detail->id,
                                'is_broken' => false,
                                'is_in_return_process' => false,
                            ]);
                        }

                        // Record SerialNumberHistory
                        $history = SerialNumberHistory::create([
                            'product_serial_number_id' => $serialNumber->id,
                            'event_type' => SerialNumberHistory::EVENT_RECEIVED,
                            'location_id' => $locationId,
                            'reference_type' => ConsignmentReceivingDetail::class,
                            'reference_id' => $detail->id,
                            'user_id' => $userId,
                            'note' => "Penerimaan Konsinyasi {$lockedReceiving->receiving_number}",
                        ]);

                        // Link in pivot table
                        ConsignmentReceivingDetailSerialNumber::create([
                            'consignment_receiving_detail_id' => $detail->id,
                            'product_serial_number_id' => $serialNumber->id,
                            'source_history_id' => $history->id,
                            'linked_at' => now(),
                        ]);
                    }
                }

                // 6. Update Detail with Snapshots, Transaction ID, and clear pending_serial_numbers
                $detail->update([
                    'stock_before' => $stockBefore,
                    'stock_after' => $stockAfter,
                    'stock_tax_before' => $stockTaxBefore,
                    'stock_tax_after' => $stockTaxAfter,
                    'stock_non_tax_before' => $stockNonTaxBefore,
                    'stock_non_tax_after' => $stockNonTaxAfter,
                    'setting_quantity_before' => $settingQtyBefore,
                    'setting_quantity_after' => $settingQtyAfter,
                    'setting_avg_cost_before' => $settingAvgCostBefore,
                    'setting_avg_cost_after' => $settingAvgCostAfter,
                    'transaction_id' => $transaction->id,
                    'pending_serial_numbers' => null,
                ]);
            }

            $lockedReceiving->update([
                'status' => ConsignmentReceiving::STATUS_APPROVED,
                'approved_by' => $userId,
                'approved_at' => now(),
            ]);

            $notificationService = app(\App\Services\Notification\DocumentNotificationService::class);
            $notificationService->resolveApproval($lockedReceiving);

            return $lockedReceiving->fresh(['details', 'location', 'receival']);
        });
    }

    /**
     * Preview reversal eligibility for an approved ConsignmentReceiving note.
     */
    public function previewReversal(ConsignmentReceiving $receiving): array
    {
        $settingId = $receiving->setting_id;
        $locationId = $receiving->location_id;
        $blockers = [];

        if ($receiving->status !== ConsignmentReceiving::STATUS_APPROVED) {
            $blockers[] = "Hanya penerimaan fisik berstatus APPROVED yang dapat dibatalkan (status saat ini: {$receiving->status}).";
            return ['can_reverse' => false, 'blockers' => $blockers];
        }

        $location = Location::find($locationId);
        $settingLocationIds = Location::where('setting_id', $settingId)->pluck('id');

        foreach ($receiving->details as $detail) {
            $product = Product::find($detail->product_id);
            $stock = ProductStock::where('product_id', $detail->product_id)
                ->where('location_id', $locationId)
                ->first();

            $qtyReceived = (float) $detail->quantity_received;

            // 1. Verify current stock at location exactly matches stock_after snapshot
            if ($detail->stock_after !== null) {
                $expectedStock = (float) $detail->stock_after;
                $currentStock = (float) ($stock->quantity ?? 0);
                if (abs($currentStock - $expectedStock) > 0.0001) {
                    $blockers[] = "Stok produk '{$product->product_name}' di lokasi '{$location->name}' saat ini ({$currentStock}) tidak sama dengan kondisi setelah persetujuan ({$expectedStock}). Telah terjadi mutasi atau penjualan lanjutan.";
                }
            } else {
                if (!$stock || $stock->quantity < $qtyReceived) {
                    $blockers[] = "Stok produk '{$product->product_name}' di lokasi '{$location->name}' saat ini (" . ($stock->quantity ?? 0) . ") kurang dari jumlah yang diterima ({$qtyReceived}).";
                }
            }

            // 2. Verify setting average purchase price matches setting_avg_cost_after
            $productPrice = ProductPrice::where('product_id', $detail->product_id)
                ->where('setting_id', $settingId)
                ->first();

            if ($productPrice && $detail->setting_avg_cost_after !== null) {
                $expectedAvg = (float) $detail->setting_avg_cost_after;
                $currentAvg = (float) ($productPrice->average_purchase_price ?? 0);
                if (abs($currentAvg - $expectedAvg) > 0.01) {
                    $blockers[] = "Harga rata-rata (HPP) produk '{$product->product_name}' di pengaturan ini saat ini (Rp " . number_format($currentAvg, 2) . ") tidak sama dengan kondisi setelah persetujuan (Rp " . number_format($expectedAvg, 2) . "). Terdapat transaksi pembelian atau penyesuaian harga lanjutan.";
                }
            }

            // 3. Verify no later inventory transactions exist for this product and location
            $receiptTx = Transaction::where('consignment_receiving_detail_id', $detail->id)
                ->where('type', 'CONSIGNMENT_RECEIPT')
                ->first();

            if ($receiptTx) {
                $laterTxCount = Transaction::where('product_id', $detail->product_id)
                    ->where('location_id', $locationId)
                    ->where('id', '>', $receiptTx->id)
                    ->count();

                if ($laterTxCount > 0) {
                    $blockers[] = "Terdapat {$laterTxCount} transaksi inventaris lanjutan untuk produk '{$product->product_name}' di lokasi ini setelah penerimaan.";
                }
            }

            $hasTax = !empty($detail->tax_id);
            if ($hasTax && (!$stock || $stock->quantity_tax < $qtyReceived)) {
                $blockers[] = "Stok bertarif pajak produk '{$product->product_name}' tidak mencukupi untuk pembalikan.";
            }
            if (!$hasTax && (!$stock || $stock->quantity_non_tax < $qtyReceived)) {
                $blockers[] = "Stok non-pajak produk '{$product->product_name}' tidak mencukupi untuk pembalikan.";
            }

            // Check if serials are still ACTIVE and at this location
            if ($detail->receivalLine->is_serialized) {
                $linkedSerials = $detail->serialNumbers;
                foreach ($linkedSerials as $serial) {
                    if ($serial->status !== ProductSerialNumber::STATUS_ACTIVE || $serial->location_id !== $locationId) {
                        $blockers[] = "Nomor seri '{$serial->serial_number}' ({$product->product_name}) tidak lagi berstatus ACTIVE di lokasi ini (status: {$serial->status}). Reversal tidak dapat dilakukan.";
                    }

                    // Check if there are later histories
                    $pivot = $serial->pivot;
                    $laterHistory = SerialNumberHistory::where('product_serial_number_id', $serial->id)
                        ->where('id', '>', $pivot->source_history_id ?? 0)
                        ->exists();

                    if ($laterHistory) {
                        $blockers[] = "Nomor seri '{$serial->serial_number}' ({$product->product_name}) memiliki mutasi atau riwayat transaksi lanjutan setelah penerimaan ini.";
                    }
                }
            }
        }

        return [
            'can_reverse' => count($blockers) === 0,
            'blockers' => $blockers,
        ];
    }

    /**
     * Atomically reverse an approved ConsignmentReceiving note.
     */
    public function reverseReceiving(ConsignmentReceiving $receiving, int $userId, string $reason): ConsignmentReceiving
    {
        $reason = trim($reason);
        if (empty($reason)) {
            throw new Exception("Alasan pembatalan (reversal) penerimaan konsinyasi wajib diisi.");
        }

        return DB::transaction(function () use ($receiving, $userId, $reason) {
            $lockedReceiving = ConsignmentReceiving::with(['details.receivalLine', 'receival'])
                ->whereKey($receiving->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedReceiving->status !== ConsignmentReceiving::STATUS_APPROVED) {
                throw new Exception("Hanya penerimaan berstatus APPROVED yang dapat dibatalkan.");
            }

            $settingId = $lockedReceiving->setting_id;
            $locationId = $lockedReceiving->location_id;
            $settingLocationIds = Location::where('setting_id', $settingId)->pluck('id');
            $productIds = $lockedReceiving->details->pluck('product_id')->unique();

            // Lock products, prices, and stocks
            $products = Product::whereIn('id', $productIds)->lockForUpdate()->get()->keyBy('id');
            $productPrices = ProductPrice::whereIn('product_id', $productIds)
                ->where('setting_id', $settingId)
                ->lockForUpdate()
                ->get()
                ->keyBy('product_id');

            $productStocks = ProductStock::whereIn('product_id', $productIds)
                ->where('location_id', $locationId)
                ->lockForUpdate()
                ->get()
                ->keyBy('product_id');

            // Authoritative eligibility check under lock
            $blockers = [];
            foreach ($lockedReceiving->details as $detail) {
                $product = $products->get($detail->product_id);
                $stock = $productStocks->get($detail->product_id);
                $qtyReceived = (float) $detail->quantity_received;

                if ($detail->stock_after !== null) {
                    $expectedStock = (float) $detail->stock_after;
                    $currentStock = (float) ($stock->quantity ?? 0);
                    if (abs($currentStock - $expectedStock) > 0.0001) {
                        $blockers[] = "Stok produk '{$product->product_name}' di lokasi saat ini ({$currentStock}) tidak sama dengan kondisi setelah persetujuan ({$expectedStock}).";
                    }
                } else {
                    if (!$stock || $stock->quantity < $qtyReceived) {
                        $blockers[] = "Stok produk '{$product->product_name}' tidak mencukupi untuk pembalikan.";
                    }
                }

                $productPrice = $productPrices->get($detail->product_id);
                if ($productPrice && $detail->setting_avg_cost_after !== null) {
                    $expectedAvg = (float) $detail->setting_avg_cost_after;
                    $currentAvg = (float) ($productPrice->average_purchase_price ?? 0);
                    if (abs($currentAvg - $expectedAvg) > 0.01) {
                        $blockers[] = "Harga rata-rata (HPP) produk '{$product->product_name}' saat ini (Rp " . number_format($currentAvg, 2) . ") telah berubah dari snapshot persetujuan (Rp " . number_format($expectedAvg, 2) . ").";
                    }
                }

                $receiptTx = Transaction::where('consignment_receiving_detail_id', $detail->id)
                    ->where('type', 'CONSIGNMENT_RECEIPT')
                    ->first();

                if ($receiptTx) {
                    $laterTxCount = Transaction::where('product_id', $detail->product_id)
                        ->where('location_id', $locationId)
                        ->where('id', '>', $receiptTx->id)
                        ->count();

                    if ($laterTxCount > 0) {
                        $blockers[] = "Terdapat {$laterTxCount} transaksi inventaris lanjutan untuk produk '{$product->product_name}' di lokasi ini.";
                    }
                }

                $hasTax = !empty($detail->tax_id);
                if ($hasTax && (!$stock || $stock->quantity_tax < $qtyReceived)) {
                    $blockers[] = "Stok bertarif pajak produk '{$product->product_name}' tidak mencukupi untuk pembalikan.";
                }
                if (!$hasTax && (!$stock || $stock->quantity_non_tax < $qtyReceived)) {
                    $blockers[] = "Stok non-pajak produk '{$product->product_name}' tidak mencukupi untuk pembalikan.";
                }

                if ($detail->receivalLine->is_serialized) {
                    $linkedSerials = $detail->serialNumbers;
                    foreach ($linkedSerials as $serial) {
                        if ($serial->status !== ProductSerialNumber::STATUS_ACTIVE || $serial->location_id !== $locationId) {
                            $blockers[] = "Nomor seri '{$serial->serial_number}' tidak lagi berstatus ACTIVE di lokasi ini.";
                        }
                        $pivot = $serial->pivot;
                        $laterHistory = SerialNumberHistory::where('product_serial_number_id', $serial->id)
                            ->where('id', '>', $pivot->source_history_id ?? 0)
                            ->exists();
                        if ($laterHistory) {
                            $blockers[] = "Nomor seri '{$serial->serial_number}' memiliki riwayat transaksi lanjutan.";
                        }
                    }
                }
            }

            if (!empty($blockers)) {
                throw new Exception("Pembalikan ditolak: " . implode('; ', $blockers));
            }

            foreach ($lockedReceiving->details as $detail) {
                $product = $products->get($detail->product_id);
                $stock = $productStocks->get($detail->product_id);
                $qtyReceived = (float) $detail->quantity_received;
                $hasTax = !empty($detail->tax_id);

                $stockBefore = (float) $stock->quantity;

                // Revert stock
                $stock->decrement('quantity', $qtyReceived);
                if ($hasTax) {
                    $stock->decrement('quantity_tax', $qtyReceived);
                } else {
                    $stock->decrement('quantity_non_tax', $qtyReceived);
                }

                $product->decrement('product_quantity', $qtyReceived);

                $settingQtyBefore = (float) ProductStock::where('product_id', $detail->product_id)
                    ->whereIn('location_id', $settingLocationIds)
                    ->sum('quantity') + $qtyReceived;

                $settingQtyAfter = $settingQtyBefore - $qtyReceived;

                // Restore setting average cost snapshot
                $productPrice = $productPrices->get($detail->product_id);
                if ($productPrice && $detail->setting_avg_cost_before !== null) {
                    $productPrice->update([
                        'average_purchase_price' => $detail->setting_avg_cost_before,
                    ]);
                }

                // Create CONSIGNMENT_RECEIPT_REVERSAL transaction
                $reversalTransaction = Transaction::create([
                    'product_id' => $detail->product_id,
                    'setting_id' => $settingId,
                    'type' => 'CONSIGNMENT_RECEIPT_REVERSAL',
                    'quantity' => $qtyReceived,
                    'current_quantity' => $settingQtyAfter,
                    'broken_quantity' => 0,
                    'broken_quantity_tax' => 0,
                    'broken_quantity_non_tax' => 0,
                    'location_id' => $locationId,
                    'user_id' => $userId,
                    'reason' => "Pembatalan Penerimaan Konsinyasi {$lockedReceiving->receiving_number}: {$reason}",
                    'previous_quantity' => $settingQtyBefore,
                    'after_quantity' => $settingQtyAfter,
                    'previous_quantity_at_location' => $stockBefore,
                    'after_quantity_at_location' => (float) $stock->quantity,
                    'quantity_tax' => $hasTax ? $qtyReceived : 0,
                    'quantity_non_tax' => !$hasTax ? $qtyReceived : 0,
                    'consignment_receiving_detail_id' => $detail->id,
                ]);

                // Process serial numbers reversal
                if ($detail->receivalLine->is_serialized) {
                    $pivotRows = ConsignmentReceivingDetailSerialNumber::where('consignment_receiving_detail_id', $detail->id)->get();
                    foreach ($pivotRows as $pivotRow) {
                        $serial = ProductSerialNumber::find($pivotRow->product_serial_number_id);
                        if ($serial) {
                            $serial->update([
                                'status' => ProductSerialNumber::STATUS_RETURNED,
                            ]);

                            $revHistory = SerialNumberHistory::create([
                                'product_serial_number_id' => $serial->id,
                                'setting_id' => $settingId,
                                'event_type' => SerialNumberHistory::EVENT_CONSIGNMENT_REVERSED,
                                'status_before' => ProductSerialNumber::STATUS_ACTIVE,
                                'status_after' => ProductSerialNumber::STATUS_RETURNED,
                                'location_id' => $locationId,
                                'user_id' => $userId,
                                'occurred_at' => now(),
                                'note' => "Reversal Penerimaan Konsinyasi {$lockedReceiving->receiving_number}: {$reason}",
                            ]);

                            $pivotRow->update([
                                'reversal_history_id' => $revHistory->id,
                            ]);
                        }
                    }
                }

                $detail->update([
                    'reversal_transaction_id' => $reversalTransaction->id,
                ]);
            }

            $lockedReceiving->update([
                'status' => ConsignmentReceiving::STATUS_REVERSED,
                'reversed_by' => $userId,
                'reversed_at' => now(),
                'reversal_reason' => $reason,
            ]);

            return $lockedReceiving->fresh(['details', 'location', 'receival']);
        });
    }
}
