<?php

namespace Modules\Adjustment\Services;

use App\Models\User;
use App\Services\SerialNumberHistoryService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferActionHistory;
use Modules\Adjustment\Entities\TransferActiveSerialClaim;
use Modules\Adjustment\Entities\TransferMovement;
use Modules\Adjustment\Entities\TransferMovementAllocation;
use Modules\Adjustment\Entities\TransferMovementHistory;
use Modules\Adjustment\Entities\TransferMovementLine;
use Modules\Adjustment\Entities\TransferMovementSerial;
use Modules\Adjustment\Entities\TransferRoutePolicy;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\SerialNumberHistory;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use RuntimeException;

/**
 * Confirmed version 3 approval: validates the reviewed plan and dispatches
 * every allocation in one transaction, or nothing. No stock reservation
 * precedes this; every check reruns against locked live state.
 */
class TransferV3DispatchExecutor
{
    public function __construct(
        private TransferV3AllocationService $allocations,
        private TransferRoutePolicyResolver $policyResolver,
    ) {
    }

    public function approveAndDispatch(
        Transfer $transfer,
        User $actor,
        int $activeSettingId,
        int $reviewedRequestRevisionId,
        int $reviewedConfigurationRevision,
        ?string $operationKey = null
    ): Transfer {
        return DB::transaction(function () use ($transfer, $actor, $activeSettingId, $reviewedRequestRevisionId, $reviewedConfigurationRevision, $operationKey) {
            $locked = Transfer::whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            TransferV3Access::assertV3($locked);

            // A committed replay returns its recorded outcome without effects.
            if (TransferV3EventRecorder::replayed($locked, TransferActionHistory::ACTION_DISPATCHED, $operationKey)) {
                return $locked;
            }

            if ($locked->status !== Transfer::STATUS_PENDING) {
                throw new RuntimeException('Transfer tidak lagi menunggu persetujuan (status saat ini: ' . $locked->status . ').');
            }

            if ((int) $locked->current_request_revision_id !== $reviewedRequestRevisionId
                || (int) $locked->approval_configuration_revision !== $reviewedConfigurationRevision) {
                throw new RuntimeException('Ringkasan persetujuan sudah tidak berlaku karena dokumen atau alokasi berubah. Tinjau ringkasan terbaru.');
            }

            $plan = $this->allocations->currentPlan($locked);
            $requestRevision = \Modules\Adjustment\Entities\TransferRequestRevision::findOrFail($locked->current_request_revision_id);

            // Stable lock order: locations, settings, products, stock rows, serials.
            $locationIds = $plan->pluck('source_location_id')->merge($plan->pluck('destination_location_id'))->filter()->unique()->sort()->values()->all();
            $locations = Location::whereIn('id', $locationIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $settingIds = $locations->pluck('setting_id')->unique()->sort()->values()->all();
            $settings = Setting::whereIn('id', $settingIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $productIds = $plan->pluck('product_id')->unique()->sort()->values()->all();
            $products = Product::whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            $stocks = ProductStock::whereIn('product_id', $productIds)
                ->whereIn('location_id', $plan->pluck('source_location_id')->unique()->all())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (ProductStock $stock) => $stock->product_id . ':' . $stock->location_id);

            $serialIds = $plan->flatMap(fn ($row) => $row->serials->pluck('product_serial_number_id'))->map(fn ($id) => (int) $id)->sort()->values()->all();
            $liveSerials = ProductSerialNumber::whereIn('id', $serialIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            // Complete-plan validation against the locked live state.
            $errors = $this->allocations->validatePlan($locked);
            if ($errors !== []) {
                throw new RuntimeException(implode(' ', $errors));
            }

            $isBroken = $locked->stock_condition === Transfer::CONDITION_BREAKAGE;
            $now = Carbon::now();

            $movement = TransferMovement::create([
                'transfer_id'             => $locked->id,
                'type'                    => TransferMovement::TYPE_FORWARD_DISPATCH,
                'revision'                => 1,
                'transfer_revision'       => $locked->revision,
                'status'                  => TransferMovement::STATUS_APPROVED,
                'stock_condition'         => $locked->stock_condition,
                'origin_location_id'      => null,
                'destination_location_id' => null,
                'created_by'              => $actor->id,
                'submitted_by'            => $actor->id,
                'reviewed_by'             => $actor->id,
                'submitted_at'            => $now,
                'reviewed_at'             => $now,
                'metadata'                => [
                    'workflow_version'       => Transfer::WORKFLOW_V3,
                    'request_revision_id'    => $reviewedRequestRevisionId,
                    'configuration_revision' => $reviewedConfigurationRevision,
                    // Frozen tracking mode read by receipt and cancellation.
                    'serialized_products'    => collect($plan->pluck('product_id')->unique())
                        ->mapWithKeys(fn ($id) => [(string) $id => $requestRevision->isSerializedProduct((int) $id)])
                        ->all(),
                ],
            ]);

            $lines = [];
            foreach ($plan->groupBy('product_id') as $productId => $rows) {
                $lines[(int) $productId] = TransferMovementLine::create([
                    'transfer_movement_id' => $movement->id,
                    'product_id'           => (int) $productId,
                    'quantity'             => (int) $rows->sum('quantity'),
                    'count_confirmed'      => true,
                ]);
            }

            $taxSnapshot = null;
            $lineBuckets = [];

            foreach ($plan as $row) {
                $product = $products->get($row->product_id);
                $source = $locations->get($row->source_location_id);
                $destination = $locations->get($row->destination_location_id);
                $sourceSetting = $settings->get($source->setting_id);
                $destinationSetting = $settings->get($destination->setting_id);

                if (! $product || ! $sourceSetting || ! $destinationSetting) {
                    throw new RuntimeException('Data produk atau bisnis untuk alokasi tidak lengkap.');
                }

                $stock = $stocks->get($row->product_id . ':' . $row->source_location_id);
                if (! $stock) {
                    throw new RuntimeException("Stok {$product->product_name} tidak ditemukan di lokasi sumber {$source->name}.");
                }

                $quantity = (int) $row->quantity;
                $serialRows = [];

                // Frozen submitted tracking mode, never the product's current flag
                // (validatePlan already rejected any mismatch between the two).
                $serialized = $requestRevision->isSerializedProduct((int) $product->id);
                if ($serialized !== (bool) $product->serial_number_required || $serialized !== $row->serials->isNotEmpty()) {
                    throw new RuntimeException("Pengaturan nomor seri {$product->product_name} tidak sesuai dengan pengajuan.");
                }

                if ($serialized) {
                    $buckets = TransferV3InventoryPoster::EMPTY_BUCKETS;
                    foreach ($row->serials as $assignment) {
                        $serial = $liveSerials->get($assignment->product_serial_number_id);
                        $this->assertSerialDispatchable($serial, $product, $source, $isBroken);

                        $bucket = ($isBroken ? 'broken_' : '') . ($serial->tax_id ? 'tax' : 'non_tax');
                        $buckets[$bucket]++;
                        $serialRows[] = $serial;
                    }

                    if (count($serialRows) !== $quantity) {
                        throw new RuntimeException("Jumlah nomor seri {$product->product_name} tidak sesuai dengan alokasi.");
                    }
                } else {
                    $buckets = TransferV3InventoryPoster::allocateNonTaxFirst($stock, $quantity, $isBroken);
                }

                // Immutable per-allocation route policy: cross-business depends
                // only on business identity; classification follows the
                // established PKP rules; no return obligation in version 3.
                $crossBusiness = (int) $sourceSetting->id !== (int) $destinationSetting->id;
                $classification = $this->policyResolver->resolveClassification(! $crossBusiness, (bool) $sourceSetting->is_pkp, (bool) $destinationSetting->is_pkp)['classification'];
                $tax = ['tax_id' => null, 'tax_name' => null, 'tax_rate' => null, 'provenance' => null];
                if ($classification === TransferRoutePolicy::CLASSIFICATION_TAX) {
                    $tax = $taxSnapshot ??= $this->policyResolver->resolveDestinationTax();
                }

                $snapshot = TransferV3InventoryPoster::apply($product, $stock, $buckets, -1);
                $trx = TransferV3InventoryPoster::recordTransaction(
                    (int) $product->id,
                    (int) $sourceSetting->id,
                    (int) $source->id,
                    -$quantity,
                    $snapshot,
                    $actor->id,
                    sprintf('Pengiriman transfer %s (Transfer #%d) ke %s - %s', $locked->document_number, $locked->id, $destinationSetting->company_name ?? '-', $destination->name)
                );

                $allocation = TransferMovementAllocation::create([
                    'transfer_id'                     => $locked->id,
                    'transfer_movement_id'            => $movement->id,
                    'transfer_movement_line_id'       => $lines[(int) $product->id]->id,
                    'approval_allocation_id'          => $row->id,
                    'kind'                            => TransferMovementAllocation::KIND_DISPATCH,
                    'product_id'                      => $product->id,
                    'source_location_id'              => $source->id,
                    'destination_location_id'         => $destination->id,
                    'source_setting_id'               => $sourceSetting->id,
                    'destination_setting_id'          => $destinationSetting->id,
                    'cross_business'                  => $crossBusiness,
                    'stock_condition'                 => $locked->stock_condition,
                    'quantity'                        => $quantity,
                    'applied_quantity_non_tax'        => $buckets['non_tax'],
                    'applied_quantity_tax'            => $buckets['tax'],
                    'applied_quantity_broken_non_tax' => $buckets['broken_non_tax'],
                    'applied_quantity_broken_tax'     => $buckets['broken_tax'],
                    'destination_classification'      => $classification,
                    'tax_id'                          => $tax['tax_id'],
                    'tax_name'                        => $tax['tax_name'],
                    'tax_rate'                        => $tax['tax_rate'],
                    'tax_resolver_provenance'         => $tax['provenance'],
                    'stock_snapshot_before'           => $snapshot['previous_stock'],
                    'stock_snapshot_after'            => $snapshot['current_stock'],
                    'inventory_transaction_id'        => $trx->id,
                    'actor_id'                        => $actor->id,
                ]);

                foreach ($serialRows as $serial) {
                    $movementSerial = TransferMovementSerial::create([
                        'transfer_movement_id'            => $movement->id,
                        'transfer_movement_line_id'       => $lines[(int) $product->id]->id,
                        'transfer_movement_allocation_id' => $allocation->id,
                        'product_id'                      => $product->id,
                        'product_serial_number_id'        => $serial->id,
                        'serial_number'                   => $serial->serial_number,
                        'stock_condition'                 => $locked->stock_condition,
                        'tax_id'                          => $serial->tax_id,
                        'transit_custody_status'          => TransferMovementSerial::CUSTODY_IN_TRANSIT,
                        'custody_started_at'              => $now,
                        'origin_location_id'              => $source->id,
                        'destination_location_id'         => $destination->id,
                    ]);

                    // Exclusive claim enforced by the unique serial index; the
                    // live location stays at the source until receipt.
                    TransferActiveSerialClaim::create([
                        'product_serial_number_id'    => $serial->id,
                        'transfer_movement_id'        => $movement->id,
                        'transfer_movement_serial_id' => $movementSerial->id,
                    ]);

                    SerialNumberHistoryService::record(
                        $serial->id,
                        SerialNumberHistory::EVENT_LOCATION_TRANSFER,
                        $source->id,
                        $movement,
                        sprintf('Dikirim (transit) transfer %s dari %s ke %s', $locked->document_number, $source->name, $destination->name),
                        $actor->id
                    );
                }

                foreach ($buckets as $bucket => $value) {
                    $lineBuckets[(int) $product->id][$bucket] = ($lineBuckets[(int) $product->id][$bucket] ?? 0) + $value;
                }
            }

            foreach ($lines as $productId => $line) {
                $totals = array_merge(TransferV3InventoryPoster::EMPTY_BUCKETS, $lineBuckets[$productId] ?? []);
                $line->update([
                    'applied_quantity_non_tax'        => $totals['non_tax'],
                    'applied_quantity_tax'            => $totals['tax'],
                    'applied_quantity_broken_non_tax' => $totals['broken_non_tax'],
                    'applied_quantity_broken_tax'     => $totals['broken_tax'],
                ]);
            }

            TransferMovementHistory::create([
                'transfer_movement_id' => $movement->id,
                'revision'             => 1,
                'action'               => TransferMovementHistory::ACTION_APPROVED,
                'from_status'          => null,
                'to_status'            => TransferMovement::STATUS_APPROVED,
                'actor_id'             => $actor->id,
                'reason'               => 'Pengiriman dibuat dari persetujuan transfer versi 3.',
                'idempotency_key'      => $operationKey,
            ]);

            $locked->update([
                'status'        => Transfer::STATUS_DISPATCHED,
                'revision'      => $locked->revision + 1,
                'approved_by'   => $actor->id,
                'approved_at'   => $now,
                'dispatched_by' => $actor->id,
                'dispatched_at' => $now,
            ]);

            $evidence = [
                'request_revision_id'    => $reviewedRequestRevisionId,
                'configuration_revision' => $reviewedConfigurationRevision,
                'movement_id'            => $movement->id,
            ];

            TransferV3EventRecorder::record($locked, TransferActionHistory::ACTION_APPROVED, Transfer::STATUS_PENDING, Transfer::STATUS_DISPATCHED, $actor->id, $activeSettingId, 'Disetujui', $evidence);
            TransferV3EventRecorder::record($locked, TransferActionHistory::ACTION_DISPATCHED, Transfer::STATUS_PENDING, Transfer::STATUS_DISPATCHED, $actor->id, $activeSettingId, 'Dikirim otomatis saat persetujuan', $evidence, $operationKey);

            return $locked;
        });
    }

    private function assertSerialDispatchable(?ProductSerialNumber $serial, Product $product, Location $source, bool $isBroken): void
    {
        if (! $serial || (int) $serial->product_id !== (int) $product->id) {
            throw new RuntimeException("Nomor seri untuk {$product->product_name} tidak valid.");
        }

        if ((int) $serial->location_id !== (int) $source->id) {
            throw new RuntimeException("Lokasi nomor seri {$serial->serial_number} telah berubah. Tinjau ulang alokasi.");
        }

        if (TransferActiveSerialClaim::where('product_serial_number_id', $serial->id)->lockForUpdate()->exists()) {
            throw new RuntimeException("Nomor seri {$serial->serial_number} sedang dalam transit transfer lain.");
        }

        if (! $this->allocations->serialEligible($serial, $isBroken)) {
            throw new RuntimeException("Nomor seri {$serial->serial_number} tidak lagi tersedia untuk kondisi transfer ini.");
        }
    }
}
