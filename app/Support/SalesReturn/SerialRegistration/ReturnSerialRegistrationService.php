<?php

namespace App\Support\SalesReturn\SerialRegistration;

use App\Services\SerialNumberHistoryService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\SerialNumberHistory;
use Modules\SalesReturn\Entities\ReturnSerialRegistration;

/**
 * Shared registration of proposed (unknown) serials for both return workflows.
 *
 * Draft phase  : saveProposals() persists text + source evidence only (no ProductSerialNumber).
 * Receiving    : register() runs INSIDE the caller's transaction, creates the serial and records
 *                actor/time/source on the claim. A committed claim replays idempotently; an
 *                independently created serial is a collision that aborts the whole action.
 */
class ReturnSerialRegistrationService
{
    public function __construct(private readonly PendingSerialValidator $validator)
    {
    }

    /**
     * Replace the unregistered proposals of one return line with the given texts.
     * Claim UUIDs of unchanged texts are preserved so identity is stable across edits.
     *
     * @param  array<string,mixed>  $line  workflow, sale_return_id|pos_return_id, *_detail_id|*_line_id, product_id, source_key,
     *                                      component_product_id?, classification (SourceClassification)
     * @param  list<string>  $texts
     * @return Collection<int,ReturnSerialRegistration>
     */
    public function saveProposals(array $line, array $texts): Collection
    {
        /** @var SourceClassification $class */
        $class = $line['classification'];
        if (! $class->allowsUnknownSerialRegistration()) {
            throw new \DomainException('Sumber ini tidak mengizinkan pendaftaran nomor seri baru.');
        }

        $column = $this->lineColumn($line);
        $lineId = (int) $line[$column];

        $existing = ReturnSerialRegistration::query()
            ->where('workflow', $line['workflow'])->where($column, $lineId)
            ->whereNull('registered_serial_id')->lockForUpdate()->get()
            ->keyBy('proposed_serial');

        $normalized = collect($texts)->map(fn ($t) => $this->validator->normalize((string) $t))->values();
        $existing->reject(fn ($row, $text) => $normalized->contains($text))->each->delete();

        return $normalized->map(function (string $text, int $i) use ($line, $class, $existing, $lineId) {
            $row = $existing->get($text) ?? new ReturnSerialRegistration(['claim_uuid' => (string) (($line['claim_uuids'][$text] ?? null) ?: Str::uuid())]);
            $row->fill([
                'workflow' => $line['workflow'],
                'sale_return_id' => $line['sale_return_id'] ?? null,
                'sale_return_detail_id' => $line['sale_return_detail_id'] ?? null,
                'pos_return_id' => $line['pos_return_id'] ?? null,
                'pos_return_line_id' => $line['pos_return_line_id'] ?? null,
                'product_id' => $line['product_id'],
                'source_key' => $line['source_key'],
                'component_product_id' => $line['component_product_id'] ?? null,
                'source_classification' => $class->type,
                'source_basis' => $class->basis,
                'source_fingerprint' => $class->fingerprint,
                'unit_attribution_key' => $line['source_key'].'#'.$row->claim_uuid,
                'proposed_serial' => $text,
            ]);
            $row->save();

            return $row;
        })->values();
    }

    /**
     * @param  Collection<int,ReturnSerialRegistration>  $claims  claims of ONE receiving action
     * @param  array{location_id:int,tax_id:?int,dispatch_detail_id?:?int,status?:string,is_in_return_process?:bool,actor_id:?int,at:\DateTimeInterface,reference?:object,note?:string}  $ctx
     * @return Collection<string,ProductSerialNumber> claim_uuid => serial
     */
    public function register(Collection $claims, array $ctx): Collection
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Registrasi nomor seri harus dijalankan di dalam transaksi penerimaan.');
        }

        $result = collect();
        $claims = ReturnSerialRegistration::query()
            ->whereIn('id', $claims->pluck('id')->all())->orderBy('id')->lockForUpdate()->get();

        foreach ($claims as $claim) {
            if ($claim->registered_serial_id) {
                $result[$claim->claim_uuid] = ProductSerialNumber::query()->findOrFail($claim->registered_serial_id);   // replay
                continue;
            }

            $text = ProductSerialNumber::normalize($claim->proposed_serial);
            if (ProductSerialNumber::query()->where('product_id', $claim->product_id)->where('serial_number', $text)->lockForUpdate()->exists()) {
                throw new SerialRegistrationCollisionException($claim->claim_uuid, (int) $claim->product_id, $text);
            }

            try {
                $serial = ProductSerialNumber::create([
                    'product_id' => $claim->product_id,
                    'serial_number' => $text,
                    'location_id' => $ctx['location_id'],
                    'tax_id' => $ctx['tax_id'] ?? null,
                    'dispatch_detail_id' => $ctx['dispatch_detail_id'] ?? null,
                    'status' => $ctx['status'] ?? ProductSerialNumber::STATUS_RETURN_IN_PROCESS,
                    'is_in_return_process' => $ctx['is_in_return_process'] ?? true,
                ]);
            } catch (QueryException $e) {
                // Concurrent creator won the unique index. Never continue a failed transaction.
                if ($this->isUniqueViolation($e)) {
                    throw new SerialRegistrationCollisionException($claim->claim_uuid, (int) $claim->product_id, $text);
                }
                throw $e;
            }

            $claim->forceFill([
                'registered_serial_id' => $serial->id,
                'registered_by' => $ctx['actor_id'] ?? null,
                'registered_at' => $ctx['at'],
            ])->save();

            SerialNumberHistoryService::record(
                $serial->id,
                SerialNumberHistory::EVENT_RECEIVED,
                (int) $ctx['location_id'],
                $ctx['reference'] ?? $claim,
                $ctx['note'] ?? 'Didaftarkan melalui penerimaan retur (nomor seri belum tercatat pada penjualan asal)',
                $ctx['actor_id'] ?? null,
            );

            $result[$claim->claim_uuid] = $serial;
        }

        return $result;
    }

    /**
     * Standard workflow: persist the proposed serials of an authoritative row onto its saved return detail.
     * Does nothing to inventory. Source eligibility is re-derived from persisted fulfillment.
     *
     * @param  array<string,mixed>  $row  authoritative claim row (pending_serials, claim_uuids?)
     */
    public function saveStandardProposals(\Modules\SalesReturn\Entities\SaleReturnDetail $detail, array $row): void
    {
        $texts = $row['pending_serials'] ?? [];
        if ($texts === []) {
            return;
        }
        $dispatchDetail = \Modules\Sale\Entities\DispatchDetail::query()->findOrFail($detail->dispatch_detail_id);
        $this->saveProposals([
            'workflow' => ReturnSerialRegistration::WORKFLOW_STANDARD,
            'sale_return_id' => $detail->sale_return_id,
            'sale_return_detail_id' => $detail->id,
            'product_id' => $detail->product_id,
            'source_key' => HistoricalFulfillmentClassifier::standardSourceKey((int) $dispatchDetail->id),
            'classification' => app(HistoricalFulfillmentClassifier::class)->forDispatchDetail($dispatchDetail),
            'claim_uuids' => $row['claim_uuids'] ?? [],
        ], $texts);
    }

    /**
     * POS workflow: one claim per persisted quantity-style PosReturnLine (quantity 1).
     * Eligibility is derived from persisted fulfillment of the line's dispatch detail.
     *
     * @param  array<string,string>  $claimUuids  normalized text => preserved claim uuid (server-read on edit)
     */
    public function savePosProposal(\Modules\Pos\Entities\PosReturnLine $line, string $text, array $claimUuids = []): ReturnSerialRegistration
    {
        $dispatchDetail = \Modules\Sale\Entities\DispatchDetail::query()->findOrFail($line->dispatch_detail_id);

        return $this->saveProposals([
            'workflow' => ReturnSerialRegistration::WORKFLOW_POS,
            'pos_return_id' => $line->pos_return_id,
            'pos_return_line_id' => $line->id,
            'product_id' => $line->product_id,
            'source_key' => HistoricalFulfillmentClassifier::standardSourceKey((int) $dispatchDetail->id),
            'classification' => app(HistoricalFulfillmentClassifier::class)->forDispatchDetail($dispatchDetail),
            'claim_uuids' => $claimUuids,
        ], [$text])->first();
    }

    /** @return Collection<int,ReturnSerialRegistration> */
    public function pendingFor(string $workflow, string $column, int $id): Collection
    {
        return ReturnSerialRegistration::query()->where('workflow', $workflow)->where($column, $id)->orderBy('id')->get();
    }

    private function lineColumn(array $line): string
    {
        return $line['workflow'] === ReturnSerialRegistration::WORKFLOW_STANDARD ? 'sale_return_detail_id' : 'pos_return_line_id';
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $code = (string) ($e->errorInfo[1] ?? '');

        return in_array($code, ['1062', '19', '2067', '1555'], true) || ($e->getCode() === '23505') || Str::contains($e->getMessage(), 'UNIQUE');
    }
}
