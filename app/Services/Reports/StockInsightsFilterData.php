<?php

namespace App\Services\Reports;

use Carbon\Carbon;
use InvalidArgumentException;

class StockInsightsFilterData
{
    public const STATUS_OUT_OF_STOCK = 'Stok Habis';
    public const STATUS_REORDER_REQUIRED = 'Perlu Dibeli Lagi';
    public const STATUS_MINIMUM_UNSET = 'Batas Minimum Belum Diatur';
    public const STATUS_SLOW_MOVING = 'Lama Tidak Terjual';

    public const ALL_STATUSES = [
        self::STATUS_OUT_OF_STOCK,
        self::STATUS_REORDER_REQUIRED,
        self::STATUS_MINIMUM_UNSET,
        self::STATUS_SLOW_MOVING,
    ];

    public const ALLOWED_SORTS = [
        'default',
        'product_name',
        'global_stock',
        'sold_quantity',
        'sales_value',
        'sold_cost',
        'gross_profit',
        'last_sale_date',
    ];

    public string $search;
    public array $statuses; // array of status strings
    public array $categoryIds;
    public array $brandIds;
    public string $startDate; // 'Y-m-d'
    public string $endDate;   // 'Y-m-d' (always today in app timezone)
    public int $durationDays; // inclusive count of days
    public ?string $preset;   // '7', '30', '90', or null if custom
    public string $sortColumn;
    public string $sortDirection; // 'asc' or 'desc'
    public int $perPage;
    public int $page;

    public function __construct(
        string $search = '',
        array $statuses = [],
        array $categoryIds = [],
        array $brandIds = [],
        ?string $startDate = null,
        string $sortColumn = 'default',
        string $sortDirection = 'asc',
        int $perPage = 25,
        int $page = 1,
        ?Carbon $today = null
    ) {
        $today = $today ? $today->copy()->startOfDay() : Carbon::today();
        $this->endDate = $today->format('Y-m-d');

        $this->search = trim($search);

        // Normalize statuses: only allowed strings, distinct
        $this->statuses = array_values(array_intersect(self::ALL_STATUSES, $statuses));

        $this->categoryIds = array_values(array_unique(array_map('intval', array_filter($categoryIds))));
        $this->brandIds = array_values(array_unique(array_map('intval', array_filter($brandIds))));

        // Start date normalization and validation
        if ($startDate !== null && trim($startDate) !== '') {
            $parsedStart = Carbon::parse($startDate)->startOfDay();
            if ($parsedStart->greaterThan($today)) {
                throw new InvalidArgumentException('Tanggal mulai tidak boleh lebih dari hari ini.');
            }
            $this->startDate = $parsedStart->format('Y-m-d');
        } else {
            // Default: 7 inclusive days (today and preceding 6 days)
            $this->startDate = $today->copy()->subDays(6)->format('Y-m-d');
        }

        $startDateCarbon = Carbon::parse($this->startDate)->startOfDay();
        $this->durationDays = (int) $startDateCarbon->diffInDays($today) + 1;

        if (in_array($this->durationDays, [7, 30, 90], true)) {
            $this->preset = (string) $this->durationDays;
        } else {
            $this->preset = null;
        }

        // Sorting
        $sortColumn = strtolower(trim($sortColumn));
        $this->sortColumn = in_array($sortColumn, self::ALLOWED_SORTS, true) ? $sortColumn : 'default';

        $sortDirection = strtolower(trim($sortDirection));
        $this->sortDirection = in_array($sortDirection, ['asc', 'desc'], true) ? $sortDirection : 'asc';

        $this->perPage = $perPage > 0 ? $perPage : 25;
        $this->page = $page > 0 ? $page : 1;
    }

    public static function fromArray(array $data, ?Carbon $today = null): self
    {
        return new self(
            $data['search'] ?? '',
            $data['statuses'] ?? [],
            $data['categoryIds'] ?? [],
            $data['brandIds'] ?? [],
            $data['startDate'] ?? null,
            $data['sortColumn'] ?? 'default',
            $data['sortDirection'] ?? 'asc',
            (int) ($data['perPage'] ?? 25),
            (int) ($data['page'] ?? 1),
            $today
        );
    }

    public function getPeriodLabel(): string
    {
        return "{$this->durationDays} Hari Terakhir";
    }
}
