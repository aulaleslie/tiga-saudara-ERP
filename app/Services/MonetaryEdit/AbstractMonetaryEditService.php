<?php

namespace App\Services\MonetaryEdit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Shared contract for post-fulfillment, monetary-only document edits.
 *
 * The single protected persistence path for received Purchases and dispatched
 * Sales. Every caller — Livewire component or legacy HTTP controller — routes
 * through {@see apply()} once the document's edit mode resolves to
 * MONETARY_ONLY; the normal delete-and-recreate update paths are never reached
 * in that mode.
 *
 * The guarantees enforced here, in order:
 *  - the header and all of its detail rows are locked before anything is read;
 *  - the edit mode is re-derived from the locked persisted record, so a stale
 *    or forged client state cannot widen authority;
 *  - the document belongs to the active setting;
 *  - submitted rows map one-to-one onto persisted detail IDs, with product and
 *    quantity unchanged on every row;
 *  - only whitelisted monetary values are written, in place, by primary key;
 *  - payment rows are never touched, and a total that would contradict the
 *    persisted active-payment state is rejected rather than reconciled.
 */
abstract class AbstractMonetaryEditService
{
    /**
     * Apply a monetary-only edit atomically.
     *
     * @param  Model  $document  the document as the caller knows it; reloaded under lock
     * @param  iterable  $cartItems  submitted cart rows
     * @param  array<string, mixed>  $input  submitted header monetary inputs
     *
     * @throws MonetaryEditException on any lifecycle, identity, or payment violation
     */
    public function apply(Model $document, iterable $cartItems, array $input, bool $isGlobal = false): Model
    {
        $cartItems = $cartItems instanceof Collection
            ? $cartItems
            : collect($cartItems);

        if ($cartItems->isEmpty()) {
            throw new MonetaryEditException('Produk tidak boleh kosong.');
        }

        return DB::transaction(function () use ($document, $cartItems, $input, $isGlobal) {
            $locked = $this->lockDocument($document);

            $this->assertMonetaryOnlyMode($locked);
            $this->assertAuthorizationAndEligibility($locked, $isGlobal);

            $details = $this->lockDetails($locked);
            $rows = $this->mapRows($cartItems, $details);

            $normalized = $this->normalize($locked, $rows, $input);

            $this->assertPaymentConsistency($locked, (float) $normalized['header']['total_amount']);

            $this->persistHeader($locked, $normalized['header'], $input);
            $this->persistDetails($rows, $normalized);

            return $locked;
        });
    }

    /**
     * Reload the document with a row lock so the mode is judged on persisted state.
     */
    protected function lockDocument(Model $document): Model
    {
        $class = $document::class;

        $locked = $class::query()
            ->whereKey($document->getKey())
            ->lockForUpdate()
            ->first();

        if (! $locked) {
            throw new MonetaryEditException('Dokumen tidak ditemukan.');
        }

        return $locked;
    }

    /**
     * Build the submitted-row → persisted-detail mapping.
     *
     * Rows are matched on the persisted detail ID carried in cart metadata,
     * never on product ID: a document may legitimately hold several lines for
     * the same product, and product identity is itself a protected value.
     *
     * @param  Collection<int, object>  $details  keyed by detail ID
     * @return array<int, MonetaryEditRow>
     */
    protected function mapRows(Collection $cartItems, Collection $details): array
    {
        if ($cartItems->count() !== $details->count()) {
            throw new MonetaryEditException(
                'Jumlah baris produk tidak sesuai dengan dokumen asli. Penambahan atau penghapusan baris tidak diizinkan.'
            );
        }

        $rows = [];
        $seen = [];

        foreach ($cartItems as $cartItem) {
            $detailId = $this->resolveSubmittedDetailId($cartItem);

            if ($detailId === null) {
                throw new MonetaryEditException('Baris produk tidak memiliki identitas detail yang valid.');
            }

            if (isset($seen[$detailId])) {
                throw new MonetaryEditException('Baris detail duplikat tidak diizinkan.');
            }

            if (! $details->has($detailId)) {
                throw new MonetaryEditException('Baris detail tidak dikenali pada dokumen ini.');
            }

            $detail = $details->get($detailId);

            $this->assertProtectedRowValues($detail, $cartItem);

            $seen[$detailId] = true;
            $rows[] = new MonetaryEditRow($detailId, $detail, $cartItem);
        }

        return $rows;
    }

    /**
     * Reject a new total that the persisted active payments could not support.
     *
     * Payment rows are outside this workflow's authority, so an inconsistency
     * is refused rather than papered over by adjusting them.
     */
    protected function assertPaymentConsistency(Model $document, float $totalAmount): void
    {
        $paid = round($this->effectivePaidAmount($document), 2);

        if ($paid > round($totalAmount, 2) + 0.001) {
            throw new MonetaryEditException(sprintf(
                'Total dokumen (%s) tidak boleh lebih kecil dari jumlah pembayaran yang sudah tercatat (%s).',
                number_format($totalAmount, 2),
                number_format($paid, 2)
            ), 'total_amount');
        }
    }

    /**
     * The monetary values allowed onto each persisted detail row.
     *
     * Deliberately excludes product_id, product_name, product_code and
     * quantity: those are validated as unchanged, never written.
     *
     * @return array<string, mixed>
     */
    protected function detailMonetaryPayload(array $normalizedDetail): array
    {
        return [
            'unit_price' => $normalizedDetail['unit_price'],
            'price' => $normalizedDetail['price'],
            'product_discount_type' => $normalizedDetail['product_discount_type'],
            'product_discount_amount' => $normalizedDetail['product_discount_amount'],
            'sub_total' => $normalizedDetail['sub_total'],
            'product_tax_amount' => $normalizedDetail['product_tax_amount'],
            'tax_id' => $normalizedDetail['tax_id'],
        ];
    }

    /**
     * Write monetary values onto the existing rows by primary key.
     *
     * Updating in place is what preserves every received-note, dispatch,
     * bundle, serial, and stock-history link hanging off these row IDs.
     *
     * @param  array<int, MonetaryEditRow>  $rows
     */
    protected function persistDetails(array $rows, array $normalized): void
    {
        foreach ($rows as $index => $row) {
            $row->detail->forceFill($this->detailMonetaryPayload($normalized['details'][$index]))->save();
        }
    }

    /**
     * PKP status of the business that owns the locked document.
     *
     * Read from the document's own `setting_id` inside the transaction rather
     * than from session state, a Livewire property, or any submitted field:
     * tax treatment of a fulfilled document is a property of the owning
     * business, and must not be steerable by the request.
     */
    protected function resolveIsPkp(Model $document): bool
    {
        return (bool) (\Modules\Setting\Entities\Setting::query()
            ->whereKey((int) $document->setting_id)
            ->value('is_pkp') ?? false);
    }

    /**
     * Tax-inclusive flag for the locked document.
     *
     * A non-PKP business can never carry tax, so the stored flag is forced
     * false there. Otherwise the persisted value stands: `is_tax_included`
     * describes how the document's existing prices were entered, and this
     * workflow corrects amounts, not that interpretation.
     */
    protected function resolveIsTaxIncluded(Model $document, bool $isPkp): bool
    {
        return $isPkp && (bool) $document->is_tax_included;
    }

    protected function assertAuthorizationAndEligibility(Model $document, bool $isGlobal): void
    {
        $user = auth()->user();
        if (! $user) {
            throw new MonetaryEditException('Pengguna belum terautentikasi.');
        }

        if (method_exists($document, 'isArchived') && $document->isArchived()) {
            throw new MonetaryEditException('Transaksi yang telah diarsipkan tidak dapat diubah.');
        }

        if ($isGlobal) {
            $this->assertGlobalAuthorizationAndEligibility($document, $user);
        } else {
            $this->assertBelongsToActiveSetting($document);
            $this->assertNormalAuthorization($document, $user);
        }
    }

    /** Lock and return the document's current detail rows, keyed by ID. */
    abstract protected function lockDetails(Model $document): Collection;

    /** Reject anything whose persisted edit mode is not monetary-only. */
    abstract protected function assertMonetaryOnlyMode(Model $document): void;

    /** Reject documents outside the caller's active setting. */
    abstract protected function assertBelongsToActiveSetting(Model $document): void;

    /** Validate global authorization and global payment document eligibility. */
    abstract protected function assertGlobalAuthorizationAndEligibility(Model $document, mixed $user): void;

    /** Validate normal tenant authorization for monetary edit. */
    abstract protected function assertNormalAuthorization(Model $document, mixed $user): void;

    /** Read the persisted detail ID a submitted cart row claims. */
    abstract protected function resolveSubmittedDetailId(mixed $cartItem): ?int;

    /** Reject a row whose product identity or quantity was altered. */
    abstract protected function assertProtectedRowValues(object $detail, mixed $cartItem): void;

    /**
     * Run the document's normalizer over the permitted monetary inputs.
     *
     * @param  array<int, MonetaryEditRow>  $rows
     * @return array{header: array<string, mixed>, details: array<int, array<string, mixed>>}
     */
    abstract protected function normalize(Model $document, array $rows, array $input): array;

    /** Write the whitelisted monetary values onto the header. */
    abstract protected function persistHeader(Model $document, array $header, array $input): void;

    /** Paid amount derived from persisted active payments only. */
    abstract protected function effectivePaidAmount(Model $document): float;
}
