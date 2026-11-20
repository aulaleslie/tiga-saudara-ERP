<?php

namespace Modules\Product\Livewire\Modals;

use Livewire\Component;
use Modules\Product\Entities\Brand;
use Modules\Product\Services\ProductTaxonomyResolver;
use Illuminate\Support\Facades\Auth;

class BrandQuickAddModal extends Component
{
    public $showModal = false;
    public $name = '';
    public $description = '';
    public $listenEvent = 'openBrandModal';

    public function getListeners()
    {
        return [
            $this->listenEvent => 'openModal',
        ];
    }

    protected function rules()
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
            ],
            'description' => 'nullable|string|max:1000'
        ];
    }

    protected function validationAttributes()
    {
        return [
            'name' => 'nama merek',
            'description' => 'deskripsi'
        ];
    }

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

    public function save()
    {
        $this->validate();

        $brand = app(ProductTaxonomyResolver::class)->createBrand(
            (string) $this->name,
            $this->description,
            Auth::id()
        );

        $this->dispatch('brandCreated', [
            'id' => $brand->id,
            'name' => $brand->name
        ]);

        session()->flash('success', 'Merek berhasil ditambahkan.');

        $this->closeModal();
    }

    private function resetForm()
    {
        $this->name = '';
        $this->description = '';
        $this->resetValidation();
    }

    public function render()
    {
        return view('livewire.modals.brand-quick-add-modal');
    }
}
