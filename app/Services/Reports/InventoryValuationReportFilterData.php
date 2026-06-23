<?php

namespace App\Services\Reports;

use Carbon\Carbon;
use Illuminate\Http\Request;

class InventoryValuationReportFilterData
{
    public Carbon $tanggalAwal;
    public Carbon $tanggalAkhir;
    public array $categoryIds;
    public string $categoryMatchMode; // 'all', 'any'
    public array $productIds;
    public string $sortColumn;
    public string $sortDirection;

    public function __construct(
        ?Carbon $tanggalAwal = null,
        ?Carbon $tanggalAkhir = null,
        array $categoryIds = [],
        string $categoryMatchMode = 'any',
        array $productIds = [],
        string $sortColumn = 'product_name',
        string $sortDirection = 'asc'
    ) {
        $now = now();
        $this->tanggalAwal = $tanggalAwal ? $tanggalAwal->copy()->startOfDay() : $now->copy()->startOfMonth()->startOfDay();
        $this->tanggalAkhir = $tanggalAkhir ? $tanggalAkhir->copy()->endOfDay() : $now->copy()->endOfMonth()->endOfDay();
        $this->categoryIds = $categoryIds;
        $this->categoryMatchMode = in_array($categoryMatchMode, ['all', 'any']) ? $categoryMatchMode : 'any';
        $this->productIds = $productIds;
        $this->sortColumn = in_array($sortColumn, ['product_name', 'product_code', 'stock', 'average_cost', 'value']) ? $sortColumn : 'product_name';
        $this->sortDirection = in_array(strtolower($sortDirection), ['asc', 'desc']) ? strtolower($sortDirection) : 'asc';
    }

    public static function fromRequest(Request $request): self
    {
        $tanggalAwal = $request->filled('tanggalAwal') ? Carbon::parse($request->input('tanggalAwal')) : null;
        $tanggalAkhir = $request->filled('tanggalAkhir') ? Carbon::parse($request->input('tanggalAkhir')) : null;
        
        return new self(
            $tanggalAwal,
            $tanggalAkhir,
            $request->input('categoryIds', []),
            $request->input('categoryMatchMode', 'any'),
            $request->input('productIds', []),
            $request->input('sortColumn', 'product_name'),
            $request->input('sortDirection', 'asc')
        );
    }
    
    public static function fromArray(array $filters): self
    {
        $tanggalAwal = isset($filters['tanggalAwal']) && $filters['tanggalAwal'] ? Carbon::parse($filters['tanggalAwal']) : null;
        $tanggalAkhir = isset($filters['tanggalAkhir']) && $filters['tanggalAkhir'] ? Carbon::parse($filters['tanggalAkhir']) : null;

        return new self(
            $tanggalAwal,
            $tanggalAkhir,
            $filters['categoryIds'] ?? [],
            $filters['categoryMatchMode'] ?? 'any',
            $filters['productIds'] ?? [],
            $filters['sortColumn'] ?? 'product_name',
            $filters['sortDirection'] ?? 'asc'
        );
    }
}
