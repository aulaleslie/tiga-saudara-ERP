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

class AdjustmentProductTable extends Component
{
    protected $listeners = ['productSelected', 'serialNumberSelected', 'locationSelected'];

    public $products;
    public $hasAdjustments;
    public $locationId;
    public $quantities;
    public $serialNumberErrors = [];

    public function mount(
        $adjustedProducts = null,
        $locationId = null,
        $serial_numbers = null,
        $is_taxables = null,
        $product_ids = null,
        $quantities = null
    ): void
    {
        $this->products = [];
        $this->locationId = $locationId;
        $this->quantities = $quantities ?? []; // Ensure quantities is set

        if ($adjustedProducts) {
            Log::debug('Adjustment Product Table', [
                'adjustedProducts' => $adjustedProducts,
            ]);
            $this->hasAdjustments = true;
            $this->products = array_map(function ($adjustedProduct) {
                // Fetch product stock by product ID & location
                $productStock = ProductStock::where('product_id', $adjustedProduct['product']['id'])
                    ->where('location_id', $this->locationId)
                    ->first();

                return [
                    'id' => $adjustedProduct['product']['id'],
                    'product_name' => $adjustedProduct['product']['product_name'],
                    'product_code' => $adjustedProduct['product']['product_code'],
                    'serial_number_required' => $adjustedProduct['product']['serial_number_required'],
                    'serial_numbers' => $this->mapStoredSerialNumbers(
                        is_string($adjustedProduct['serial_numbers'])
                            ? json_decode($adjustedProduct['serial_numbers'], true)
                            : $adjustedProduct['serial_numbers']
                    ),
                    'unit' => $adjustedProduct['product']['base_unit']['name'] ?? '',
                    'quantity' => $adjustedProduct['quantity'], // Assign existing quantity
                    'quantity_tax' => $productStock->quantity_tax ?? 0,
                    'quantity_non_tax' => $productStock->quantity_non_tax ?? 0,
                    'broken_quantity_tax' => $productStock->broken_quantity_tax ?? 0,
                    'broken_quantity_non_tax' => $productStock->broken_quantity_non_tax ?? 0,
                    'is_taxable' => $adjustedProduct['is_taxable'] ?? 0,
                ];
            }, $adjustedProducts);
        } elseif (!empty($product_ids) && !empty($quantities)) {
            // Restore product selection from validation error
            foreach ($product_ids as $key => $product_id) {
                $product = Product::find($product_id);
                $productStock = ProductStock::where('product_id', $product_id)
                    ->where('location_id', $this->locationId)
                    ->first();

                if ($product) {
                    $this->products[] = [
                        'id' => $product->id,
                        'product_name' => $product->product_name,
                        'product_code' => $product->product_code,
                        'serial_number_required' => $product->serial_number_required,
                        'serial_numbers' => $this->getSerialNumbers($serial_numbers, $key), // Keep selected serials
                        'unit' => $product->baseUnit->unit_name ?? '',
                        'quantity' => $quantities[$key] ?? 1, // Ensure quantity is set
                        'quantity_tax' => $productStock->quantity_tax ?? 0,
                        'quantity_non_tax' => $productStock->quantity_non_tax ?? 0,
                        'broken_quantity_tax' => $productStock->broken_quantity_tax ?? 0,
                        'broken_quantity_non_tax' => $productStock->broken_quantity_non_tax ?? 0,
                        'is_taxable' => $is_taxables[$key] ?? 0,
                    ];
                }
            }
        } else {
            $this->hasAdjustments = false;
        }
    }

    protected function mapStoredSerialNumbers(array $storedSerials): array
    {
        if (empty($storedSerials)) {
            return [];
        }

        $ids = collect($storedSerials)->pluck('id')->toArray();

        $serials = ProductSerialNumber::whereIn('id', $ids)
            ->get(['id', 'serial_number', 'tax_id'])
            ->keyBy('id');

        return collect($storedSerials)->map(function ($item) use ($serials) {
            $serial = $serials[$item['id']] ?? null;

            return [
                'id' => (int) $item['id'],
                'serial_number' => $serial?->serial_number ?? '',
                'tax_id' => $serial?->tax_id,
                'taxable' => (bool) ($item['taxable'] ?? ($serial?->tax_id ? 1 : 0)),
            ];
        })->toArray();
    }

    public function render(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        return view('livewire.adjustment.adjustment-product-table');
    }

    public function productSelected($product): void
    {
        Log::info('product', $product);

        // Ensure location is selected
        if (!$this->locationId) {
            session()->flash('message', 'Pilih lokasi terlebih dahulu sebelum menambahkan produk.');
            return;
        }

        // Prevent duplicate selection
        if (collect($this->products)->contains('id', $product['id'])) {
            session()->flash('message', 'Produk sudah dipilih.');
            return;
        }

        // Fetch product stock by product ID & location
        $productStock = ProductStock::where('product_id', $product['id'])
            ->where('location_id', $this->locationId)
            ->first();

        // Ensure productStock exists, else default values
        if ($productStock) {
            $product['quantity'] = $productStock->quantity;
            $product['quantity_tax'] = $productStock->quantity_tax ?? 0;
            $product['quantity_non_tax'] = $productStock->quantity_non_tax ?? 0;
            $product['broken_quantity_tax'] = $productStock->broken_quantity_tax ?? 0;
            $product['broken_quantity_non_tax'] = $productStock->broken_quantity_non_tax ?? 0;
        } else {
            session()->flash('message', 'Stok produk tidak ditemukan untuk lokasi ini.');
            return;
        }

        // Retrieve product unit
        $productEntity = Product::with('baseUnit')->find($product['id']);
        $product['unit'] = $product['base_unit']['name'] ?? '';

        // Initialize empty serial numbers
        $product['serial_numbers'] = [];

        // Add to the product list
        $this->products[] = $product;
    }

    public function removeProduct($key): void
    {
        unset($this->products[$key]);
    }

    public function locationSelected($locationId): void
    {
        Log::info("Location selected: " . $locationId);

        if ($this->locationId !== $locationId) {
            $this->products = [];
        }

        $this->locationId = $locationId;
    }

    protected function updateProductQuantitiesByLocation(): void
    {
        foreach ($this->products as &$product) {
            $product['product_quantity'] = $this->getProductQuantity($product['id']);
        }
    }

    public function serialNumberSelected($index, $serialNumber)
    {
        if (!isset($this->products[$index]) || !$this->products[$index]['serial_number_required']) {
            return;
        }

        // Prevent duplicates
        if (collect($this->products[$index]['serial_numbers'] ?? [])
            ->pluck('id')->contains($serialNumber['id'])) {
            $this->serialNumberErrors[$index] = "Serial number '{$serialNumber['serial_number']}' sudah ada.";
            return;
        }

        // Clear error if passed
        unset($this->serialNumberErrors[$index]);

        // Fetch full serial record to check tax
        $serial = ProductSerialNumber::find($serialNumber['id']);
        if (!$serial) {
            Log::warning("Serial not found: {$serialNumber['id']}");
            return;
        }

        // Add tax_id to the serialNumber object before pushing to products
        $serialNumber['tax_id'] = $serial->tax_id;
        $serialNumber['taxable'] = (bool) $serial->tax_id;

        // Then push it
        $this->products[$index]['serial_numbers'][] = $serialNumber;

        // Adjust quantity
        if (!isset($this->quantities[$index])) {
            $this->quantities[$index] = ['tax' => 0, 'non_tax' => 0];
        }

        if ($serial->tax_id) {
            $this->quantities[$index]['tax']++;
        } else {
            $this->quantities[$index]['non_tax']++;
        }

        Log::info("Serial number added for row {$index}", ['serial_number' => $serialNumber]);
    }

    public function removeSerialNumber($index, $serialIndex)
    {
        if (isset($this->products[$index]['serial_numbers'][$serialIndex])) {
            unset($this->products[$index]['serial_numbers'][$serialIndex]);
            $this->products[$index]['serial_numbers'] = array_values($this->products[$index]['serial_numbers']);
            Log::info("Removed serial number at index {$serialIndex} for row {$index}");
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

    protected function getSerialNumbers($serial_numbers, $key)
    {
        if (empty($serial_numbers) || empty($serial_numbers[$key])) {
            return [];
        }

        return $this->getSerialNumberByIds($serial_numbers[$key]);
    }

    protected function getSerialNumberByIds($serialNumberIds)
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

    public function getTotalQuantityProperty(): array
    {
        return collect($this->quantities)
            ->map(function ($row) {
                $tax = is_numeric($row['tax'] ?? null) ? (int) $row['tax'] : 0;
                $nonTax = is_numeric($row['non_tax'] ?? null) ? (int) $row['non_tax'] : 0;
                return $tax + $nonTax;
            })
            ->toArray();
    }

    public function toggleSerialTaxable($productIndex, $serialIndex): void
    {
        $serial = &$this->products[$productIndex]['serial_numbers'][$serialIndex];

        if (!isset($this->quantities[$productIndex])) {
            $this->quantities[$productIndex] = ['tax' => 0, 'non_tax' => 0];
        }

        if (!array_key_exists('taxable', $serial)) {
            return;
        }

        if ($serial['taxable']) {
            // Checkbox is now checked -> move to Pajak
            $this->quantities[$productIndex]['tax']++;
            $this->quantities[$productIndex]['non_tax'] = max(0, $this->quantities[$productIndex]['non_tax'] - 1);
        } else {
            // Checkbox is now unchecked -> move to Non Pajak
            $this->quantities[$productIndex]['non_tax']++;
            $this->quantities[$productIndex]['tax'] = max(0, $this->quantities[$productIndex]['tax'] - 1);
        }
    }
}
