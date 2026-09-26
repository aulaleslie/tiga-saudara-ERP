<?php

namespace App\Livewire\Reports;

use App\Services\Reports\StockInsightsFilterData;
use App\Services\Reports\StockInsightsQueryService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\Product\Entities\Product;

class StockInsightsMinimumModal extends Component
{
    public bool $showMinimumModal = false;
    public ?int $modalProductId = null;
    public string $modalProductName = '';
    public string $modalProductCode = '';
    public ?string $modalBarcode = null;
    public float $modalGlobalGoodStock = 0.0;
    public float $modalTaxGood = 0.0;
    public float $modalNonTaxGood = 0.0;
    public float $modalTaxBroken = 0.0;
    public float $modalNonTaxBroken = 0.0;
    public float $modalSoldQuantity = 0.0;
    public float $modalSalesValue = 0.0;
    public ?string $modalLastSaleDate = null;
    public string $modalMinimumInput = '';
    public string $modalErrorMessage = '';
    public string $periodLabel = '';
    public string $startDate = '';

    #[On('open-stock-insights-minimum-modal')]
    public function openMinimumModal(
        int $productId,
        string $startDate,
        string $periodLabel,
        ?string $productName = null,
        ?string $productCode = null,
        ?string $barcode = null,
        ?int $stockAlert = null,
        ?float $globalGoodStock = null,
        ?float $taxGood = null,
        ?float $nonTaxGood = null,
        ?float $taxBroken = null,
        ?float $nonTaxBroken = null,
        ?float $soldQuantity = null,
        ?string $lastSaleDate = null
    ): void {
        abort_unless(auth()->user()->can('stockInsights.access'), 403);

        $this->startDate = $startDate;
        $this->periodLabel = $periodLabel;

        // Prefer already-computed row metrics from the report table to avoid
        // re-running the sales aggregation query for frequently sold products.
        if ($productName !== null) {
            $this->modalProductId = $productId;
            $this->modalProductName = $productName;
            $this->modalProductCode = (string) $productCode;
            $this->modalBarcode = $barcode;

            $this->modalGlobalGoodStock = $globalGoodStock ?? 0.0;
            $this->modalTaxGood = $taxGood ?? 0.0;
            $this->modalNonTaxGood = $nonTaxGood ?? 0.0;
            $this->modalTaxBroken = $taxBroken ?? 0.0;
            $this->modalNonTaxBroken = $nonTaxBroken ?? 0.0;

            $this->modalSoldQuantity = $soldQuantity ?? 0.0;
            $this->modalLastSaleDate = $lastSaleDate;

            $this->modalMinimumInput = (string) ($stockAlert ?? 0);
            $this->modalErrorMessage = '';
            $this->showMinimumModal = true;
            return;
        }

        $product = Product::without(['media', 'brand', 'category'])
            ->where('id', $productId)
            ->where('is_active', true)
            ->whereNull('merged_into_id')
            ->where('stock_managed', true)
            ->firstOrFail(['id', 'product_name', 'product_code', 'barcode', 'product_stock_alert']);

        $queryService = app(StockInsightsQueryService::class);
        $stockMatrix = $queryService->getStockMatrix([$productId]);
        $stockBucket = $stockMatrix[$productId]['global'] ?? null;

        $today = Carbon::today()->format('Y-m-d');
        $financialAggregates = $queryService->getSalesAndFinancialAggregates([$productId], $this->startDate, $today);
        $financial = $financialAggregates[$productId] ?? [];

        $this->modalProductId = $productId;
        $this->modalProductName = (string) $product->product_name;
        $this->modalProductCode = (string) $product->product_code;
        $this->modalBarcode = $product->barcode ? (string) $product->barcode : null;

        $this->modalGlobalGoodStock = $stockBucket ? $stockBucket->totalGood : 0.0;
        $this->modalTaxGood = $stockBucket ? $stockBucket->taxGood : 0.0;
        $this->modalNonTaxGood = $stockBucket ? $stockBucket->nonTaxGood : 0.0;
        $this->modalTaxBroken = $stockBucket ? $stockBucket->taxBroken : 0.0;
        $this->modalNonTaxBroken = $stockBucket ? $stockBucket->nonTaxBroken : 0.0;

        $this->modalSoldQuantity = (float) ($financial['sold_quantity'] ?? 0.0);
        $this->modalSalesValue = (float) ($financial['sales_value'] ?? 0.0);
        $this->modalLastSaleDate = $financial['last_sale_date'] ?? null;

        $this->modalMinimumInput = (string) ($product->product_stock_alert ?? 0);
        $this->modalErrorMessage = '';
        $this->showMinimumModal = true;
    }

    public function closeMinimumModal(): void
    {
        $this->showMinimumModal = false;
        $this->modalProductId = null;
        $this->modalErrorMessage = '';
    }

    public function saveMinimumStock(): void
    {
        abort_unless(auth()->user()->can('stockInsights.access'), 403);

        if (!$this->modalProductId) {
            return;
        }

        $minInput = trim($this->modalMinimumInput);
        if (!ctype_digit($minInput) || (int) $minInput < 0) {
            $this->modalErrorMessage = 'Batas minimum stok harus berupa angka bulat positif (0 atau lebih).';
            return;
        }

        $newMinimum = (int) $minInput;

        try {
            DB::transaction(function () use ($newMinimum) {
                $product = Product::without(['media', 'brand', 'category'])
                    ->where('id', $this->modalProductId)
                    ->where('is_active', true)
                    ->whereNull('merged_into_id')
                    ->where('stock_managed', true)
                    ->lockForUpdate()
                    ->firstOrFail(['id', 'product_stock_alert']);

                $product->product_stock_alert = $newMinimum;
                $product->save();
            });

            $savedName = $this->modalProductName;
            $productId = $this->modalProductId;
            $this->closeMinimumModal();

            $this->dispatch('stock-insights-minimum-saved', productId: $productId, productName: $savedName, newMinimum: $newMinimum)->to(StockInsights::class);
        } catch (\Throwable $e) {
            Log::error('Gagal menyimpan batas minimum stok produk ' . ($this->modalProductId ?? 'unknown') . ': ' . $e->getMessage(), [
                'exception' => $e,
                'product_id' => $this->modalProductId,
                'minimum_input' => $this->modalMinimumInput,
            ]);
            $this->modalErrorMessage = 'Terjadi kesalahan saat menyimpan batas minimum stok. Silakan coba lagi.';
        }
    }

    public function render()
    {
        return view('livewire.reports.stock-insights-minimum-modal');
    }
}
