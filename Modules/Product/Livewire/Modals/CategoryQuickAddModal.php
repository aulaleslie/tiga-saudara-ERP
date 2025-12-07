<?php

namespace Modules\Product\Livewire\Modals;

use Livewire\Component;
use Modules\Product\Entities\Category;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;

class CategoryQuickAddModal extends Component
{
    public $showModal = false;
    public $category_code = '';
    public $category_name = '';
    public $parent_id = null;

    protected $listeners = [
        'openCategoryModal' => 'openModal'
    ];

    protected function rules()
    {
        return [
            'category_code' => [
                'required',
                'string',
                'max:255',
                Rule::unique('categories', 'category_code')
            ],
            'category_name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('categories', 'category_name')
            ],
            'parent_id' => 'nullable|exists:categories,id'
        ];
    }

    protected function validationAttributes()
    {
        return [
            'category_code' => 'kode kategori',
            'category_name' => 'nama kategori',
            'parent_id' => 'kategori induk'
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

        $category = Category::create([
            'category_code' => $this->category_code,
            'category_name' => $this->category_name,
            'parent_id' => $this->parent_id,
            'created_by' => Auth::id(),
            'setting_id' => session('setting_id') ?? 1
        ]);

        $this->dispatch('categoryCreated', [
            'id' => $category->id,
            'name' => $category->category_name
        ]);

        session()->flash('success', 'Kategori berhasil ditambahkan.');

        $this->closeModal();
    }

    private function resetForm()
    {
        $this->category_code = '';
        $this->category_name = '';
        $this->parent_id = null;
        $this->resetValidation();
    }

    public function getParentCategoriesProperty()
    {
        return Category::whereNull('parent_id')
            ->where('setting_id', session('setting_id') ?? 1)
            ->orderBy('category_name')
            ->get();
    }

    public function render()
    {
        return view('livewire.modals.category-quick-add-modal');
    }
}