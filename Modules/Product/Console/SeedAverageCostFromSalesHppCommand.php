<?php

namespace Modules\Product\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Entities\ReceivedNote;
use Modules\Purchase\Entities\ReceivedNoteDetail;
use Modules\Sale\Entities\SaleDetails;
use Modules\Setting\Entities\Setting;

class SeedAverageCostFromSalesHppCommand extends Command
{
    protected $signature = 'product:seed-average-cost-from-sales-hpp {--write : Apply the changes to the database}';
    protected $description = 'Seed average purchase prices from the latest imported HPP sales snapshots.';

    public function handle()
    {
        $isWrite = $this->option('write');
        $mode = $isWrite ? 'WRITE' : 'DRY-RUN';

        $this->info("Starting product average-cost and last-purchase-price seeding in $mode mode...");

        $settings = Setting::all();
        $specialSettingIds = $this->getSpecialSettingIds($settings);
        $perdanaSettingIds = $specialSettingIds['perdana'] ?? [];

        $consideredCount = 0;
        $skippedCount = 0;
        $createdCount = 0;
        $updatedCount = 0;
        $unchangedCount = 0;

        Product::where('stock_managed', true)
            ->chunkById(100, function ($products) use (
                $isWrite,
                $settings,
                $specialSettingIds,
                $perdanaSettingIds,
                &$consideredCount,
                &$skippedCount,
                &$createdCount,
                &$updatedCount,
                &$unchangedCount
            ) {
                $productIds = $products->pluck('id')->toArray();
                $literalPurchaseCandidates = $this->getLiteralPurchaseCandidates($productIds, $specialSettingIds, $perdanaSettingIds);

                foreach ($products as $product) {
                    $consideredCount++;

                    $hppBuckets = $this->resolveProductBuckets($product, $settings, $specialSettingIds);
                    $purchaseCandidates = $literalPurchaseCandidates[$product->id] ?? [];

                    $this->processProductBuckets(
                        $product,
                        $hppBuckets,
                        $purchaseCandidates,
                        $specialSettingIds,
                        $isWrite,
                        $skippedCount,
                        $createdCount,
                        $updatedCount,
                        $unchangedCount
                    );
                }
            });

        $this->outputDryRunReport(
            $mode,
            $consideredCount,
            $skippedCount,
            $createdCount,
            $updatedCount,
            $unchangedCount
        );

        return 0;
    }

    private function getSpecialSettingIds(Collection $settings): array
    {
        $tigaNusaIds = [];
        $topItIds = [];
        $perdanaIds = [];

        foreach ($settings as $setting) {
            $name = strtolower(trim($setting->company_name ?? ''));
            if ($name === 'cv tiga nusa computer') {
                $tigaNusaIds[] = $setting->id;
            } elseif ($name === 'cv top it internusa') {
                $topItIds[] = $setting->id;
            } elseif ($name === 'perdana') {
                $perdanaIds[] = $setting->id;
            }
        }

        return [
            'tiga_nusa' => $tigaNusaIds,
            'top_it' => $topItIds,
            'perdana' => $perdanaIds,
        ];
    }

    private function resolveProductBuckets(Product $product, Collection $settings, array $specialSettingIds): array
    {
        $buckets = [
            'tiga_nusa' => null,
            'top_it' => null,
            'perdana' => null,
        ];

        $candidates = $this->getHppImportCandidates($product);

        foreach ($candidates as $candidate) {
            $bucket = $this->getBucketForSetting($candidate['setting_id'], $specialSettingIds);
            if ($bucket && !isset($buckets[$bucket])) {
                $buckets[$bucket] = $candidate;
            }
        }

        return array_filter($buckets, fn($v) => $v !== null);
    }

    private function getHppImportCandidates(Product $product): array
    {
        $candidates = SaleDetails::where('product_id', $product->id)
            ->where('cost_snapshot_source', 'HPP_SNAPSHOT_IMPORT')
            ->whereRaw('cost_unit_snapshot > 0')
            ->with('sale:id,date,setting_id')
            ->get()
            ->map(fn($detail) => [
                'detail_id' => $detail->id,
                'sale_id' => $detail->sale_id,
                'sale_date' => is_string($detail->sale->date)
                    ? $detail->sale->date
                    : $detail->sale->date->format('Y-m-d H:i:s'),
                'setting_id' => $detail->sale->setting_id,
                'cost_unit_snapshot' => $detail->cost_unit_snapshot,
            ])
            ->toArray();

        usort($candidates, function ($a, $b) {
            if ($a['sale_date'] !== $b['sale_date']) {
                return $b['sale_date'] <=> $a['sale_date'];
            }
            if ($a['sale_id'] !== $b['sale_id']) {
                return $b['sale_id'] <=> $a['sale_id'];
            }
            return $b['detail_id'] <=> $a['detail_id'];
        });

        return $candidates;
    }

    private function getBucketForSetting(int $settingId, array $specialSettingIds): ?string
    {
        if (in_array($settingId, $specialSettingIds['tiga_nusa'])) {
            return 'tiga_nusa';
        }
        if (in_array($settingId, $specialSettingIds['top_it'])) {
            return 'top_it';
        }
        if (in_array($settingId, $specialSettingIds['perdana'])) {
            return 'perdana';
        }
        return null;
    }

    private function processProductBuckets(
        Product $product,
        array $hppBuckets,
        array $purchaseCandidates,
        array $specialSettingIds,
        bool $isWrite,
        &$skippedCount,
        &$createdCount,
        &$updatedCount,
        &$unchangedCount
    ): void {
        $allSettings = Setting::all();

        foreach ($allSettings as $setting) {
            $bucket = $this->getBucketForSetting($setting->id, $specialSettingIds);
            $hppCandidate = null;
            $purchaseCandidate = null;

            if ($bucket && isset($hppBuckets[$bucket])) {
                $hppCandidate = $hppBuckets[$bucket];
            } elseif ($bucket && in_array($bucket, ['tiga_nusa', 'top_it']) && isset($hppBuckets['perdana'])) {
                $hppCandidate = $hppBuckets['perdana'];
            } elseif (!$bucket && isset($hppBuckets['perdana'])) {
                $hppCandidate = $hppBuckets['perdana'];
            }

            if ($purchaseCandidates) {
                $purchaseCandidate = $this->resolvePurchaseCandidate($purchaseCandidates, $setting, $specialSettingIds);
            }

            $existingPrice = ProductPrice::where('product_id', $product->id)
                ->where('setting_id', $setting->id)
                ->first();

            if ($existingPrice) {
                if ($hppCandidate) {
                    $this->updateOrSkipProductPrice(
                        $existingPrice,
                        $hppCandidate,
                        $purchaseCandidate,
                        $isWrite,
                        $unchangedCount,
                        $updatedCount
                    );
                } else {
                    $skippedCount++;
                }
            } else {
                if (!$hppCandidate) {
                    $skippedCount++;
                    continue;
                }

                if ($purchaseCandidate) {
                    if ($isWrite) {
                        $this->createProductPrice($product, $setting, $hppCandidate, $purchaseCandidate);
                    }
                    $createdCount++;
                } else {
                    $skippedCount++;
                }
            }
        }
    }

    private function getLiteralPurchaseCandidates(array $productIds, array $specialSettingIds, array $perdanaSettingIds): array
    {
        $candidates = [];

        PurchaseDetail::whereIn('product_id', $productIds)
            ->with([
                'purchase:id,setting_id,date,archived_at,status',
                'purchase.receivedNotes:id,po_id,approved_at,status'
            ])
            ->chunkById(500, function ($details) use (&$candidates, $specialSettingIds, $perdanaSettingIds) {
                $detailIds = $details->pluck('id')->toArray();
                $partialApprovedAts = $this->getLatestApprovedAtsForPartialDetails($detailIds);

                foreach ($details as $detail) {
                    $purchase = $detail->purchase;

                    if ($purchase->archived_at || !in_array($purchase->status, ['RECEIVED', 'RECEIVED PARTIALLY'])) {
                        continue;
                    }

                    if (!$detail->quantity || $detail->quantity <= 0) {
                        continue;
                    }

                    $approvedAt = null;

                    if ($purchase->status === 'RECEIVED PARTIALLY') {
                        $approvedAt = $partialApprovedAts[$detail->id] ?? null;
                        if ($approvedAt === null) {
                            continue;
                        }
                    } else {
                        if ($purchase->receivedNotes && $purchase->receivedNotes->count() > 0) {
                            $approvedNotes = $purchase->receivedNotes->filter(fn($n) => $n->status === 'APPROVED');
                            if ($approvedNotes->count() > 0) {
                                $latest = $approvedNotes->sortByDesc('approved_at')->first();
                                if ($latest && $latest->approved_at) {
                                    $approvedAt = $latest->approved_at;
                                }
                            }
                        }
                    }

                    $productId = $detail->product_id;
                    $settingId = $purchase->setting_id;

                    $purchaseDate = is_string($purchase->date)
                        ? $purchase->date
                        : $purchase->date->format('Y-m-d H:i:s');

                    $unitPrice = $this->calculateLiteralPurchaseUnitPrice($detail);

                    $candidate = [
                        'detail_id' => $detail->id,
                        'purchase_id' => $purchase->id,
                        'setting_id' => $settingId,
                        'approved_at' => $approvedAt,
                        'purchase_date' => $purchaseDate,
                        'unit_price' => $unitPrice,
                    ];

                    if (!isset($candidates[$productId])) {
                        $candidates[$productId] = [];
                    }

                    $candidates[$productId][] = $candidate;
                }
            });

        foreach ($candidates as &$productCandidates) {
            $this->sortAndGroupPurchaseCandidates($productCandidates);
        }

        return $candidates;
    }

    private function getLatestApprovedAtsForPartialDetails(array $detailIds): array
    {
        $result = [];

        if (empty($detailIds)) {
            return $result;
        }

        $receivedNoteDetails = ReceivedNoteDetail::whereIn('po_detail_id', $detailIds)
            ->where('quantity_received', '>', 0)
            ->with(['receivedNote' => function ($q) {
                $q->where('status', 'APPROVED')
                  ->whereNotNull('approved_at')
                  ->select('id', 'approved_at');
            }])
            ->select('id', 'po_detail_id', 'received_note_id', 'quantity_received')
            ->get();

        $detailToLatest = [];

        foreach ($receivedNoteDetails as $rnd) {
            if (!$rnd->receivedNote || !$rnd->receivedNote->approved_at) {
                continue;
            }

            $poDetailId = $rnd->po_detail_id;
            $approvedAt = is_string($rnd->receivedNote->approved_at)
                ? $rnd->receivedNote->approved_at
                : $rnd->receivedNote->approved_at->format('Y-m-d H:i:s');

            if (!isset($detailToLatest[$poDetailId])) {
                $detailToLatest[$poDetailId] = [
                    'approved_at' => $approvedAt,
                    'received_note_detail_id' => $rnd->id,
                    'received_note_id' => $rnd->received_note_id,
                ];
            } else {
                $current = $detailToLatest[$poDetailId];
                if ($approvedAt > $current['approved_at']) {
                    $detailToLatest[$poDetailId] = [
                        'approved_at' => $approvedAt,
                        'received_note_detail_id' => $rnd->id,
                        'received_note_id' => $rnd->received_note_id,
                    ];
                } elseif ($approvedAt === $current['approved_at']) {
                    if ($rnd->id > $current['received_note_detail_id']) {
                        $detailToLatest[$poDetailId] = [
                            'approved_at' => $approvedAt,
                            'received_note_detail_id' => $rnd->id,
                            'received_note_id' => $rnd->received_note_id,
                        ];
                    } elseif ($rnd->id === $current['received_note_detail_id'] && $rnd->received_note_id > $current['received_note_id']) {
                        $detailToLatest[$poDetailId] = [
                            'approved_at' => $approvedAt,
                            'received_note_detail_id' => $rnd->id,
                            'received_note_id' => $rnd->received_note_id,
                        ];
                    }
                }
            }
        }

        foreach ($detailToLatest as $poDetailId => $data) {
            $result[$poDetailId] = $data['approved_at'];
        }

        return $result;
    }

    private function calculateLiteralPurchaseUnitPrice(PurchaseDetail $detail): float
    {
        $subTotal = (float) $detail->sub_total;
        $discount = (float) ($detail->product_discount_amount ?? 0);
        $quantity = (float) $detail->quantity;

        if ($quantity <= 0) {
            return 0;
        }

        $total = $subTotal + $discount;
        return round($total / $quantity, 2);
    }

    private function sortAndGroupPurchaseCandidates(array &$candidates): void
    {
        usort($candidates, function ($a, $b) {
            if ($a['approved_at'] !== $b['approved_at']) {
                if ($a['approved_at'] === null) {
                    return 1;
                }
                if ($b['approved_at'] === null) {
                    return -1;
                }
                return $b['approved_at'] <=> $a['approved_at'];
            }

            if ($a['purchase_date'] !== $b['purchase_date']) {
                return $b['purchase_date'] <=> $a['purchase_date'];
            }

            if ($a['purchase_id'] !== $b['purchase_id']) {
                return $b['purchase_id'] <=> $a['purchase_id'];
            }

            return $b['detail_id'] <=> $a['detail_id'];
        });
    }

    private function resolvePurchaseCandidate(array $purchaseCandidates, Setting $setting, array $specialSettingIds): ?array
    {
        $perdanaIds = $specialSettingIds['perdana'] ?? [];

        foreach ($purchaseCandidates as $candidate) {
            if ($candidate['setting_id'] === $setting->id) {
                return $candidate;
            }
        }

        foreach ($purchaseCandidates as $candidate) {
            if (in_array($candidate['setting_id'], $perdanaIds)) {
                return $candidate;
            }
        }

        return null;
    }

    private function updateOrSkipProductPrice(
        ProductPrice $price,
        ?array $hppCandidate,
        ?array $purchaseCandidate,
        bool $isWrite,
        &$unchangedCount,
        &$updatedCount
    ): void {
        $updateData = [];

        if ($hppCandidate) {
            $newAverage = $hppCandidate['cost_unit_snapshot'];
            if ((float) $price->average_purchase_price !== (float) $newAverage) {
                $updateData['average_purchase_price'] = $newAverage;
            }
        }

        if ($purchaseCandidate) {
            $newLastPrice = $purchaseCandidate['unit_price'];
            if ((float) $price->last_purchase_price !== (float) $newLastPrice) {
                $updateData['last_purchase_price'] = $newLastPrice;
            }
        }

        if (!$updateData) {
            $unchangedCount++;
            return;
        }

        if ($isWrite) {
            $price->update($updateData);
        }
        $updatedCount++;
    }

    private function createProductPrice(Product $product, Setting $setting, ?array $hppCandidate, ?array $purchaseCandidate = null): void
    {
        $template = ProductPrice::where('product_id', $product->id)->first();

        $data = [
            'product_id' => $product->id,
            'setting_id' => $setting->id,
            'average_purchase_price' => $hppCandidate['cost_unit_snapshot'] ?? 0,
            'last_purchase_price' => $purchaseCandidate['unit_price'] ?? 0,
            'sale_price' => $template?->sale_price ?? 0,
            'purchase_tax_id' => $template?->purchase_tax_id,
            'sale_tax_id' => $template?->sale_tax_id,
        ];

        if ($template) {
            $data['tier_1_price'] = $template->tier_1_price;
            $data['tier_2_price'] = $template->tier_2_price;
        }

        ProductPrice::create($data);
    }

    private function outputDryRunReport(
        string $mode,
        int $consideredCount,
        int $skippedCount,
        int $createdCount,
        int $updatedCount,
        int $unchangedCount
    ): void {
        $this->line('');
        $this->info("== $mode MODE ==");
        $this->info("Considered products: $consideredCount");
        $this->info("Created: $createdCount");
        $this->info("Updated: $updatedCount");
        $this->info("Unchanged: $unchangedCount");
        $this->info("Skipped: $skippedCount");
    }
}
