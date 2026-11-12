<?php

namespace Modules\Consignment\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Modules\Consignment\Entities\ConsignmentActiveSerialClaim;
use Modules\Consignment\Entities\ConsignmentBuyout;
use Modules\Consignment\Entities\ConsignmentBuyoutAuditEvent;
use Modules\Consignment\Entities\ConsignmentBuyoutLine;
use Modules\Consignment\Entities\ConsignmentBuyoutReservation;
use Modules\Consignment\Entities\ConsignmentBuyoutSerial;
use Modules\Consignment\Entities\ConsignmentInventoryCoordination;
use Modules\Consignment\Entities\ConsignmentReceiving;
use Modules\Consignment\Entities\ConsignmentReceivingDetail;
use Modules\Consignment\Entities\ConsignmentSoldSource;
use Modules\Consignment\Entities\ConsignmentSoldSourceSerial;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Setting\Entities\Location;

/**
 * Domain-level buyout lifecycle: DRAFT -> WAITING_APPROVAL -> APPROVED -> (FINALIZED -> REVERSED).
 * Drafts and waiting documents reserve nothing. Approval creates every reservation together; cancel/reject
 * release them. Finalization/reversal are intentionally NOT implemented here (group 6/8) and no production
 * route exposes these actions until stock consumers honour the reservations.
 *
 * Line input: line_type, consignment_receiving_detail_id, consignment_sold_source_id (sold lines),
 * destination_location_id, base_quantity, serial_ids (serialized products), buckets {TAXED,NON_TAXED} (unsold non-serialized).
 */
class ConsignmentBuyoutLifecycleService
{
    public const BUCKETS = ['TAXED', 'NON_TAXED'];

    public function __construct(protected ?ConsignmentBuyoutCapacityService $capacity = null)
    {
        $this->capacity ??= new ConsignmentBuyoutCapacityService();
    }

    /* ----------------------------------------------------------------- authorization */

    /** Required abilities per action; unsold acquisition authority is composed in when unsold lines exist. */
    protected function abilities(string $action, bool $hasUnsold): array
    {
        $base = match ($action) {
            'create' => ['consignments.buyouts.create'],
            'edit' => ['consignments.buyouts.edit'],
            'submit' => ['consignments.buyouts.submit'],
            'approve' => ['consignments.buyouts.approve', 'consignments.allocations.approve'],
            'reject' => ['consignments.buyouts.reject'],
            'cancel' => ['consignments.buyouts.cancel'],
            default => throw new InvalidArgumentException("Unknown buyout action {$action}."),
        };
        if ($hasUnsold && $action !== 'cancel' && $action !== 'reject') {
            $base[] = 'consignments.buyouts.acquire_unsold';
        }

        return $base;
    }

    /** Remote custody/owner businesses of unsold lines need delegated acquisition authority (no global foreign access). */
    public function authorizeCustody(User $actor, ConsignmentBuyout $buyout): void
    {
        $owners = $buyout->lines()->where('line_type', ConsignmentBuyoutLine::TYPE_UNSOLD_BUYOUT)->whereNotNull('inventory_owner_setting_id')
            ->pluck('inventory_owner_setting_id')->map(fn ($i) => (int) $i)->unique()->values()->all();
        if (array_diff($owners, [(int) $buyout->setting_id]) !== []) {
            app(ConsignmentProvenanceAuthorizer::class)->authorizeAcquisition($actor, (int) $buyout->setting_id, $owners);
        }
    }

    protected function authorize(User $actor, string $action, int $settingId, bool $hasUnsold): void
    {
        if (! $actor->hasRole('Super Admin') && ! $actor->settings()->where('settings.id', $settingId)->exists()) {
            throw new AuthorizationException('No access to this business.');
        }
        foreach ($this->abilities($action, $hasUnsold) as $ability) {
            if (Gate::forUser($actor)->denies($ability)) {
                throw new AuthorizationException("Missing permission {$ability}.");
            }
        }
    }

    /* ----------------------------------------------------------------- draft / revise */

    public function createDraft(int $settingId, int $supplierId, string $date, array $lines, User $actor, array $meta = []): ConsignmentBuyout
    {
        $this->authorize($actor, 'create', $settingId, $this->hasUnsold($lines));

        return DB::transaction(function () use ($settingId, $supplierId, $date, $lines, $actor, $meta) {
            \Modules\Setting\Entities\Setting::where('id', $settingId)->lockForUpdate()->firstOrFail();
            $buyout = ConsignmentBuyout::create([
                'setting_id' => $settingId,
                'supplier_id' => $supplierId,
                'reference' => $this->nextReference($settingId),
                'status' => ConsignmentBuyout::STATUS_DRAFT,
                'revision' => 1,
                'date' => $date,
                'invoice_metadata' => $meta['invoice_metadata'] ?? null,
                'pricing_intent' => $meta['pricing_intent'] ?? null,
                'notes' => $meta['notes'] ?? null,
                'created_by' => $actor->id,
            ]);
            $this->replaceLines($buyout, $lines);
            $this->authorizeCustody($actor, $buyout);
            $this->audit($buyout, 'DRAFT_CREATED', null, $actor, 'Draft created.');

            return $buyout->fresh();
        });
    }

    public function reviseDraft(ConsignmentBuyout $buyout, array $lines, User $actor, ?int $expectedRevision = null, array $meta = []): ConsignmentBuyout
    {
        $this->authorize($actor, 'edit', $buyout->setting_id, $this->hasUnsold($lines));

        return DB::transaction(function () use ($buyout, $lines, $actor, $expectedRevision, $meta) {
            $locked = $this->lockHeader($buyout, [ConsignmentBuyout::STATUS_DRAFT, ConsignmentBuyout::STATUS_REJECTED], $expectedRevision);
            $from = $locked->status;
            $this->replaceLines($locked, $lines);
            $this->authorizeCustody($actor, $locked);
            $locked->update([
                'status' => ConsignmentBuyout::STATUS_DRAFT,
                'revision' => $locked->revision + 1,
                'invoice_metadata' => $meta['invoice_metadata'] ?? $locked->invoice_metadata,
                'pricing_intent' => $meta['pricing_intent'] ?? $locked->pricing_intent,
                'notes' => $meta['notes'] ?? $locked->notes,
                'rejection_reason' => null,
            ]);
            $this->audit($locked, 'REVISED', $from, $actor, 'Revised.');
            $this->notify('resolve_revision', $locked);

            return $locked->fresh();
        });
    }

    /* ----------------------------------------------------------------- transitions */

    public function submit(ConsignmentBuyout $buyout, User $actor, ?int $expectedRevision = null): ConsignmentBuyout
    {
        $this->authorize($actor, 'submit', $buyout->setting_id, $this->buyoutHasUnsold($buyout));

        return DB::transaction(function () use ($buyout, $actor, $expectedRevision) {
            $locked = $this->lockHeader($buyout, [ConsignmentBuyout::STATUS_DRAFT], $expectedRevision);
            $this->assertHasLines($locked);
            $this->authorizeCustody($actor, $locked);
            $errors = $this->validateDocument($locked);
            if ($errors) {
                throw new InvalidArgumentException('Buyout cannot be submitted: ' . implode(' ', $errors));
            }
            $locked->update([
                'status' => ConsignmentBuyout::STATUS_WAITING_APPROVAL,
                'submitted_by' => $actor->id,
                'submitted_at' => now(),
            ]);
            $this->audit($locked, 'SUBMITTED', ConsignmentBuyout::STATUS_DRAFT, $actor, 'Submitted for approval; no resources reserved.');
            $this->notify('approval_needed', $locked);

            return $locked->fresh();
        });
    }

    public function reject(ConsignmentBuyout $buyout, string $reason, User $actor, ?int $expectedRevision = null): ConsignmentBuyout
    {
        $this->authorize($actor, 'reject', $buyout->setting_id, false);
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A rejection reason is required.');
        }

        return DB::transaction(function () use ($buyout, $reason, $actor, $expectedRevision) {
            $locked = $this->lockHeader($buyout, [ConsignmentBuyout::STATUS_WAITING_APPROVAL], $expectedRevision);
            $this->releaseAll($locked);
            $locked->update([
                'status' => ConsignmentBuyout::STATUS_REJECTED,
                'rejection_reason' => $reason,
                'rejected_by' => $actor->id,
                'rejected_at' => now(),
            ]);
            $this->audit($locked, 'REJECTED', ConsignmentBuyout::STATUS_WAITING_APPROVAL, $actor, $reason);
            $this->notify('revision_needed', $locked, $reason);

            return $locked->fresh();
        });
    }

    public function approve(ConsignmentBuyout $buyout, User $actor, ?int $expectedRevision = null): ConsignmentBuyout
    {
        $this->authorize($actor, 'approve', $buyout->setting_id, $this->buyoutHasUnsold($buyout));

        return ConsignmentLockPlanner::run(fn () => $this->pairsOf($buyout), function ($cover) use ($buyout, $actor, $expectedRevision) {
            $locked = $this->lockHeader($buyout, [ConsignmentBuyout::STATUS_WAITING_APPROVAL], $expectedRevision);
            $lines = $locked->lines()->with('serials')->orderBy('id')->get();
            $cover($this->pairsOfLines($locked, $lines));
            $this->authorizeCustody($actor, $locked);
            $this->lockResources($lines);

            $errors = $this->validateDocument($locked);
            if ($errors) {
                throw new InvalidArgumentException('Buyout cannot be approved: ' . implode(' ', $errors));
            }

            // All reservations are created together, inside this transaction: any failure leaves nothing behind.
            foreach ($lines as $line) {
                $this->reserveLine($locked, $line);
            }

            $locked->update([
                'status' => ConsignmentBuyout::STATUS_APPROVED,
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'approved_revision_hash' => $this->revisionHash($locked->fresh()),
            ]);
            // Pricing terms are accepted with the approval so later drift in receipt evidence is detectable at finalization.
            (new ConsignmentBuyoutPricingService())->accept($locked->fresh());

            $this->audit($locked, 'APPROVED', ConsignmentBuyout::STATUS_WAITING_APPROVAL, $actor, 'Approved; resources reserved.');
            $this->notify('resolve', $locked);

            return $locked->fresh();
        });
    }

    public function cancel(ConsignmentBuyout $buyout, string $reason, User $actor, ?int $expectedRevision = null): ConsignmentBuyout
    {
        $this->authorize($actor, 'cancel', $buyout->setting_id, false);
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A cancellation reason is required.');
        }

        return ConsignmentLockPlanner::run(fn () => $this->pairsOf($buyout), function ($cover) use ($buyout, $reason, $actor, $expectedRevision) {
            $locked = $this->lockHeader($buyout, [
                ConsignmentBuyout::STATUS_DRAFT, ConsignmentBuyout::STATUS_WAITING_APPROVAL, ConsignmentBuyout::STATUS_APPROVED, ConsignmentBuyout::STATUS_REJECTED,
            ], $expectedRevision);
            $cover($this->pairsOfLines($locked, $locked->lines()->get()));
            $from = $locked->status;
            $this->releaseAll($locked);
            $locked->update([
                'status' => ConsignmentBuyout::STATUS_CANCELLED,
                'cancellation_reason' => $reason,
                'cancelled_by' => $actor->id,
                'cancelled_at' => now(),
            ]);
            $this->audit($locked, 'CANCELLED', $from, $actor, $reason);
            $this->notify('resolve', $locked);

            return $locked->fresh();
        });
    }

    /**
     * Turn active reservations into consumed ones (called by the finalization poster in group 6, inside its transaction).
     */
    public function consumeReservations(ConsignmentBuyout $locked): void
    {
        if ($locked->status !== ConsignmentBuyout::STATUS_APPROVED) {
            throw new InvalidArgumentException('Only approved buyouts can consume reservations.');
        }
        $locked->reservations()->where('status', ConsignmentBuyoutReservation::STATUS_ACTIVE)
            ->update(['status' => ConsignmentBuyoutReservation::STATUS_CONSUMED, 'consumed_at' => now()]);
    }

    /* ----------------------------------------------------------------- hash */

    /** Canonical hash over identities, quantities, destinations, snapshots and commercial intent. */
    public function revisionHash(ConsignmentBuyout $buyout): string
    {
        $lines = $buyout->lines()->with('serials')->orderBy('id')->get()->map(function (ConsignmentBuyoutLine $l) use ($buyout) {
            $snap = $l->original_snapshot ?? [];
            ksort($snap);

            return [
                'type' => $l->line_type,
                'crd' => (int) $l->consignment_receiving_detail_id,
                'source' => $l->consignment_sold_source_id ? (int) $l->consignment_sold_source_id : null,
                'from' => (int) $l->source_location_id,
                'to' => $l->destination_location_id ? (int) $l->destination_location_id : null,
                'qty' => number_format((float) $l->base_quantity, 3, '.', ''),
                'serials' => $l->serials->pluck('product_serial_number_id')->map(fn ($x) => (int) $x)->sort()->values()->all(),
                'snapshot' => $snap,
            ] + ($l->inventory_owner_setting_id && (int) $l->inventory_owner_setting_id !== (int) $buyout->setting_id
                ? ['owner' => (int) $l->inventory_owner_setting_id] : []);
        })->sortBy(fn ($r) => json_encode([$r['type'], $r['crd'], $r['source'], $r['from']]))->values()->all();

        $payload = [
            'setting' => (int) $buyout->setting_id,
            'supplier' => (int) $buyout->supplier_id,
            'date' => $buyout->date?->toDateString(),
            'invoice' => $this->ksortDeep($buyout->invoice_metadata ?? []),
            'pricing' => $this->ksortDeep($buyout->pricing_intent ?? []),
            'lines' => $lines,
        ];

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES));
    }

    /** BaseModel upper-cases string attributes, so hashes are compared case-insensitively. */
    public function approvedHashIsCurrent(ConsignmentBuyout $buyout): bool
    {
        return $buyout->approved_revision_hash !== null
            && hash_equals(strtolower($buyout->approved_revision_hash), $this->revisionHash($buyout->fresh()));
    }

    private function ksortDeep(array $a): array
    {
        foreach ($a as $k => $v) {
            if (is_array($v)) {
                $a[$k] = $this->ksortDeep($v);
            }
        }
        if (array_keys($a) !== range(0, count($a) - 1)) {
            ksort($a);
        }

        return $a;
    }

    /* ----------------------------------------------------------------- validation */

    /**
     * Full document validation under the caller's locks. Excludes the document's own reservations.
     *
     * @return string[]
     */
    public function validateDocument(ConsignmentBuyout $buyout): array
    {
        $errors = [];
        $lines = $buyout->lines()->with(['serials', 'soldSource', 'receivingDetail.receiving.receival'])->orderBy('id')->get();
        $slices = [];
        $bucketNeeds = [];

        foreach ($lines as $line) {
            $detail = $line->receivingDetail;
            $ref = "line #{$line->id}";
            $receiving = $detail?->receiving;
            if (! $receiving || $receiving->status !== ConsignmentReceiving::STATUS_APPROVED) {
                $errors[] = "{$ref}: receipt is not approved.";
                continue;
            }
            if ((int) $receiving->setting_id !== (int) $buyout->setting_id || (int) $receiving->receival->supplier_id !== (int) $buyout->supplier_id) {
                $errors[] = "{$ref}: receipt belongs to another supplier or business.";
                continue;
            }

            // Unsold goods become owned by their holding business: the destination is a standard location of THAT business.
            $ownerSetting = $line->inventoryOwnerSettingId();
            $dest = $line->destination_location_id ? Location::find($line->destination_location_id) : null;
            if (! $dest || (int) $dest->setting_id !== $ownerSetting || $dest->is_consignment || ! $dest->is_active) {
                $errors[] = "{$ref}: destination must be an active standard location of the business holding the goods.";
            }
            if ($line->line_type === ConsignmentBuyoutLine::TYPE_UNSOLD_BUYOUT) {
                $remoteSource = (int) $receiving->location_id !== (int) $line->source_location_id;
                $src = Location::find($line->source_location_id);
                if ($remoteSource && (! $src || ! $src->is_consignment || (int) $src->setting_id !== $ownerSetting)) {
                    $errors[] = "{$ref}: source must be a consignment location of the holding business.";
                } elseif ($remoteSource || $this->capacity->hasCustodyEvents((int) $detail->id)) {
                    $errors = array_merge($errors, $this->capacity->validateLocationCustody($line, $ref, $buyout->id));
                }
            }

            $product = Product::find($line->product_id);
            $serialized = (bool) ($product->serial_number_required ?? false);
            $qty = ConsignmentBuyoutCapacityService::milli($line->base_quantity);

            if ($serialized) {
                $errors = array_merge($errors, $this->validateSerials($buyout, $line, $qty));
            } elseif ($line->serials->isNotEmpty()) {
                $errors[] = "{$ref}: serials supplied for a non-serialized product.";
            }

            if ($line->line_type === ConsignmentBuyoutLine::TYPE_UNSOLD_BUYOUT && ! $serialized) {
                $buckets = $this->bucketsOf($line);
                if (! $buckets || array_sum(array_map([ConsignmentBuyoutCapacityService::class, 'milli'], $buckets)) !== $qty) {
                    $errors[] = "{$ref}: unsold quantity must be fully split into TAXED / NON_TAXED buckets.";
                } else {
                    $k = $line->product_id . ':' . $line->source_location_id;
                    foreach ($buckets as $name => $q) {
                        $bucketNeeds[$k][$name] = ($bucketNeeds[$k][$name] ?? 0) + ConsignmentBuyoutCapacityService::milli($q);
                    }
                }
            }

            $slices[] = ['type' => $line->line_type, 'receiving_detail_id' => $line->consignment_receiving_detail_id,
                'sold_source_id' => $line->consignment_sold_source_id, 'quantity' => $line->base_quantity];

            if ($line->line_type === ConsignmentBuyoutLine::TYPE_SOLD_UNBILLED) {
                $source = ConsignmentSoldSource::find($line->consignment_sold_source_id);
                $bound = $source && (int) $source->setting_id !== (int) $buyout->setting_id
                    && app(ConsignmentReceiptAllocationService::class)->verifiedBinding((int) $source->id, (int) $line->consignment_receiving_detail_id) !== null;
                if (! $source || ((int) $source->setting_id !== (int) $buyout->setting_id && ! $bound)) {
                    $errors[] = "{$ref}: sold source not available.";
                } else {
                    $errors = array_merge($errors, $this->validateSoldEvidence($line, $source, $ref));
                }
            }
        }

        $errors = array_merge($errors, $this->capacity->validateSlices($slices, $buyout->id));
        $errors = array_merge($errors, $this->capacity->validateSoldPairs(array_values(array_filter(array_map(
            fn ($x) => $x['type'] === ConsignmentBuyoutLine::TYPE_SOLD_UNBILLED ? $x + ['receiving_detail_id' => $x['receiving_detail_id']] : null, $slices))), $buyout->id));

        foreach ($bucketNeeds as $key => $needs) {
            [$productId, $locationId] = array_map('intval', explode(':', $key));
            $poolSetting = (int) (Location::whereKey($locationId)->value('setting_id') ?? $buyout->setting_id);
            $errors = array_merge($errors, $this->capacity->validateBuckets($poolSetting, $productId, $locationId, [
                'taxed' => ConsignmentBuyoutCapacityService::fromMilli($needs['TAXED'] ?? 0),
                'non_taxed' => ConsignmentBuyoutCapacityService::fromMilli($needs['NON_TAXED'] ?? 0),
            ], $buyout->id));
        }

        return array_values(array_unique($errors));
    }

    /** Sold evidence must still be the evidence the line was drafted against, and the live dispatch must still hash to it. */
    protected function validateSoldEvidence(ConsignmentBuyoutLine $line, ConsignmentSoldSource $source, string $ref): array
    {
        $errors = [];
        $captured = $line->original_snapshot['sold_source'] ?? null;
        if (! empty($captured['hash']) && ! empty($source->source_hash) && strcasecmp($captured['hash'], $source->source_hash) !== 0) {
            $errors[] = "{$ref}: sold source evidence changed since the line was drafted.";
        }
        $live = app(ConsignmentBillingConfirmationLifecycleService::class)->computeCanonicalLiveHash($source);
        if (! empty($source->source_hash) && ! empty($live) && strcasecmp($live, $source->source_hash) !== 0) {
            $errors[] = "{$ref}: live dispatch evidence no longer matches the sold source (dispatch altered).";
        }

        return $errors;
    }

    protected function validateSerials(ConsignmentBuyout $buyout, ConsignmentBuyoutLine $line, int $qtyMilli): array
    {
        $ref = "line #{$line->id}";
        $errors = [];
        if ($qtyMilli % 1000 !== 0) {
            return ["{$ref}: serialized quantity must be a whole number."];
        }
        $ids = $line->serials->pluck('product_serial_number_id')->map(fn ($x) => (int) $x);
        if ($ids->count() !== intdiv($qtyMilli, 1000)) {
            $errors[] = "{$ref}: exact serial selection ({$ids->count()}) must equal the quantity.";
        }
        if ($ids->unique()->count() !== $ids->count()) {
            $errors[] = "{$ref}: duplicate serial selected.";
        }

        $serials = ProductSerialNumber::whereIn('id', $ids->all())->get()->keyBy('id');
        $texts = [];
        $selectable = $line->line_type === ConsignmentBuyoutLine::TYPE_UNSOLD_BUYOUT
            ? $this->capacity->selectableSerialIds($line->product_id, $line->source_location_id, $line->consignment_receiving_detail_id)
            : null;
        $soldSerialIds = $line->line_type === ConsignmentBuyoutLine::TYPE_SOLD_UNBILLED
            ? ConsignmentSoldSourceSerial::where('consignment_sold_source_id', $line->consignment_sold_source_id)->pluck('product_serial_number_id')->map(fn ($x) => (int) $x)->all()
            : [];
        $returned = $line->line_type === ConsignmentBuyoutLine::TYPE_SOLD_UNBILLED && $line->soldSource
            ? (new ConsignmentReturnEligibilityService())->getEffectiveReturnedSerialIds($line->soldSource->dispatch_detail_id) : [];

        foreach ($ids as $id) {
            $serial = $serials->get($id);
            if (! $serial || (int) $serial->product_id !== (int) $line->product_id) {
                $errors[] = "{$ref}: serial #{$id} does not belong to the product.";
                continue;
            }
            if ((int) $serial->consignment_receiving_detail_id !== (int) $line->consignment_receiving_detail_id) {
                $errors[] = "{$ref}: serial {$serial->serial_number} belongs to another receipt or supplier.";
            }
            $texts[strtoupper($serial->serial_number)] = ($texts[strtoupper($serial->serial_number)] ?? 0) + 1;

            if ($line->line_type === ConsignmentBuyoutLine::TYPE_UNSOLD_BUYOUT) {
                if (! in_array($id, $selectable, true)) {
                    // Claimed by *this* buyout is fine on re-validation at approval time.
                    $own = ConsignmentActiveSerialClaim::where('product_serial_number_id', $id)->where('consignment_buyout_id', $buyout->id)->exists();
                    if (! $own) {
                        $errors[] = "{$ref}: serial {$serial->serial_number} is not available (sold, broken, held, claimed or at another location).";
                    }
                }
            } else {
                if (! in_array($id, $soldSerialIds, true) || in_array($id, $returned, true)) {
                    $errors[] = "{$ref}: serial {$serial->serial_number} is not an effective sold serial of the source.";
                }
                $foreign = ConsignmentActiveSerialClaim::where('product_serial_number_id', $id)
                    ->where(fn ($q) => $q->whereNull('consignment_buyout_id')->orWhere('consignment_buyout_id', '!=', $buyout->id))->exists();
                if ($foreign) {
                    $errors[] = "{$ref}: serial {$serial->serial_number} is already claimed by another document.";
                }
            }
        }
        foreach ($texts as $text => $n) {
            if ($n > 1) {
                $errors[] = "{$ref}: duplicate serial number {$text}.";
            }
        }

        return $errors;
    }

    /* ----------------------------------------------------------------- reservations */

    protected function reserveLine(ConsignmentBuyout $buyout, ConsignmentBuyoutLine $line): void
    {
        $base = ['consignment_buyout_id' => $buyout->id, 'consignment_buyout_line_id' => $line->id,
            'revision' => $buyout->revision, 'status' => ConsignmentBuyoutReservation::STATUS_ACTIVE];

        ConsignmentBuyoutReservation::create($base + ['resource_type' => 'RECEIPT', 'base_quantity' => $line->base_quantity,
            'consignment_receiving_detail_id' => $line->consignment_receiving_detail_id, 'product_id' => $line->product_id]);

        if ($line->line_type === ConsignmentBuyoutLine::TYPE_SOLD_UNBILLED) {
            ConsignmentBuyoutReservation::create($base + ['resource_type' => 'SOLD_SOURCE', 'base_quantity' => $line->base_quantity,
                'consignment_sold_source_id' => $line->consignment_sold_source_id, 'product_id' => $line->product_id]);
        } elseif ($line->serials->isEmpty()) {
            foreach ($this->bucketsOf($line) as $name => $q) {
                if (ConsignmentBuyoutCapacityService::milli($q) <= 0) {
                    continue;
                }
                ConsignmentBuyoutReservation::create($base + ['resource_type' => 'PHYSICAL_BUCKET', 'base_quantity' => $q,
                    'product_id' => $line->product_id, 'location_id' => $line->source_location_id, 'tax_bucket' => $name]);
            }
        }

        foreach ($line->serials as $bs) {
            ConsignmentBuyoutReservation::create($base + ['resource_type' => 'SERIAL', 'base_quantity' => 1,
                'product_serial_number_id' => $bs->product_serial_number_id, 'product_id' => $line->product_id,
                'location_id' => $line->source_location_id]);
            ConsignmentActiveSerialClaim::create([
                'owner_type' => ConsignmentActiveSerialClaim::OWNER_BUYOUT,
                'product_serial_number_id' => $bs->product_serial_number_id,
                'consignment_buyout_id' => $buyout->id,
                'consignment_buyout_serial_id' => $bs->id,
            ]);
        }
    }

    protected function releaseAll(ConsignmentBuyout $buyout): void
    {
        $buyout->reservations()->where('status', ConsignmentBuyoutReservation::STATUS_ACTIVE)
            ->update(['status' => ConsignmentBuyoutReservation::STATUS_RELEASED, 'released_at' => now()]);
        ConsignmentActiveSerialClaim::where('consignment_buyout_id', $buyout->id)->delete();
    }

    /* ----------------------------------------------------------------- locking & plumbing */

    /** @return array<int, array{0:int,1:int}> read-only discovery of the (business, product) set */
    public function pairsOf(ConsignmentBuyout $buyout): array
    {
        return $this->pairsOfLines($buyout, $buyout->lines()->get());
    }

    /** Origin (billing) business plus every holding business of a remote unsold slice, per product. */
    public function pairsOfLines(ConsignmentBuyout $buyout, $lines): array
    {
        $pairs = [];
        foreach ($lines as $l) {
            $pairs[(int) $buyout->setting_id . ':' . (int) $l->product_id] = [(int) $buyout->setting_id, (int) $l->product_id];
            if ($l->inventory_owner_setting_id) {
                $pairs[(int) $l->inventory_owner_setting_id . ':' . (int) $l->product_id] = [(int) $l->inventory_owner_setting_id, (int) $l->product_id];
            }
        }

        return array_values($pairs);
    }

    protected function lockCoordination(ConsignmentBuyout $buyout): void
    {
        ConsignmentInventoryCoordination::lockMany($this->pairsOf($buyout));
    }

    protected function lockResources($lines): void
    {
        $crd = $lines->pluck('consignment_receiving_detail_id')->unique()->sort()->values()->all();
        $src = $lines->pluck('consignment_sold_source_id')->filter()->unique()->sort()->values()->all();
        $loc = $lines->flatMap(fn ($l) => [$l->source_location_id, $l->destination_location_id])->filter()->unique()->sort()->values()->all();
        $serials = $lines->flatMap(fn ($l) => $l->serials->pluck('product_serial_number_id'))->unique()->sort()->values()->all();

        if ($loc) {
            Location::whereIn('id', $loc)->orderBy('id')->lockForUpdate()->get();
        }
        if ($crd) {
            ConsignmentReceivingDetail::whereIn('id', $crd)->orderBy('id')->lockForUpdate()->get();
        }
        if ($src) {
            ConsignmentSoldSource::whereIn('id', $src)->orderBy('id')->lockForUpdate()->get();
        }
        if ($serials) {
            ProductSerialNumber::whereIn('id', $serials)->orderBy('id')->lockForUpdate()->get();
        }
    }

    protected function lockHeader(ConsignmentBuyout $buyout, array $allowed, ?int $expectedRevision): ConsignmentBuyout
    {
        $locked = ConsignmentBuyout::whereKey($buyout->id)->lockForUpdate()->firstOrFail();
        if (! in_array($locked->status, $allowed, true)) {
            throw new InvalidArgumentException("Invalid transition from {$locked->status}.");
        }
        if ($expectedRevision !== null && (int) $locked->revision !== $expectedRevision) {
            throw new InvalidArgumentException("Buyout was changed by someone else (revision {$locked->revision}, expected {$expectedRevision}).");
        }

        return $locked;
    }

    protected function assertHasLines(ConsignmentBuyout $buyout): void
    {
        if (! $buyout->lines()->exists()) {
            throw new InvalidArgumentException('A buyout needs at least one line.');
        }
    }

    protected function replaceLines(ConsignmentBuyout $buyout, array $lines): void
    {
        foreach ($buyout->serials as $s) {
            $s->delete();
        }
        foreach ($buyout->lines as $l) {
            $l->delete();
        }
        foreach ($lines as $data) {
            $detail = ConsignmentReceivingDetail::with('receiving')->find($data['consignment_receiving_detail_id'] ?? null);
            if (! $detail) {
                throw new InvalidArgumentException('Receipt detail not found.');
            }
            $snapshot = ['receipt' => ['unit_cost' => (string) $detail->unit_cost, 'unit_dpp' => (string) $detail->unit_dpp,
                'tax_id' => $detail->tax_id, 'tax_rate' => (string) $detail->tax_rate]];
            if (! empty($data['buckets'])) {
                $snapshot['buckets'] = array_intersect_key(array_map('strval', $data['buckets']), array_flip(self::BUCKETS));
            }
            if (! empty($data['consignment_sold_source_id'])) {
                $src = ConsignmentSoldSource::find($data['consignment_sold_source_id']);
                $snapshot['sold_source'] = $src ? ['version' => ConsignmentSoldSourceDiscoveryService::resolveSnapshotVersion($src->source_snapshot),
                    'hash' => $src->source_hash] : null;
            }
            // Unsold stock may be bought where it actually is (receipt-backed custody), owned by that holding business.
            $sourceLocationId = (int) $detail->receiving->location_id;
            $owner = null;
            // Acquired remote SOLD units belong to the actual selling business: future customer returns route there.
            if (($data['line_type'] ?? null) === ConsignmentBuyoutLine::TYPE_SOLD_UNBILLED && ! empty($data['consignment_sold_source_id'])) {
                $sellerSetting = (int) ConsignmentSoldSource::whereKey($data['consignment_sold_source_id'])->value('setting_id');
                if ($sellerSetting && $sellerSetting !== (int) $buyout->setting_id) {
                    $owner = $sellerSetting;
                }
            }
            if (($data['line_type'] ?? null) === ConsignmentBuyoutLine::TYPE_UNSOLD_BUYOUT && ! empty($data['source_location_id'])
                && (int) $data['source_location_id'] !== $sourceLocationId) {
                $custody = Location::find((int) $data['source_location_id']);
                if (! $custody || ! $custody->is_consignment) {
                    throw new InvalidArgumentException('Unsold source must be a consignment location holding the receipt.');
                }
                $sourceLocationId = (int) $custody->id;
                if ((int) $custody->setting_id !== (int) $buyout->setting_id) {
                    $owner = (int) $custody->setting_id;
                }
            }
            $line = ConsignmentBuyoutLine::create([
                'consignment_buyout_id' => $buyout->id,
                'line_type' => $data['line_type'] ?? null,
                'consignment_receiving_detail_id' => $detail->id,
                'product_id' => $detail->product_id,
                'consignment_sold_source_id' => $data['consignment_sold_source_id'] ?? null,
                'inventory_owner_setting_id' => $owner,
                'source_location_id' => $sourceLocationId,
                'destination_location_id' => $data['destination_location_id'] ?? null,
                'base_quantity' => $data['base_quantity'],
                'original_snapshot' => $snapshot,
            ]);
            foreach (array_unique($data['serial_ids'] ?? []) as $sid) {
                ConsignmentBuyoutSerial::create(['consignment_buyout_id' => $buyout->id, 'consignment_buyout_line_id' => $line->id,
                    'product_serial_number_id' => $sid, 'consignment_receiving_detail_id' => $detail->id]);
            }
        }
    }

    protected function bucketsOf(ConsignmentBuyoutLine $line): array
    {
        return $line->original_snapshot['buckets'] ?? [];
    }

    protected function hasUnsold(array $lines): bool
    {
        foreach ($lines as $l) {
            if (($l['line_type'] ?? null) === ConsignmentBuyoutLine::TYPE_UNSOLD_BUYOUT) {
                return true;
            }
        }

        return false;
    }

    protected function buyoutHasUnsold(ConsignmentBuyout $b): bool
    {
        return $b->lines()->where('line_type', ConsignmentBuyoutLine::TYPE_UNSOLD_BUYOUT)->exists();
    }

    /**
     * Existing document-notification conventions (Bahasa Indonesia titles, resolve on completion). Notifications are
     * advisory: a failure to write one must never block a stock/financial transition.
     */
    protected function notify(string $what, ConsignmentBuyout $buyout, string $reason = ''): void
    {
        // Emitted only once the outermost transaction COMMITS: a rolled-back attempt, a lock-planner retry or a later
        // failure in the same transaction can therefore never produce (or duplicate) an external effect.
        if (DB::transactionLevel() > 0) {
            DB::afterCommit(fn () => $this->deliverNotification($what, $buyout, $reason));

            return;
        }
        $this->deliverNotification($what, $buyout, $reason);
    }

    protected function deliverNotification(string $what, ConsignmentBuyout $buyout, string $reason = ''): void
    {
        try {
            $svc = app(\App\Services\Notification\DocumentNotificationService::class);
            match ($what) {
                'approval_needed' => $svc->notifyApprovalNeeded($buyout, $buyout->reference, (int) $buyout->setting_id),
                'revision_needed' => (function () use ($svc, $buyout, $reason) {
                    $svc->resolveApproval($buyout);
                    $svc->notifyRevisionNeeded($buyout, $buyout->reference, (int) $buyout->setting_id, $reason);
                })(),
                'resolve_revision' => $svc->resolveRevision($buyout),
                default => (function () use ($svc, $buyout) {
                    $svc->resolveApproval($buyout);
                    $svc->resolveRevision($buyout);
                })(),
            };
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Buyout notification failed: ' . $e->getMessage(), ['buyout_id' => $buyout->id]);
        }
    }

    protected function audit(ConsignmentBuyout $buyout, string $action, ?string $from, User $actor, string $reason): void
    {
        $buyout->refresh();
        ConsignmentBuyoutAuditEvent::create([
            'consignment_buyout_id' => $buyout->id,
            'action' => $action,
            'from_status' => $from,
            'to_status' => $buyout->status,
            'revision' => $buyout->revision,
            'revision_hash' => $buyout->approved_revision_hash,
            'actor_id' => $actor->id,
            'reason' => $reason,
            'snapshot' => ['lines' => $buyout->lines()->get(['id', 'line_type', 'consignment_receiving_detail_id', 'consignment_sold_source_id', 'base_quantity'])->toArray()],
        ]);
    }

    protected function nextReference(int $settingId): string
    {
        $n = ConsignmentBuyout::where('setting_id', $settingId)->count() + 1;
        do {
            $ref = sprintf('BO-%d-%05d', $settingId, $n++);
        } while (ConsignmentBuyout::where('setting_id', $settingId)->where('reference', $ref)->exists());

        return $ref;
    }
}
