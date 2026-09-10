<?php

namespace Modules\Adjustment\DTOs;

/**
 * Builds the versioned `approval_result` payload persisted on successful
 * breakage approval (design.md "Persist actual approval evidence in the
 * existing audit field"). Unlike Stock Opname's approval_result (which has
 * no explicit version key), breakage introduces SCHEMA_VERSION so future
 * changes to this shape can be detected by readers (e.g. the show view's
 * legacy-fallback check).
 */
class BreakageApprovalResult
{
    public const SCHEMA_VERSION = 1;

    /**
     * @param array $products Each: [
     *   product_id, product_name, product_code, base_unit, is_serialized,
     *   before: [good, bad], movement, after: [good, bad],
     *   serials: [[serial_id, serial_number, condition_before, condition_after]],
     * ]
     * @param string[] $warnings
     */
    public static function build(
        int $adjustmentId,
        int $locationId,
        string $locationName,
        int $settingId,
        bool $isPkp,
        int $approvedBy,
        string $approvedByName,
        string $approvedAt,
        array $products,
        array $warnings = [],
    ): array {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'adjustment_id' => $adjustmentId,
            'location_id' => $locationId,
            'location_name' => $locationName,
            'setting_id' => $settingId,
            'is_pkp' => $isPkp,
            'approved_by' => $approvedBy,
            'approved_by_name' => $approvedByName,
            'approved_at' => $approvedAt,
            'products' => $products,
            'warnings' => $warnings,
        ];
    }

    public static function isVersioned(?array $result): bool
    {
        return is_array($result) && isset($result['schema_version']);
    }
}
