<?php

namespace App\Livewire\Adjustment;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\Transaction;

class BreakageProductTable extends Component
{
    protected $listeners = ['productSelected', 'serialNumberSelected', 'locationSelected'];

    public array $products = [];
    public bool $hasAdjustments = false;
    public ?int $locationId = null;
    public array $quantities = [];
    public array $serialNumberErrors = [];

    public function mount(
        $adjustedProducts = null,
        $locationId = null,
        $serial_numbers = null,
        $product_ids = null,
        $quantities_tax = null,
        $quantities_non_tax = null
    ): void {
        $this->locationId = $locationId;
        $this->products = [];
        $this->quantities = [];

        if ($adjustedProducts) {
            $this->hydrateFromExistingAdjustments($adjustedProducts);
            return;
        }

        if (!empty($product_ids)) {
            $this->hydrateFromOldInput(
                $product_ids,
                $serial_numbers,
                $quantities_tax,
                $quantities_non_tax
            );
        }
    }

    public function render(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        return view('livewire.adjustment.breakage-product-table');
    }

    public function productSelected($product): void
    {
        if (!$this->locationId) {
            session()->flash('message', 'Pilih lokasi terlebih dahulu sebelum menambahkan produk.');
            return;
        }

        if (collect($this->products)->contains('id', $product['id'])) {
            session()->flash('message', 'Produk sudah dipilih.');
            return;
        }

        $productStock = ProductStock::where('product_id', $product['id'])
            ->where('location_id', $this->locationId)
            ->first();

        if (!$productStock) {
            $productStock = new ProductStock([
                'product_id' => $product['id'],
                'location_id' => $this->locationId,
                'quantity' => 0,
                'quantity_tax' => 0,
                'quantity_non_tax' => 0,
                'broken_quantity_tax' => 0,
                'broken_quantity_non_tax' => 0,
            ]);
        }

        $productModel = Product::with('baseUnit')->find($product['id']);
        if (!$productModel) {
            session()->flash('message', 'Produk tidak ditemukan.');
            return;
        }

        $baseUnit = optional($productModel->baseUnit);

        $this->products[] = [
            'id' => $productModel->id,
            'product_name' => $productModel->product_name,
            'product_code' => $productModel->product_code,
            'serial_number_required' => (bool) $productModel->serial_number_required,
            'serial_numbers' => [],
            'unit' => $baseUnit->unit_name
                ?? $baseUnit->name
                ?? $baseUnit->short_name
                ?? '',
            'quantity_tax' => (int) ($productStock->quantity_tax ?? 0),
            'quantity_non_tax' => (int) ($productStock->quantity_non_tax ?? 0),
            'broken_quantity_tax' => (int) ($productStock->broken_quantity_tax ?? 0),
            'broken_quantity_non_tax' => (int) ($productStock->broken_quantity_non_tax ?? 0),
        ];

        $this->quantities[] = ['tax' => 0, 'non_tax' => 0];
        $this->serialNumberErrors[] = null;

        Log::info('[Breakage] Product added', ['product_id' => $productModel->id]);
    }

    public function removeProduct($index): void
    {
        unset($this->products[$index], $this->quantities[$index], $this->serialNumberErrors[$index]);

        $this->products = array_values($this->products);
        $this->quantities = array_values($this->quantities);
        $this->serialNumberErrors = array_values($this->serialNumberErrors);
    }

    public function locationSelected($locationId): void
    {
        if ($this->locationId !== $locationId) {
            $this->products = [];
            $this->quantities = [];
            $this->serialNumberErrors = [];
        }

        $this->locationId = $locationId;
    }

    public function serialNumberSelected($index, $serialNumber): void
    {
        if (!isset($this->products[$index]) || !$this->products[$index]['serial_number_required']) {
            return;
        }

        $currentSerials = collect($this->products[$index]['serial_numbers'] ?? []);
        if ($currentSerials->pluck('id')->contains($serialNumber['id'])) {
            $this->serialNumberErrors[$index] = "Serial number '{$serialNumber['serial_number']}' sudah ada.";
            return;
        }

        $serial = ProductSerialNumber::find($serialNumber['id']);
        if (!$serial) {
            $this->serialNumberErrors[$index] = 'Serial number tidak ditemukan.';
            return;
        }

        unset($this->serialNumberErrors[$index]);

        $entry = [
            'id' => $serial->id,
            'serial_number' => $serial->serial_number,
            'tax_id' => $serial->tax_id,
            'taxable' => (bool) $serial->tax_id,
        ];

        $this->products[$index]['serial_numbers'][] = $entry;

        if (!isset($this->quantities[$index])) {
            $this->quantities[$index] = ['tax' => 0, 'non_tax' => 0];
        }

        if ($serial->tax_id) {
            $this->quantities[$index]['tax']++;
        } else {
            $this->quantities[$index]['non_tax']++;
        }

        Log::info('[Breakage] Serial number added', ['product_index' => $index, 'serial_id' => $serial->id]);
    }

    public function removeSerialNumber($index, $serialIndex): void
    {
        if (!isset($this->products[$index]['serial_numbers'][$serialIndex])) {
            return;
        }

        $serial = $this->products[$index]['serial_numbers'][$serialIndex];
        $isTaxable = !empty($serial['tax_id']);

        unset($this->products[$index]['serial_numbers'][$serialIndex]);
        $this->products[$index]['serial_numbers'] = array_values($this->products[$index]['serial_numbers']);

        if (!isset($this->quantities[$index])) {
            $this->quantities[$index] = ['tax' => 0, 'non_tax' => 0];
        }

        if ($isTaxable) {
            $this->quantities[$index]['tax'] = max(0, $this->quantities[$index]['tax'] - 1);
        } else {
            $this->quantities[$index]['non_tax'] = max(0, $this->quantities[$index]['non_tax'] - 1);
        }
    }

    public function getTotalQuantityProperty(): array
    {
        return collect($this->quantities)
            ->map(fn ($row) => (int) ($row['tax'] ?? 0) + (int) ($row['non_tax'] ?? 0))
            ->toArray();
    }

    protected function hydrateFromExistingAdjustments($adjustedProducts): void
    {
        $this->hasAdjustments = true;

        foreach ($adjustedProducts as $adjustedProduct) {
            $productId = $adjustedProduct['product']['id'] ?? null;
            if (!$productId) {
                continue;
            }

            $product = Product::with('baseUnit')->find($productId);
            if (!$product) {
                continue;
            }

            $productStock = ProductStock::where('product_id', $productId)
                ->where('location_id', $this->locationId)
                ->first();

            $quantities = [
                'tax' => (int) ($adjustedProduct['quantity_tax'] ?? 0),
                'non_tax' => (int) ($adjustedProduct['quantity_non_tax'] ?? 0),
            ];

            if (($quantities['tax'] + $quantities['non_tax']) === 0) {
                $fallback = (int) ($adjustedProduct['quantity'] ?? 0);
                if (($adjustedProduct['is_taxable'] ?? false) && $fallback > 0) {
                    $quantities['tax'] = $fallback;
                } elseif ($fallback > 0) {
                    $quantities['non_tax'] = $fallback;
                }
            }

            $baseUnit = optional($product->baseUnit);

            $this->products[] = [
                'id' => $product->id,
                'product_name' => $product->product_name,
                'product_code' => $product->product_code,
                'serial_number_required' => (bool) $product->serial_number_required,
                'serial_numbers' => $this->getSerialNumberByIds($adjustedProduct['serial_number_ids'] ?? []),
                'unit' => $baseUnit->unit_name
                    ?? $baseUnit->name
                    ?? $baseUnit->short_name
                    ?? '',
                'quantity_tax' => optional($productStock)->quantity_tax ?? 0,
                'quantity_non_tax' => optional($productStock)->quantity_non_tax ?? 0,
                'broken_quantity_tax' => optional($productStock)->broken_quantity_tax ?? 0,
                'broken_quantity_non_tax' => optional($productStock)->broken_quantity_non_tax ?? 0,
            ];

            $this->quantities[] = $quantities;
        }
    }

    protected function hydrateFromOldInput($productIds, $serialNumbers, $quantitiesTax, $quantitiesNonTax): void
    {
        foreach ($productIds as $key => $productId) {
            $product = Product::with('baseUnit')->find($productId);
            if (!$product) {
                continue;
            }

            $productStock = ProductStock::where('product_id', $productId)
                ->where('location_id', $this->locationId)
                ->first();

            $baseUnit = optional($product->baseUnit);

            $this->products[] = [
                'id' => $product->id,
                'product_name' => $product->product_name,
                'product_code' => $product->product_code,
                'serial_number_required' => (bool) $product->serial_number_required,
                'serial_numbers' => $this->getSerialNumbers($serialNumbers, $key),
                'unit' => $baseUnit->unit_name
                    ?? $baseUnit->name
                    ?? $baseUnit->short_name
                    ?? '',
                'quantity_tax' => optional($productStock)->quantity_tax ?? 0,
                'quantity_non_tax' => optional($productStock)->quantity_non_tax ?? 0,
                'broken_quantity_tax' => optional($productStock)->broken_quantity_tax ?? 0,
                'broken_quantity_non_tax' => optional($productStock)->broken_quantity_non_tax ?? 0,
            ];

            $this->quantities[] = [
                'tax' => (int) ($quantitiesTax[$key] ?? 0),
                'non_tax' => (int) ($quantitiesNonTax[$key] ?? 0),
            ];
        }
    }

    protected function getProductQuantity($productId)
    {
        if ($this->locationId) {
            return Transaction::where('product_id', $productId)
                ->where('location_id', $this->locationId)
                ->groupBy('product_id', 'location_id')
                ->sum('quantity');
        }

        return 0;
    }

    protected function getSerialNumbers($serialNumbers, $key): array
    {
        if (empty($serialNumbers) || empty($serialNumbers[$key])) {
            return [];
        }

        return $this->getSerialNumberByIds($serialNumbers[$key]);
    }

    protected function getSerialNumberByIds($serialNumberIds): array
    {
        if (empty($serialNumberIds)) {
            return [];
        }

        return ProductSerialNumber::whereIn('id', $serialNumberIds)
            ->get(['id', 'serial_number', 'tax_id'])
            ->map(function ($serial) {
                return [
                    'id' => $serial->id,
                    'serial_number' => $serial->serial_number,
                    'tax_id' => $serial->tax_id,
                    'taxable' => (bool) $serial->tax_id,
                ];
            })
            ->toArray();
    }
}
