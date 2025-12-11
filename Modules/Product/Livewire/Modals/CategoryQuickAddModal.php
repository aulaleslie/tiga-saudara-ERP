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
        'openCategoryModal' => 'openModal',
        'categoryDropdownSelected' => 'onCategoryDropdownSelected',
    ];

    protected function rules()
    {
        return [
            'category_code' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('categories', 'category_code')
            ],
            'category_name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('categories', 'category_name')
                    ->where('setting_id', session('setting_id') ?? 1)
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

        $code = $this->category_code;

        if (!$code) {
            $categoryMaxId = Category::max('id') + 1;
            $code = 'CA_' . str_pad($categoryMaxId, 2, '0', STR_PAD_LEFT);
        }

        $category = Category::create([
            'category_code' => $code,
            'category_name' => $this->category_name,
            'parent_id' => $this->parent_id,
            'created_by' => Auth::id(),
            'setting_id' => session('setting_id') ?? 1
        ]);

        $parentName = optional($category->parent)->category_name;
        $displayName = $parentName
            ? "{$parentName} | {$category->category_name}"
            : $category->category_name;

        $this->dispatch('categoryCreated', [
            'id' => $category->id,
            'name' => $displayName,
            'display_name' => $displayName,
            'category_name' => $category->category_name,
            'parent_id' => $category->parent_id,
            'parent_name' => $parentName,
        ]);

        session()->flash('success', 'Kategori berhasil ditambahkan.');

        $this->closeModal();
    }

    public function onCategoryDropdownSelected(string $name, $value): void
    {
        if ($name === 'parent_id') {
            $this->parent_id = $value ?: null;
        }
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

    public function getParentCategoryOptionsProperty()
    {
        return $this->parentCategories
            ->map(fn ($category) => [
                'id' => $category->id,
                'name' => $category->category_name,
                'parent_id' => $category->parent_id,
                'category_name' => $category->category_name,
            ])
            ->all();
    }

    public function render()
    {
        return view('livewire.modals.category-quick-add-modal');
    }
}
