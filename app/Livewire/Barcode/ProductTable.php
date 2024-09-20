<?php

namespace App\Livewire\Barcode;

use Barryvdh\Snappy\Facades\SnappyPdf;
use Livewire\Component;
use Milon\Barcode\Facades\DNS1DFacade;
use Modules\Product\Entities\Product;

class ProductTable extends Component
{
    public $product;
    public $quantity;
    public $barcodes;

    protected $listeners = ['productSelected'];

    public function mount()
    {
        $this->product = '';
        $this->quantity = 0;
        $this->barcodes = [];
    }

    public function render()
    {
        return view('livewire.barcode.product-table');
    }

    public function productSelected(Product $product)
    {
        $this->product = $product;
        $this->quantity = 1;
        $this->barcodes = [];
    }

    public function generateBarcodes(Product $product, $quantity)
    {
        if ($quantity > 100) {
            return session()->flash('message', 'Max quantity is 100 per barcode generation!');
        }

        // Check if the barcode is a valid EAN13
        if (!preg_match('/^[0-9]{13}$/', $product->barcode)) {
            $this->barcodes = [];
            return session()->flash('message', 'Invalid Barcode. Please update the product\'s barcode to a valid EAN13 format.');
        }

        $this->barcodes = [];

        for ($i = 1; $i <= $quantity; $i++) {
            $barcode = DNS1DFacade::getBarCodeSVG($product->barcode, 'EAN13', 2, 60, 'black', false);
            array_push($this->barcodes, $barcode);
        }
    }

    public function getPdf()
    {
        $pdf = SnappyPdf::loadView('product::barcode.print', [
            'barcodes' => $this->barcodes,
            'price' => $this->product->sale_price,
            'name' => $this->product->product_name,
        ]);
        return $pdf->stream('barcodes-' . $this->product->product_code . '.pdf');
    }

    public function updatedQuantity()
    {
        $this->barcodes = [];
    }

    public function updateBarcode()
    {
        // Ensure the product is set
        if (!$this->product) {
            return session()->flash('message', 'No product selected.');
        }

        // Ensure the barcode is present
        if (empty($this->product->barcode)) {
            return session()->flash('message', 'Please enter a barcode.');
        }

        // Check if the barcode is valid (EAN13 format)
        if (!preg_match('/^[0-9]{13}$/', $this->product->barcode)) {
            return session()->flash('message', 'The barcode must be a valid 13-digit number (EAN13).');
        }

        // Update the product's barcode and save it
        $this->product->save();

        session()->flash('message', 'Barcode updated successfully.');
    }
}
