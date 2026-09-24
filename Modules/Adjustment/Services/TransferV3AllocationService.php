<?php

namespace Modules\Adjustment\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferActionHistory;
use Modules\Adjustment\Entities\TransferApprovalAllocation;
use Modules\Adjustment\Entities\TransferApprovalAllocationSerial;
use Modules\Adjustment\Entities\TransferRequestRevision;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Sale\Support\PendingDispatchSerialGuard;
use Modules\Setting\Entities\Location;
use RuntimeException;

/**
 * Approver allocation workspace for version 3 transfers.
 *
 * Serialized goods are grouped by product and the serial's live source
 * location (one destination per group); non-serialized goods take one or
 * more approver rows (source, destination, whole quantity). Progress saves
 * are manual, revision-checked and reserve nothing. Final validation always
 * rereads stored intent and live stock; client values carry no authority.
 */
class TransferV3AllocationService
{
    /**
     * Data for the approver-only workspace. Callers must already hold
     * approval authority; bucket diagnostics additionally need stock visibility.
     */
    public function workspace(Transfer $transfer, bool $withDiagnostics): array
    {
        $revision = $this->requireCurrentRequestRevision($transfer);
        $isBroken = $transfer->stock_condition === Transfer::CONDITION_BREAKAGE;
        $lines = $revision->linesByProduct();

        $products = Product::whereIn('id', array_keys($lines))->get()->keyBy('id');
        $plan = $this->currentPlan($transfer);
        $locations = $this->locationDirectory();

        $items = [];
        foreach ($lines as $productId => $line) {
            $product = $products->get($productId);
            $item = [
                'product_id'   => $productId,
                'product_name' => $product?->product_name ?? '-',
                'product_code' => $product?->product_code ?? '',
                'quantity'     => $line['quantity'],
                'serialized'   => $line['serials'] !== [],
            ];

            if ($item['serialized']) {
                $savedDestinations = $plan->where('product_id', $productId)
                    ->mapWithKeys(fn (TransferApprovalAllocation $row) => [(int) $row->source_location_id => $row->destination_location_id])
                    ->all();

                $item['groups'] = collect($this->liveSerialGroups($line['serials'], $isBroken))
                    ->map(function (array $group) use ($savedDestinations, $locations) {
                        $group['source_label'] = $group['source_location_id'] ? ($locations[$group['source_location_id']]['label'] ?? '-') : null;
                        $group['destination_location_id'] = $group['source_location_id'] ? ($savedDestinations[$group['source_location_id']] ?? null) : null;

                        return $group;
                    })->values()->all();
            } else {
                $item['sources'] = $this->sourceOptions($productId, $isBroken, $withDiagnostics, $locations);
                $item['rows'] = $plan->where('product_id', $productId)->sortBy('sort_order')->map(fn (TransferApprovalAllocation $row) => [
                    'source_location_id'      => (int) $row->source_location_id,
                    'destination_location_id' => $row->destination_location_id !== null ? (int) $row->destination_location_id : null,
                    'quantity'                => (int) $row->quantity,
                ])->values()->all();
            }

            $items[] = $item;
        }

        return [
            'request_revision_id'     => $revision->id,
            'request_revision_number' => $revision->revision_number,
            'configuration_revision'  => (int) $transfer->approval_configuration_revision,
            'items'                   => $items,
            'destinations'            => array_values($locations),
        ];
    }

    /**
     * Manual Simpan Progres: persists a new configuration revision holding
     * possibly incomplete choices. Rejects a stale request or configuration
     * revision instead of overwriting another approver's saved work. Makes
     * no reservation and no inventory change.
     *
     * @param array<string, mixed> $serialDestinations "productId:sourceLocationId" => destination id|null
     * @param array<int, array{product_id: int, source_location_id: int, destination_location_id: ?int, quantity: int}> $rows
     */
    public function saveProgress(
        Transfer $transfer,
        User $actor,
        int $activeSettingId,
        int $requestRevisionId,
        int $expectedConfigurationRevision,
        array $serialDestinations,
        array $rows
    ): Transfer {
        return DB::transaction(function () use ($transfer, $actor, $activeSettingId, $requestRevisionId, $expectedConfigurationRevision, $serialDestinations, $rows) {
            $locked = Transfer::whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            TransferV3Access::assertV3($locked);

            if ($locked->status !== Transfer::STATUS_PENDING) {
                throw new InvalidArgumentException('Alokasi hanya dapat disimpan untuk transfer yang menunggu persetujuan.');
            }

            if ((int) $locked->current_request_revision_id !== $requestRevisionId
                || (int) $locked->approval_configuration_revision !== $expectedConfigurationRevision) {
                throw new RuntimeException('Konfigurasi alokasi telah berubah sejak halaman dibuka. Muat ulang dan tinjau kembali sebelum menyimpan.');
            }

            $revision = $this->requireCurrentRequestRevision($locked);
            $lines = $revision->linesByProduct();
            $products = Product::whereIn('id', array_keys($lines))->get()->keyBy('id');
            $isBroken = $locked->stock_condition === Transfer::CONDITION_BREAKAGE;
            $knownLocationIds = Location::pluck('id')->map(fn ($id) => (int) $id)->all();

            $nextRevision = $expectedConfigurationRevision + 1;
            $sort = 0;
            $savedRows = 0;

            foreach ($lines as $productId => $line) {
                if ($line['serials'] === []) {
                    continue;
                }

                foreach ($this->liveSerialGroups($line['serials'], $isBroken) as $group) {
                    if ($group['source_location_id'] === null) {
                        continue; // unavailable serials cannot be routed
                    }

                    $destination = $this->nullableLocationId($serialDestinations["{$productId}:{$group['source_location_id']}"] ?? null, $knownLocationIds);

                    $allocation = TransferApprovalAllocation::create([
                        'transfer_id'             => $locked->id,
                        'request_revision_id'     => $revision->id,
                        'configuration_revision'  => $nextRevision,
                        'product_id'              => $productId,
                        'source_location_id'      => $group['source_location_id'],
                        'destination_location_id' => $destination,
                        'quantity'                => count($group['serials']),
                        'sort_order'              => $sort++,
                        'saved_by'                => $actor->id,
                    ]);

                    foreach ($group['serials'] as $serial) {
                        TransferApprovalAllocationSerial::create([
                            'transfer_approval_allocation_id' => $allocation->id,
                            'transfer_id'                     => $locked->id,
                            'request_revision_id'             => $revision->id,
                            'configuration_revision'          => $nextRevision,
                            'product_serial_number_id'        => $serial['id'],
                        ]);
                    }
                    $savedRows++;
                }
            }

            foreach ($rows as $row) {
                $productId = (int) ($row['product_id'] ?? 0);
                $product = $products->get($productId);

                if (! isset($lines[$productId]) || ! $product) {
                    throw new InvalidArgumentException('Alokasi berisi produk yang tidak ada pada dokumen.');
                }

                if ($lines[$productId]['serials'] !== []) {
                    throw new InvalidArgumentException('Produk bernomor seri dialokasikan berdasarkan lokasi nomor seri, bukan jumlah manual.');
                }

                $source = $this->nullableLocationId($row['source_location_id'] ?? null, $knownLocationIds);
                $quantity = $row['quantity'] ?? null;

                if ($source === null && ($quantity === null || $quantity === '')) {
                    continue; // empty placeholder row
                }

                if ($source === null) {
                    throw new InvalidArgumentException("Pilih lokasi sumber untuk setiap baris alokasi {$product->product_name}.");
                }

                if (! is_numeric($quantity) || (float) $quantity != (int) $quantity || (int) $quantity <= 0) {
                    throw new InvalidArgumentException("Jumlah alokasi {$product->product_name} harus bilangan bulat lebih dari 0.");
                }

                TransferApprovalAllocation::create([
                    'transfer_id'             => $locked->id,
                    'request_revision_id'     => $revision->id,
                    'configuration_revision'  => $nextRevision,
                    'product_id'              => $productId,
                    'source_location_id'      => $source,
                    'destination_location_id' => $this->nullableLocationId($row['destination_location_id'] ?? null, $knownLocationIds),
                    'quantity'                => (int) $quantity,
                    'sort_order'              => $sort++,
                    'saved_by'                => $actor->id,
                ]);
                $savedRows++;
            }

            $locked->update(['approval_configuration_revision' => $nextRevision]);

            TransferV3EventRecorder::record($locked, TransferActionHistory::ACTION_ALLOCATION_SAVED, Transfer::STATUS_PENDING, Transfer::STATUS_PENDING, $actor->id, $activeSettingId, 'Progres alokasi disimpan', [
                'request_revision_id'    => $revision->id,
                'configuration_revision' => $nextRevision,
                'allocation_count'       => $savedRows,
            ]);

            return $locked;
        });
    }

    /**
     * @return Collection<int, TransferApprovalAllocation>
     */
    public function currentPlan(Transfer $transfer): Collection
    {
        if (! $transfer->current_request_revision_id) {
            return collect();
        }

        return TransferApprovalAllocation::with('serials')
            ->where('transfer_id', $transfer->id)
            ->where('request_revision_id', $transfer->current_request_revision_id)
            ->where('configuration_revision', (int) $transfer->approval_configuration_revision)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * Complete-plan validation against live state. Returns allocation-
     * specific Bahasa Indonesia errors; an empty array means approvable.
     */
    public function validatePlan(Transfer $transfer): array
    {
        $errors = [];

        if (! $transfer->isV3() || $transfer->status !== Transfer::STATUS_PENDING) {
            return ['Transfer tidak dalam status menunggu persetujuan.'];
        }

        $revision = TransferRequestRevision::find($transfer->current_request_revision_id);
        if (! $revision) {
            return ['Dokumen belum memiliki revisi pengajuan yang berlaku.'];
        }

        $plan = $this->currentPlan($transfer);
        if ($plan->isEmpty()) {
            return ['Alokasi belum disimpan. Simpan alokasi terlebih dahulu.'];
        }

        $isBroken = $transfer->stock_condition === Transfer::CONDITION_BREAKAGE;
        $lines = $revision->linesByProduct();
        $products = Product::whereIn('id', array_keys($lines))->get()->keyBy('id');
        $locationIds = $plan->pluck('source_location_id')->merge($plan->pluck('destination_location_id'))->filter()->unique()->all();
        $locations = Location::whereIn('id', $locationIds)->get()->keyBy('id');
        $label = fn ($id) => $locations->get($id)?->name ?? '#' . $id;

        foreach ($plan->pluck('product_id')->unique() as $productId) {
            if (! isset($lines[(int) $productId])) {
                $errors[] = 'Alokasi berisi produk yang tidak ada pada dokumen.';
            }
        }

        $demand = [];

        foreach ($lines as $productId => $line) {
            $product = $products->get($productId);
            $name = $product?->product_name ?? '#' . $productId;
            $rows = $plan->where('product_id', $productId);

            foreach ($rows as $row) {
                $source = $locations->get($row->source_location_id);
                $destination = $row->destination_location_id ? $locations->get($row->destination_location_id) : null;

                if (! $destination) {
                    $errors[] = "{$name}: pilih lokasi tujuan untuk alokasi dari {$label($row->source_location_id)}.";
                    continue;
                }

                if ((int) $row->source_location_id === (int) $row->destination_location_id) {
                    $errors[] = "{$name}: lokasi tujuan tidak boleh sama dengan lokasi sumber ({$label($row->source_location_id)}).";
                }

                if (! $destination->is_active) {
                    $errors[] = "{$name}: lokasi tujuan {$destination->name} tidak aktif.";
                }

                if (! $source || ! $source->is_active) {
                    $errors[] = "{$name}: lokasi sumber {$label($row->source_location_id)} tidak aktif.";
                }

                if ($source && (bool) $source->is_consignment !== (bool) $destination->is_consignment) {
                    $errors[] = "{$name}: transfer antara lokasi standar dan lokasi konsinyasi tidak diperbolehkan.";
                }
            }

            $frozenSerialized = $line['serials'] !== [];
            if ((bool) $product?->serial_number_required !== $frozenSerialized) {
                $errors[] = "{$name}: pengaturan nomor seri produk berubah sejak diajukan. Tolak dokumen agar barang diajukan ulang.";
                continue;
            }

            if ($frozenSerialized) {
                $requested = collect($line['serials'])->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
                $assigned = $rows->flatMap(fn ($row) => $row->serials->pluck('product_serial_number_id'))->map(fn ($id) => (int) $id)->sort()->values()->all();

                if ($requested !== $assigned) {
                    $errors[] = "{$name}: pengelompokan nomor seri tidak sesuai dengan dokumen. Muat ulang alokasi dan tinjau kembali.";
                    continue;
                }

                $live = ProductSerialNumber::whereIn('id', $requested)->get()->keyBy('id');
                foreach ($rows as $row) {
                    foreach ($row->serials as $assignment) {
                        $serial = $live->get($assignment->product_serial_number_id);
                        if (! $serial || (int) $serial->location_id !== (int) $row->source_location_id) {
                            $errors[] = "{$name}: lokasi nomor seri " . ($serial?->serial_number ?? '-') . ' telah berubah. Muat ulang alokasi dan tinjau kembali.';
                        } elseif (! $this->serialEligible($serial, $isBroken)) {
                            $errors[] = "{$name}: nomor seri {$serial->serial_number} tidak lagi tersedia.";
                        }
                    }
                }
            } else {
                $total = (int) $rows->sum('quantity');
                if ($total !== (int) $line['quantity']) {
                    $errors[] = "{$name}: total alokasi ({$total}) harus sama dengan jumlah diminta ({$line['quantity']}).";
                }
            }

            foreach ($rows as $row) {
                $key = $productId . ':' . $row->source_location_id;
                $demand[$key] = ($demand[$key] ?? 0) + (int) $row->quantity;
            }
        }

        foreach ($demand as $key => $quantity) {
            [$productId, $sourceId] = array_map('intval', explode(':', $key));
            $stock = ProductStock::where('product_id', $productId)->where('location_id', $sourceId)->first();
            $available = TransferV3InventoryPoster::eligibleQuantity($stock, $isBroken);

            if ($available < $quantity) {
                $name = $products->get($productId)?->product_name ?? '#' . $productId;
                $errors[] = "{$name}: stok di {$label($sourceId)} tidak mencukupi untuk total alokasi {$quantity}.";
            }
        }

        return array_values(array_unique($errors));
    }

    /**
     * Server-derived summary of the current stored plan for the final
     * confirmation modal, bound to the revisions it was built from.
     */
    public function summary(Transfer $transfer): array
    {
        $revision = $this->requireCurrentRequestRevision($transfer);
        $plan = $this->currentPlan($transfer)->load(['product', 'sourceLocation.setting', 'destinationLocation.setting']);
        $serialIds = $plan->flatMap(fn ($row) => $row->serials->pluck('product_serial_number_id'))->all();
        $serialNumbers = ProductSerialNumber::whereIn('id', $serialIds)->pluck('serial_number', 'id');

        $rows = $plan->map(fn (TransferApprovalAllocation $row) => [
            'product_name'   => $row->product?->product_name ?? '-',
            'quantity'       => (int) $row->quantity,
            'source'         => $this->label($row->sourceLocation),
            'destination'    => $row->destinationLocation ? $this->label($row->destinationLocation) : null,
            'cross_business' => $row->sourceLocation && $row->destinationLocation
                && (int) $row->sourceLocation->setting_id !== (int) $row->destinationLocation->setting_id,
            'serials'        => $row->serials->map(fn ($s) => (string) ($serialNumbers[$s->product_serial_number_id] ?? '-'))->values()->all(),
        ])->values()->all();

        return [
            'request_revision_id'    => $revision->id,
            'configuration_revision' => (int) $transfer->approval_configuration_revision,
            'condition'              => $transfer->stock_condition,
            'rows'                   => $rows,
            'errors'                 => $this->validatePlan($transfer),
        ];
    }

    /**
     * Groups requested serials by live source location. Serials that are no
     * longer eligible (moved to custody, sold, wrong condition) are returned
     * in a group with a null source so they block approval visibly.
     *
     * @return array<int, array{source_location_id: ?int, serials: array<int, array{id: int, serial_number: string}>}>
     */
    public function liveSerialGroups(array $serials, bool $isBroken): array
    {
        $ids = array_map(fn ($serial) => (int) $serial['id'], $serials);
        $live = ProductSerialNumber::whereIn('id', $ids)->get()->keyBy('id');

        $groups = [];
        foreach ($serials as $requested) {
            $serial = $live->get((int) $requested['id']);
            $sourceId = $serial && $serial->location_id !== null && $this->serialEligible($serial, $isBroken)
                ? (int) $serial->location_id
                : null;

            $key = $sourceId ?? 'unavailable';
            $groups[$key] ??= ['source_location_id' => $sourceId, 'serials' => []];
            $groups[$key]['serials'][] = ['id' => (int) $requested['id'], 'serial_number' => (string) ($serial?->serial_number ?? $requested['serial_number'] ?? '-')];
        }

        ksort($groups);

        return array_values($groups);
    }

    public function serialEligible(ProductSerialNumber $serial, bool $isBroken): bool
    {
        $eligible = $isBroken ? $serial->isAvailableBroken() : $serial->isSellable();

        return $eligible && ! PendingDispatchSerialGuard::isReserved((string) $serial->serial_number);
    }

    /**
     * Locations with eligible stock for the product and condition across
     * all businesses. Totals are needed to allocate; per-bucket figures are
     * diagnostics shown only with stock visibility.
     */
    public function sourceOptions(int $productId, bool $isBroken, bool $withDiagnostics, ?array $locations = null): array
    {
        $locations ??= $this->locationDirectory();

        return ProductStock::where('product_id', $productId)
            ->whereIn('location_id', array_keys($locations))
            ->get()
            ->map(function (ProductStock $stock) use ($isBroken, $withDiagnostics, $locations) {
                $available = TransferV3InventoryPoster::eligibleQuantity($stock, $isBroken);
                if ($available <= 0) {
                    return null;
                }

                $option = [
                    'location_id' => (int) $stock->location_id,
                    'label'       => $locations[(int) $stock->location_id]['label'],
                    'available'   => $available,
                ];

                if ($withDiagnostics) {
                    $option['tax'] = (int) ($isBroken ? $stock->broken_quantity_tax : $stock->quantity_tax);
                    $option['non_tax'] = (int) ($isBroken ? $stock->broken_quantity_non_tax : $stock->quantity_non_tax);
                }

                return $option;
            })
            ->filter()
            ->sortBy('label')
            ->values()
            ->all();
    }

    /**
     * Active locations across every business, keyed by id.
     *
     * @return array<int, array{id: int, label: string, setting_id: int, is_consignment: bool}>
     */
    public function locationDirectory(): array
    {
        return Location::with('setting')->active()->get()
            ->mapWithKeys(fn (Location $location) => [(int) $location->id => [
                'id'             => (int) $location->id,
                'label'          => $this->label($location),
                'setting_id'     => (int) $location->setting_id,
                'is_consignment' => (bool) $location->is_consignment,
            ]])
            ->sortBy('label')
            ->all();
    }

    private function label(?Location $location): string
    {
        if (! $location) {
            return '-';
        }

        $business = $location->setting?->company_name;

        return $business ? "{$location->name} — {$business}" : (string) $location->name;
    }

    private function nullableLocationId(mixed $value, array $knownLocationIds): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $id = (int) $value;
        if (! in_array($id, $knownLocationIds, true)) {
            throw new InvalidArgumentException('Lokasi yang dipilih tidak valid.');
        }

        return $id;
    }

    private function requireCurrentRequestRevision(Transfer $transfer): TransferRequestRevision
    {
        $revision = $transfer->current_request_revision_id
            ? TransferRequestRevision::find($transfer->current_request_revision_id)
            : null;

        if (! $revision) {
            throw new RuntimeException('Dokumen belum memiliki revisi pengajuan yang berlaku.');
        }

        return $revision;
    }
}
