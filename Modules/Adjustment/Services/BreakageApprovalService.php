<?php

namespace Modules\Adjustment\Services;

use App\Models\User;
use App\Services\Notification\DocumentNotificationService;
use App\Services\Notification\StockNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Adjustment\DTOs\BreakageApprovalResult;
use Modules\Adjustment\Entities\Adjustment;
use Modules\Adjustment\Entities\AdjustmentStatus;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\Transaction;
use Modules\Setting\Entities\Location;

/**
 * Atomic approval poster for breakage Adjustments (design.md "Approval
 * atomically moves good stock to broken stock").
 *
 * Locks the document, destination location/setting, affected product stocks,
 * and selected serials in one deterministic order, then revalidates the
 * complete movement against the CURRENTLY locked state using the same
 * BreakageSerialPolicy the pending preview uses (never trusting the earlier
 * preview). Any shortage, PKP bucket inconsistency, or invalid serial blocks
 * the ENTIRE approval -- no partial line is ever applied. Preserves total
 * physical quantity (good+bad) and tax classification: only condition
 * changes.
 */
class BreakageApprovalService
{
    public function __construct(
        private BreakageSerialPolicy $serialPolicy,
    ) {
    }

    public function approve(Adjustment $adjustment, User $actor, ?int $activeSettingId = null): Adjustment
    {
        $activeSettingId ??= (int) session('setting_id');

        if (!$actor->can('adjustments.breakage.approval') && !$actor->can('adjustments.approval')) {
            throw ValidationException::withMessages([
                'permission' => ['Anda tidak memiliki izin untuk menyetujui penyesuaian barang rusak ini.'],
            ]);
        }

        return DB::transaction(function () use ($adjustment, $actor, $activeSettingId) {
            /** @var Adjustment $locked */
            $locked = Adjustment::where('id', $adjustment->id)->lockForUpdate()->firstOrFail();

            // This service is a public domain entry point and must not trust
            // a caller (the controller, or anything calling it directly) to
            // have already confirmed the document is actually a breakage
            // adjustment -- a 'normal' adjustment sharing breakage's legacy
            // 'pending' status must never be approved/mutated through this
            // path. Checked immediately after locking, before the idempotent
            // already-approved return below.
            $normalizedType = \Illuminate\Support\Str::of($locked->type)->lower()->trim()->value();
            if ($normalizedType !== 'breakage') {
                throw ValidationException::withMessages([
                    'type' => ['Dokumen ini bukan penyesuaian barang rusak.'],
                ]);
            }

            $status = AdjustmentStatus::normalize($locked->status);

            if (!$locked->location_id) {
                throw ValidationException::withMessages([
                    'location_id' => ['Dokumen barang rusak tidak memiliki lokasi.'],
                ]);
            }

            // ---- Lock location, then its setting ----
            $location = Location::where('id', $locked->location_id)->lockForUpdate()->first();
            if (!$location) {
                throw ValidationException::withMessages([
                    'location_id' => ['Lokasi tujuan tidak ditemukan.'],
                ]);
            }

            $setting = \Modules\Setting\Entities\Setting::where('id', $location->setting_id)->lockForUpdate()->first();
            if (!$setting) {
                throw ValidationException::withMessages([
                    'location_id' => ['Pengaturan pemilik lokasi tujuan tidak ditemukan.'],
                ]);
            }
            $location->setRelation('setting', $setting);

            // Ownership is asserted BEFORE the idempotent-approved early
            // return below: a caller re-approving an already-approved
            // document must still be rejected if that document's location no
            // longer belongs to the caller's active setting -- lifecycle
            // endpoints must enforce the active-setting boundary
            // consistently regardless of whether this call turns out to be
            // a no-op.
            app(AdjustmentOwnershipGuard::class)->assertLocationOwned($location, $activeSettingId);

            if ($status === AdjustmentStatus::Approved) {
                // Idempotent: already approved, no-op re-posting.
                return $locked;
            }

            if ($status !== AdjustmentStatus::Pending) {
                throw ValidationException::withMessages([
                    'status' => ['Hanya dokumen barang rusak berstatus menunggu persetujuan yang dapat disetujui.'],
                ]);
            }

            if ($location->is_consignment) {
                throw ValidationException::withMessages([
                    'location_id' => ['Penyesuaian barang rusak tidak dapat dilakukan pada lokasi konsinyasi.'],
                ]);
            }

            $isPkp = (bool) $setting->is_pkp;

            $locked->loadMissing('adjustedProducts');
            $adjustedProducts = $locked->adjustedProducts;

            if ($adjustedProducts->isEmpty()) {
                throw ValidationException::withMessages([
                    'adjusted_products' => ['Dokumen barang rusak kosong tidak dapat disetujui.'],
                ]);
            }

            $productIds = $adjustedProducts->pluck('product_id')->unique()->values();

            // A document must never carry two rows for the same product: two
            // independently-classified rows could otherwise both claim the
            // same serial (each row's classification runs against the full
            // locked serial set, not against what a sibling row already
            // claimed), moving more stock than the number of distinct
            // serials actually marked broken.
            if ($adjustedProducts->count() !== $productIds->count()) {
                throw ValidationException::withMessages([
                    'adjusted_products' => ['Dokumen barang rusak memiliki produk duplikat.'],
                ]);
            }

            // ---- Lock Product rows: active and stock-managed only. The
            // product catalogue is global -- Product::setting_id never gates
            // approval eligibility (see StockOpnameApprovalService::approve()
            // for the same documented rule). Ownership of this operation is
            // enforced entirely through the already-locked destination
            // Location/Setting above and the location-scoped stock/serial
            // handling below, never by filtering products to the
            // destination setting's own catalogue. ----
            $products = Product::whereIn('id', $productIds)
                ->with('baseUnit')
                ->active()
                ->where('stock_managed', true)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($productIds as $productId) {
                if (!$products->has($productId)) {
                    throw ValidationException::withMessages([
                        'adjusted_products' => ["Produk dengan ID {$productId} tidak ditemukan, tidak aktif, atau bukan produk yang stoknya dikelola."],
                    ]);
                }
            }

            // ---- Lock ProductStock rows at the destination location ----
            $stocksByProduct = ProductStock::whereIn('product_id', $productIds)
                ->where('location_id', $location->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('product_id');

            // ---- Lock every candidate serial referenced by this document ----
            // A serial must appear in at most ONE row document-wide: two rows
            // classifying the same serial ID independently could both accept
            // it (each row's ProductSerialNumber lookup does not know about a
            // sibling row's claim), moving two stock units while only one
            // serial is actually marked broken. Reject the whole document
            // before any row is classified rather than let a later pass
            // silently double-count.
            $allSerialIdsFlat = collect();
            foreach ($adjustedProducts as $adjustedProduct) {
                $ids = array_map('intval', (array) (json_decode((string) $adjustedProduct->serial_numbers, true) ?? []));
                $allSerialIdsFlat = $allSerialIdsFlat->concat($ids);
            }

            if ($allSerialIdsFlat->count() !== $allSerialIdsFlat->unique()->count()) {
                throw ValidationException::withMessages([
                    'adjusted_products' => ['Nomor seri yang sama digunakan lebih dari satu kali dalam dokumen barang rusak ini.'],
                ]);
            }

            $allSerialIds = $allSerialIdsFlat->unique()->sort()->values();

            $lockedSerialsById = collect();
            if ($allSerialIds->isNotEmpty()) {
                $lockedSerialsById = ProductSerialNumber::whereIn('id', $allSerialIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');
            }

            // ---- Pass 1: build the full mutation plan and reject on ANY
            // conflict or insufficient-bucket condition before mutating
            // anything ----
            $conflicts = [];
            $plans = [];

            foreach ($adjustedProducts as $adjustedProduct) {
                $product = $products->get($adjustedProduct->product_id);
                if (!$product) {
                    continue;
                }

                $stock = $stocksByProduct->get($product->id);
                $isSerialized = (bool) $product->serial_number_required;

                if ($isSerialized) {
                    $serialIds = array_map('intval', (array) (json_decode((string) $adjustedProduct->serial_numbers, true) ?? []));
                    $persistedQuantityTax = (int) ($adjustedProduct->quantity_tax ?? 0);
                    $persistedQuantityNonTax = (int) ($adjustedProduct->quantity_non_tax ?? 0);
                    $persistedQuantity = (int) ($adjustedProduct->quantity ?? 0);
                    $serialCount = count($serialIds);

                    $classification = $this->classifyLockedSerials($product, $location, $isPkp, $serialIds, $lockedSerialsById);
                    $conflicts = array_merge($conflicts, $classification['conflicts']);

                    $movement = count($classification['eligible']);

                    // A serialized row's every persisted quantity field must
                    // agree with its own distinct serial count AND land
                    // entirely in the bucket the destination location's PKP
                    // setting mandates -- e.g. a PKP location must never
                    // accept quantity_tax=0/quantity_non_tax=1 even though
                    // their sum matches the serial count. A document tampered
                    // to report a different or wrongly-bucketed quantity than
                    // its serial_numbers array actually supports is rejected
                    // rather than silently reconciled to whichever value.
                    $expectedTax = $isPkp ? $serialCount : 0;
                    $expectedNonTax = $isPkp ? 0 : $serialCount;

                    if ($persistedQuantity !== $serialCount
                        || $persistedQuantityTax !== $expectedTax
                        || $persistedQuantityNonTax !== $expectedNonTax
                    ) {
                        $conflicts[] = sprintf(
                            'Produk %s: kuantitas tersimpan (quantity=%d, tax=%d, non_tax=%d) tidak sama dengan jumlah nomor seri (%d) pada kelompok pajak yang sesuai dengan pengaturan PKP lokasi ini.',
                            $product->product_name,
                            $persistedQuantity,
                            $persistedQuantityTax,
                            $persistedQuantityNonTax,
                            $serialCount
                        );
                    }

                    $currentGood = $isPkp
                        ? (int) round((float) ($stock?->quantity_tax ?? 0))
                        : (int) round((float) ($stock?->quantity_non_tax ?? 0));

                    if ($movement === 0) {
                        $conflicts[] = sprintf('Produk %s: tidak ada nomor seri yang valid untuk diproses.', $product->product_name);
                    }

                    if ($movement > $currentGood) {
                        $conflicts[] = sprintf(
                            'Produk %s: stok baik tersedia (%d) tidak mencukupi untuk %d nomor seri.',
                            $product->product_name,
                            $currentGood,
                            $movement
                        );
                    }

                    $plans[$adjustedProduct->id] = [
                        'type' => 'serialized',
                        'product' => $product,
                        'stock' => $stock,
                        'movement' => $movement,
                        'eligible' => $classification['eligible'],
                    ];
                } else {
                    $requestedTax = (int) ($adjustedProduct->quantity_tax ?? 0);
                    $requestedNonTax = (int) ($adjustedProduct->quantity_non_tax ?? 0);
                    $movement = $isPkp ? $requestedTax : $requestedNonTax;
                    $otherBucket = $isPkp ? $requestedNonTax : $requestedTax;

                    if ($otherBucket > 0) {
                        $conflicts[] = sprintf(
                            'Produk %s: permintaan berada pada kelompok pajak yang tidak sesuai dengan pengaturan PKP lokasi ini.',
                            $product->product_name
                        );
                    }

                    if ($movement <= 0) {
                        $conflicts[] = sprintf('Produk %s: kuantitas breakage harus lebih dari 0.', $product->product_name);
                    }

                    $currentGood = $isPkp
                        ? (int) round((float) ($stock?->quantity_tax ?? 0))
                        : (int) round((float) ($stock?->quantity_non_tax ?? 0));

                    if ($movement > $currentGood) {
                        $conflicts[] = sprintf(
                            'Produk %s: stok baik tersedia (%d) tidak mencukupi untuk permintaan breakage (%d).',
                            $product->product_name,
                            $currentGood,
                            $movement
                        );
                    }

                    $plans[$adjustedProduct->id] = [
                        'type' => 'non_serialized',
                        'product' => $product,
                        'stock' => $stock,
                        'movement' => $movement,
                    ];
                }
            }

            if (!empty($conflicts)) {
                throw ValidationException::withMessages([
                    'adjusted_products' => array_values(array_unique($conflicts)),
                ]);
            }

            // ---- Pass 2: apply every planned mutation ----
            $now = now();
            $appliedProducts = [];
            $stockNotificationChecks = [];

            foreach ($adjustedProducts as $adjustedProduct) {
                $plan = $plans[$adjustedProduct->id] ?? null;
                if (!$plan) {
                    continue;
                }

                $product = $plan['product'];
                $stock = $plan['stock'] ?? ProductStock::create([
                    'product_id' => $product->id,
                    'location_id' => $location->id,
                    'quantity' => 0, 'quantity_tax' => 0, 'quantity_non_tax' => 0,
                    'broken_quantity' => 0, 'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => 0,
                ]);
                $stock = ProductStock::where('id', $stock->id)->lockForUpdate()->first();

                $movement = $plan['movement'];

                $beforeGood = (int) round((float) ($stock->quantity_tax ?? 0)) + (int) round((float) ($stock->quantity_non_tax ?? 0));
                $beforeBad = (int) round((float) ($stock->broken_quantity_tax ?? 0)) + (int) round((float) ($stock->broken_quantity_non_tax ?? 0));
                $beforeTotal = $beforeGood + $beforeBad;

                if ($isPkp) {
                    $stock->quantity_tax = max(0, (int) round((float) $stock->quantity_tax) - $movement);
                    $stock->broken_quantity_tax = (int) round((float) $stock->broken_quantity_tax) + $movement;
                } else {
                    $stock->quantity_non_tax = max(0, (int) round((float) $stock->quantity_non_tax) - $movement);
                    $stock->broken_quantity_non_tax = (int) round((float) $stock->broken_quantity_non_tax) + $movement;
                }
                $stock->broken_quantity = (int) round((float) $stock->broken_quantity_tax) + (int) round((float) $stock->broken_quantity_non_tax);
                $stock->quantity = (int) round((float) $stock->quantity_tax) + (int) round((float) $stock->quantity_non_tax) + $stock->broken_quantity;
                $stock->save();

                $afterGood = (int) round((float) $stock->quantity_tax) + (int) round((float) $stock->quantity_non_tax);
                $afterBad = (int) round((float) $stock->broken_quantity_tax) + (int) round((float) $stock->broken_quantity_non_tax);
                $afterTotal = $afterGood + $afterBad;

                // Physical total invariant: breakage never changes good+bad total.
                if ($afterTotal !== $beforeTotal) {
                    throw ValidationException::withMessages([
                        'adjusted_products' => ["Produk {$product->product_name}: total fisik berubah saat proses breakage, yang seharusnya tidak terjadi."],
                    ]);
                }

                $serialsEvidence = [];
                if ($plan['type'] === 'serialized') {
                    $serialIds = collect($plan['eligible'])->pluck('serial_id');
                    ProductSerialNumber::whereIn('id', $serialIds)->update(['is_broken' => true]);

                    foreach ($plan['eligible'] as $eligibleSerial) {
                        $serialsEvidence[] = [
                            'serial_id' => $eligibleSerial['serial_id'],
                            'serial_number' => $eligibleSerial['serial_number'],
                            'condition_before' => 'good',
                            'condition_after' => 'broken',
                        ];
                    }
                }

                Transaction::create([
                    'product_id' => $product->id,
                    'setting_id' => $setting->id,
                    'type' => 'ADJ',
                    'quantity' => 0,
                    'current_quantity' => (float) $stock->quantity,
                    'broken_quantity' => (float) $stock->broken_quantity,
                    'previous_quantity' => (float) $product->product_quantity,
                    'previous_quantity_at_location' => (float) $beforeTotal,
                    'after_quantity' => (float) $product->product_quantity,
                    'after_quantity_at_location' => (float) $afterTotal,
                    'quantity_tax' => (float) $stock->quantity_tax,
                    'quantity_non_tax' => (float) $stock->quantity_non_tax,
                    'broken_quantity_tax' => (float) $stock->broken_quantity_tax,
                    'broken_quantity_non_tax' => (float) $stock->broken_quantity_non_tax,
                    'location_id' => $location->id,
                    'user_id' => $actor->id,
                    'reason' => "Penyesuaian barang rusak disetujui ({$locked->reference})",
                ]);

                if ($movement > 0) {
                    $product->broken_quantity = (int) ($product->broken_quantity ?? 0) + $movement;
                    $product->save();
                }

                // Stock alerts are computed against SELLABLE (good) stock,
                // not physical total (which breakage intentionally never
                // changes) -- feed checkLocationStock() the good-stock
                // before/after so a breakage that pushes good stock at or
                // below the alert threshold still notifies.
                $stockNotificationChecks["location:{$stock->id}"] = [
                    'scope' => 'location', 'stock' => $stock,
                    'before' => $beforeGood, 'after' => $afterGood,
                ];

                $appliedProducts[] = [
                    'product_id' => (int) $product->id,
                    'product_name' => (string) $product->product_name,
                    'product_code' => (string) $product->product_code,
                    'base_unit' => (string) ($product->baseUnit?->unit_name ?? $product->baseUnit?->name ?? ''),
                    'is_serialized' => $plan['type'] === 'serialized',
                    'before' => ['good' => $beforeGood, 'bad' => $beforeBad],
                    'movement' => $movement,
                    'after' => ['good' => $afterGood, 'bad' => $afterBad],
                    'serials' => $serialsEvidence,
                ];
            }

            foreach ($stockNotificationChecks as $check) {
                if ((int) $check['before'] !== (int) $check['after']) {
                    app(StockNotificationService::class)->checkLocationStock($check['stock'], $check['before'], $check['after']);
                }
            }

            $approvalResult = BreakageApprovalResult::build(
                adjustmentId: (int) $locked->id,
                locationId: (int) $location->id,
                locationName: (string) $location->name,
                settingId: (int) $setting->id,
                isPkp: $isPkp,
                approvedBy: (int) $actor->id,
                approvedByName: (string) ($actor->name ?? ''),
                approvedAt: $now->toIso8601String(),
                products: $appliedProducts,
            );

            $locked->update([
                'status' => AdjustmentStatus::Approved,
                'approved_by' => $actor->id,
                'approved_at' => $now,
                'approval_result' => $approvalResult,
            ]);

            app(DocumentNotificationService::class)->resolveApproval($locked);
            app(DocumentNotificationService::class)->resolveRevision($locked);

            return $locked;
        });
    }

    /**
     * Re-run BreakageSerialPolicy's eligibility rules against the ALREADY
     * LOCKED serial rows (never a fresh unlocked query), so the pending
     * preview's classification is never trusted for the actual mutation.
     */
    private function classifyLockedSerials(Product $product, Location $location, bool $isPkp, array $serialIds, $lockedSerialsById): array
    {
        $serialIds = array_values(array_unique(array_map('intval', $serialIds)));
        $eligible = [];
        $conflicts = [];

        if (empty($serialIds)) {
            $conflicts[] = sprintf('Produk %s: nomor seri wajib dipilih untuk produk bertipe serial.', $product->product_name);

            return ['eligible' => $eligible, 'conflicts' => $conflicts];
        }

        foreach ($serialIds as $serialId) {
            /** @var ProductSerialNumber|null $serial */
            $serial = $lockedSerialsById->get($serialId);

            if (!$serial) {
                $conflicts[] = "Nomor seri dengan ID {$serialId} tidak ditemukan.";
                continue;
            }

            if ((int) $serial->product_id !== (int) $product->id) {
                $conflicts[] = sprintf('Nomor seri %s bukan milik produk %s.', $serial->serial_number, $product->product_name);
                continue;
            }

            if ((int) $serial->location_id !== (int) $location->id) {
                $conflicts[] = sprintf('Nomor seri %s berada di lokasi lain, bukan lokasi terpilih.', $serial->serial_number);
                continue;
            }

            if (!$serial->isSellable()) {
                $conflicts[] = sprintf('Nomor seri %s tidak tersedia (terkirim, dalam proses retur, sudah rusak, atau tidak aktif).', $serial->serial_number);
                continue;
            }

            $serialIsTax = $serial->tax_id !== null;
            if ($serialIsTax !== $isPkp) {
                $conflicts[] = sprintf('Nomor seri %s memiliki klasifikasi pajak yang tidak sesuai dengan pengaturan PKP lokasi ini.', $serial->serial_number);
                continue;
            }

            $eligible[] = [
                'serial_id' => (int) $serial->id,
                'serial_number' => $serial->serial_number,
                'tax_id' => $serial->tax_id,
                'is_tax' => $serialIsTax,
            ];
        }

        return ['eligible' => $eligible, 'conflicts' => $conflicts];
    }
}
