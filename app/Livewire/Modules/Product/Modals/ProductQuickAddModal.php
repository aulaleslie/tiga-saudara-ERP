<?php

namespace App\Livewire\Modules\Product\Modals;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Livewire\Component;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Brand;
use Modules\Setting\Entities\Unit;

class ProductQuickAddModal extends Component
{
    public $showModal = false;
    public $product_name;
    public $product_code;
    public $category_id;
    public $brand_id;
    public $unit_id;
    public $description;
    public $is_sold = false;
    public $is_purchased = false;

    public $listeners = [
        'openProductModal' => 'openModal',
    ];

    public function openModal()
    {
        $this->resetForm();
        $this->showModal = true;
    }

    public function closeModal()
    {
        $this->showModal = false;
        $this->resetForm();
    }

    private function resetForm()
    {
        $this->product_name = '';
        $this->product_code = '';
        $this->category_id = '';
        $this->brand_id = '';
        $this->unit_id = '';
        $this->description = '';
        $this->is_sold = false;
        $this->is_purchased = false;
    }

    public function save()
    {
        $this->validate([
            'product_name' => 'required|string|max:255',
            'product_code' => 'nullable|string|max:255|unique:products',
            'category_id' => 'nullable|exists:categories,id',
            'brand_id' => 'nullable|exists:brands,id',
            'unit_id' => 'required|exists:units,id',
            'description' => 'nullable|string',
            'is_sold' => 'boolean',
            'is_purchased' => 'boolean',
        ]);

        try {
            $product = Product::create([
                'product_name' => $this->product_name,
                'product_code' => $this->product_code ?: 'AUTO-' . strtoupper(uniqid()),
                'category_id' => $this->category_id,
                'brand_id' => $this->brand_id,
                'unit_id' => $this->unit_id,
                'description' => $this->description,
                'is_sold' => $this->is_sold,
                'is_purchased' => $this->is_purchased,
                'stock_managed' => true, // Enable stock management by default for purchases
            ]);

            $this->dispatch('productCreated', $product->toArray());
            $this->closeModal();

            session()->flash('success', 'Produk berhasil ditambahkan!');
        } catch (\Exception $e) {
            session()->flash('error', 'Gagal menambahkan produk: ' . $e->getMessage());
        }
    }

    public function render(): Factory|Application|View|\Illuminate\View\View|\Illuminate\Contracts\Foundation\Application
    {
        return view('livewire.modules.product.modals.product-quick-add-modal', [
            'categories' => Category::all(),
            'brands' => Brand::all(),
            'units' => Unit::all(),
        ]);
    }
}