<?php

namespace App\Services\Reports;

use Carbon\Carbon;
use Illuminate\Http\Request;

class InventorySummaryReportFilterData
{
    public ?Carbon $asOfDate;
    public ?string $stockStatus; // 'available', 'out_of_stock', 'below_minimum'
    public array $categoryIds;
    public string $categoryMatchMode; // 'all', 'any'
    public array $productIds;
    public string $sortColumn;
    public string $sortDirection;
    public bool $showInventoryAccount;

    public function __construct(
        ?Carbon $asOfDate = null,
        ?string $stockStatus = null,
        array $categoryIds = [],
        string $categoryMatchMode = 'any',
        array $productIds = [],
        string $sortColumn = 'product_name',
        string $sortDirection = 'asc',
        bool $showInventoryAccount = false
    ) {
        $this->asOfDate = $asOfDate ? $asOfDate->copy()->endOfDay() : now()->endOfDay();
        $this->stockStatus = $stockStatus;
        $this->categoryIds = $categoryIds;
        $this->categoryMatchMode = in_array($categoryMatchMode, ['all', 'any']) ? $categoryMatchMode : 'any';
        $this->productIds = $productIds;
        $this->sortColumn = in_array($sortColumn, ['product_name', 'product_code', 'stock', 'average_cost', 'value']) ? $sortColumn : 'product_name';
        $this->sortDirection = in_array(strtolower($sortDirection), ['asc', 'desc']) ? strtolower($sortDirection) : 'asc';
        $this->showInventoryAccount = $showInventoryAccount;
    }

    public static function fromRequest(Request $request): self
    {
        $asOfDate = $request->filled('asOfDate') ? Carbon::parse($request->input('asOfDate'))->endOfDay() : now()->endOfDay();
        
        return new self(
            $asOfDate,
            $request->input('stockStatus'),
            $request->input('categoryIds', []),
            $request->input('categoryMatchMode', 'any'),
            $request->input('productIds', []),
            $request->input('sortColumn', 'product_name'),
            $request->input('sortDirection', 'asc'),
            $request->boolean('showInventoryAccount')
        );
    }
    
    public static function fromArray(array $filters): self
    {
        $asOfDate = isset($filters['asOfDate']) && $filters['asOfDate'] ? Carbon::parse($filters['asOfDate'])->endOfDay() : now()->endOfDay();

        return new self(
            $asOfDate,
            $filters['stockStatus'] ?? null,
            $filters['categoryIds'] ?? [],
            $filters['categoryMatchMode'] ?? 'any',
            $filters['productIds'] ?? [],
            $filters['sortColumn'] ?? 'product_name',
            $filters['sortDirection'] ?? 'asc',
            $filters['showInventoryAccount'] ?? false
        );
    }
}
