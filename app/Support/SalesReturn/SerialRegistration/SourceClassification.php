<?php

namespace App\Support\SalesReturn\SerialRegistration;

/**
 * Immutable result of classifying a historical fulfillment source.
 *
 * Eligibility for unknown-serial registration is decided ONLY from persisted
 * fulfillment evidence, never from the current Product catalog flag.
 */
final class SourceClassification
{
    public const RECORDED_SERIAL = 'recorded_serial';
    public const QUANTITY_ONLY = 'quantity_only';
    public const INCONSISTENT = 'inconsistent';

    public const BASIS_RECORDED_SERIALS = 'recorded_serials';
    public const BASIS_NO_SERIAL_EVIDENCE = 'no_serial_evidence_legacy_default';
    public const BASIS_UNRESOLVED_SERIAL_IDS = 'unresolved_serial_ids';
    public const BASIS_SERIALIZED_INDICATOR_WITHOUT_SERIALS = 'serialized_indicator_without_serials';

    /**
     * @param  list<int>  $recordedSerialIds  original-fulfillment serial IDs (return-created ones excluded)
     */
    public function __construct(
        public readonly string $type,
        public readonly string $basis,
        public readonly array $recordedSerialIds,
        public readonly string $fingerprint,
    ) {
    }

    public function allowsUnknownSerialRegistration(): bool
    {
        return $this->type === self::QUANTITY_ONLY;
    }

    public function isRecordedSerial(): bool
    {
        return $this->type === self::RECORDED_SERIAL;
    }

    public function isInconsistent(): bool
    {
        return $this->type === self::INCONSISTENT;
    }
}
