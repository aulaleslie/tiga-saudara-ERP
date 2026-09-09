<?php

namespace Modules\Adjustment\DTOs;

/**
 * Reviewer-only classification of one entered or destination-omitted serial.
 * Never expose directly to a counter-safe view.
 */
class SerialClassification
{
    public const STATUS_RETAINED = 'retained';
    public const STATUS_MOVED = 'moved';
    public const STATUS_NEW = 'new';
    public const STATUS_CONDITION_CHANGED = 'condition_changed';
    public const STATUS_TAX_CHANGED = 'tax_changed';
    public const STATUS_OMITTED = 'omitted';
    public const STATUS_CONFLICTING = 'conflicting';

    /**
     * @param string[] $statuses One or more of the STATUS_* constants. A moved
     *   serial that also changes condition and/or tax carries all applicable
     *   statuses so every impact is visible together, e.g. a taxable serial
     *   moved to a Non-PKP location reports [STATUS_MOVED, STATUS_TAX_CHANGED].
     *   `status` (singular) remains the primary/first status for simple
     *   equality checks against a single classification.
     */
    public function __construct(
        public readonly string $serialNumber,
        public readonly string $status,
        public readonly ?int $sourceSerialId,
        public readonly ?int $sourceLocationId,
        public readonly ?string $sourceLocationName,
        public readonly ?string $sourceCondition,
        public readonly ?bool $sourceIsTax,
        public readonly ?string $enteredCondition,
        public readonly ?bool $destinationIsTax,
        public readonly bool $sameTextOtherProduct = false,
        public readonly ?string $conflictReason = null,
        public readonly string $label = '',
        public readonly array $statuses = [],
        /**
         * True when this serial's source location belongs to a different
         * setting than the destination location. A structured flag (not a
         * parsed label string) so Blade and other consumers can render the
         * cross-setting movement fact without string-matching human text.
         */
        public readonly bool $crossSetting = false,
    ) {
    }

    public function hasStatus(string $status): bool
    {
        return in_array($status, $this->statuses !== [] ? $this->statuses : [$this->status], true);
    }

    public function toReviewerArray(): array
    {
        return [
            'serial_number' => $this->serialNumber,
            'status' => $this->status,
            'statuses' => $this->statuses !== [] ? $this->statuses : [$this->status],
            'label' => $this->label,
            'source_serial_id' => $this->sourceSerialId,
            'source_location_id' => $this->sourceLocationId,
            'source_location_name' => $this->sourceLocationName,
            'source_condition' => $this->sourceCondition,
            'source_is_tax' => $this->sourceIsTax,
            'entered_condition' => $this->enteredCondition,
            'destination_is_tax' => $this->destinationIsTax,
            'same_text_other_product' => $this->sameTextOtherProduct,
            'conflict_reason' => $this->conflictReason,
            'cross_setting' => $this->crossSetting,
        ];
    }

    /**
     * Only entered serial text/condition, never registration/source/tax facts.
     */
    public function toCounterArray(): array
    {
        return [
            'serial_number' => $this->serialNumber,
            'condition' => $this->enteredCondition,
        ];
    }
}
