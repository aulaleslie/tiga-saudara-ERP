<?php

namespace Modules\Pos\Support;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Auth\Authenticatable;

final class PosExpectedCashVisibility
{
    public const PERMISSION = 'pos.sessions.viewExpectedCash';

    /**
     * Keys that directly disclose or allow reconstruction of expected drawer cash.
     *
     * @var array<int, string>
     */
    private const SUMMARY_PROTECTED_KEYS = [
        'expected_cash_total',
        'cached_expected_cash_total',
        'threshold_value',
        'threshold_source',
        'is_threshold_breached',
        'cash_tendered_total',
        'change_total',
        'net_cash_sales_total',
        'kas_bon_collection_total',
        'kas_bon_cash_tendered_total',
        'kas_bon_change_total',
        'kas_bon_net_cash_total',
        'kas_bon_cash_total',
        'kas_bon_non_cash_total',
        'cash_events',
    ];

    /**
     * @var array<int, string>
     */
    private const PICKUP_PROTECTED_KEYS = [
        'expected_cash_before',
        'expected_cash_after',
        'threshold_value',
        'is_threshold_breached',
    ];

    /**
     * @var array<int, string>
     */
    private const FINALIZE_PROTECTED_KEYS = [
        'expected_cash_total',
        'variance_total',
        'variance_threshold',
    ];

    /**
     * @var array<int, string>
     */
    private const RECONCILIATION_PROTECTED_KEYS = [
        'opening_float',
        'expected_cash',
        'variance',
        'pos_cash_sales_total',
        'pos_non_cash_sales_total',
        'pos_kas_bon_cash_total',
        'pos_kas_bon_non_cash_total',
        'safe_drop_total',
    ];

    /**
     * @var array<int, string>
     */
    private const REPORT_CASH_PROTECTED_KEYS = [
        'cash_total',
        'non_cash_total',
    ];

    public static function canView(?Authenticatable $user): bool
    {
        if (! $user instanceof Authorizable) {
            return false;
        }

        return $user->can(self::PERMISSION);
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    public static function projectSummary(array $summary, ?Authenticatable $user): array
    {
        return self::projectPayload($summary, self::SUMMARY_PROTECTED_KEYS, self::canView($user));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function projectPickupResponse(array $payload, ?Authenticatable $user): array
    {
        return self::projectPayload($payload, self::PICKUP_PROTECTED_KEYS, self::canView($user));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function projectFinalizeResponse(array $payload, ?Authenticatable $user): array
    {
        return self::projectPayload($payload, self::FINALIZE_PROTECTED_KEYS, self::canView($user));
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    public static function projectReconciliation(array $rows, ?Authenticatable $user): array
    {
        return self::projectRows($rows, self::RECONCILIATION_PROTECTED_KEYS, self::canView($user));
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    public static function projectSalesReportRows(array $rows, ?Authenticatable $user): array
    {
        return self::projectRows($rows, self::REPORT_CASH_PROTECTED_KEYS, self::canView($user));
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    public static function projectPaymentMethodReportRows(array $rows, ?Authenticatable $user): array
    {
        if (! self::canView($user)) {
            return [];
        }

        return $rows;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, string>  $protectedKeys
     * @return array<int, array<string, mixed>>
     */
    private static function projectRows(array $rows, array $protectedKeys, bool $canView): array
    {
        return array_map(
            static fn (array $row): array => self::projectPayload($row, $protectedKeys, $canView),
            $rows
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $protectedKeys
     * @return array<string, mixed>
     */
    private static function projectPayload(array $payload, array $protectedKeys, bool $canView): array
    {
        $projected = $payload;
        $projected['can_view_expected_cash'] = $canView;

        if ($canView) {
            return $projected;
        }

        foreach ($protectedKeys as $key) {
            unset($projected[$key]);
        }

        return $projected;
    }
}
