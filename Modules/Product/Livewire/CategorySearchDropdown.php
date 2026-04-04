<?php

namespace Modules\Product\Livewire;

use Livewire\Component;
use Modules\Product\Entities\Category;

class CategorySearchDropdown extends Component
{
    public int|string|null $selected = null;
    public string $name = 'category_id';
    public string $placeholder = 'Pilih kategori...';
    public string $search = '';
    public bool $open = false;
    public bool $rootOnly = false;
    public bool $allowCreate = false;
    public bool $clearable = false;
    public ?string $error = null;
    public ?string $dispatchTo = null;
    public string $modalEvent = 'openCategoryModal';

    /** @var array<int, array{id:int|string,name:string,parent_id:int|null,raw_name?:string}> */
    public array $options = [];
    public ?string $selectedLabel = null;

    protected $listeners = [
        'categoryCreated' => 'handleCategoryCreated',
    ];

    public function mount(
        array $options = [],
        int|string|null $selected = null,
        string $name = 'category_id',
        string $placeholder = 'Pilih kategori...',
        bool $rootOnly = false,
        bool $allowCreate = false,
        ?string $error = null,
        ?string $dispatchTo = null,
        bool $clearable = false,
        string $modalEvent = 'openCategoryModal'
    ): void {
        $this->name = $name;
        $this->placeholder = $placeholder;
        $this->rootOnly = $rootOnly;
        $this->allowCreate = $allowCreate;
        $this->clearable = $clearable;
        $this->error = $error;
        $this->dispatchTo = $dispatchTo;
        $this->modalEvent = $modalEvent;

        $this->options = $this->prepareOptions($options);
        if (!count($this->options)) {
            $this->options = $this->fetchCategories();
        }

        $this->selected = $selected ?: null;
        $this->selectedLabel = $this->resolveLabel($this->selected);
    }

    public function render()
    {
        return view('livewire.modules.product.category-search-dropdown');
    }

    public function toggleDropdown(): void
    {
        $this->open = !$this->open;
        if ($this->open) {
            $this->search = '';
        }
    }

    public function closeDropdown(): void
    {
        $this->open = false;
    }

    public function select(int|string $id): void
    {
        $this->selected = $id;
        $this->selectedLabel = $this->resolveLabel($id);
        $this->open = false;
        $this->search = '';

        $this->dispatchSelection();
    }

    public function clearSelection(): void
    {
        $this->selected = null;
        $this->selectedLabel = null;
        $this->open = false;
        $this->search = '';

        $this->dispatchSelection();
    }

    public function updatedSelected($value): void
    {
        $this->selectedLabel = $this->resolveLabel($value);
    }

    public function updatedSearch(): void
    {
        // no-op hook to satisfy Livewire updates
    }

    /**
     * @return array<int, array{id:int|string,name:string,parent_id:int|null,raw_name?:string}>
     */
    public function getFilteredOptionsProperty(): array
    {
        if ($this->search === '') {
            return $this->options;
        }

        $keyword = mb_strtolower($this->search);

        return array_values(array_filter($this->options, function ($option) use ($keyword) {
            return mb_stripos($option['name'], $keyword) !== false
                || (isset($option['raw_name']) && mb_stripos($option['raw_name'], $keyword) !== false);
        }));
    }

    public function handleCategoryCreated(array $category): void
    {
        if ($this->rootOnly && !empty($category['parent_id'])) {
            return;
        }

        $option = [
            'id' => $category['id'] ?? null,
            'name' => $this->formatCategoryName($category),
            'parent_id' => $category['parent_id'] ?? null,
            'raw_name' => $category['category_name'] ?? ($category['name'] ?? null),
        ];

        $this->upsertOption($option);
        if ($option['id'] !== null) {
            $this->select($option['id']);
        }
    }

    private function resolveLabel(int|string|null $id): ?string
    {
        if (!$id) {
            return null;
        }

        foreach ($this->options as $option) {
            if ((string) $option['id'] === (string) $id) {
                return $option['name'];
            }
        }

        $category = Category::query()->with('parent')->find($id);
        if (!$category) {
            return null;
        }

        $option = [
            'id' => $category->id,
            'name' => $category->parent && !$this->rootOnly
                ? "{$category->parent->category_name} | {$category->category_name}"
                : $category->category_name,
            'parent_id' => $category->parent_id,
            'raw_name' => $category->category_name,
        ];

        $this->upsertOption($option);

        return $option['name'];
    }

    private function prepareOptions(array $options): array
    {
        $normalized = $this->normalizeOptions($options);

        if ($this->rootOnly) {
            $normalized = array_values(array_filter($normalized, fn ($option) => ($option['parent_id'] ?? null) === null));
        }

        return $this->dedupeById($normalized);
    }

    private function fetchCategories(): array
    {
        $settingId = session('setting_id');

        $query = Category::query()
            ->with('parent')
            ->when($this->rootOnly, fn ($q) => $q->whereNull('parent_id'))
            ->when($settingId, fn ($q) => $q->where('setting_id', $settingId))
            ->orderBy('category_name');

        return $query->get()->map(function (Category $category) {
            $label = $category->parent && !$this->rootOnly
                ? "{$category->parent->category_name} | {$category->category_name}"
                : $category->category_name;

            return [
                'id' => $category->id,
                'name' => $label,
                'parent_id' => $category->parent_id,
                'raw_name' => $category->category_name,
            ];
        })->all();
    }

    private function normalizeOptions(array $options): array
    {
        $normalized = [];

        foreach ($options as $key => $value) {
            $id = null;
            $label = null;
            $parentId = null;
            $rawName = null;

            if (is_array($value)) {
                $id = $value['id'] ?? $key;
                $label = $value['name'] ?? $value['display_name'] ?? $value['category_name'] ?? null;
                $parentId = $value['parent_id'] ?? null;
                $rawName = $value['category_name'] ?? $label;
            } else {
                $id = $key;
                $label = (string) $value;
                $rawName = $label;
            }

            if ($id === null || $label === null) {
                continue;
            }

            $normalized[] = [
                'id' => is_numeric($id) ? (int) $id : $id,
                'name' => $label,
                'parent_id' => $parentId !== null ? (int) $parentId : null,
                'raw_name' => $rawName,
            ];
        }

        return $normalized;
    }

    private function upsertOption(array $option): void
    {
        if (($option['id'] ?? null) === null || ($option['name'] ?? null) === null) {
            return;
        }

        $foundIndex = null;
        foreach ($this->options as $index => $item) {
            if ((string) $item['id'] === (string) $option['id']) {
                $foundIndex = $index;
                break;
            }
        }

        if ($foundIndex !== null) {
            $this->options[$foundIndex] = array_merge($this->options[$foundIndex], $option);
        } else {
            $this->options[] = $option;
        }

        $this->options = $this->dedupeById($this->options);
    }

    private function dedupeById(array $options): array
    {
        $seen = [];
        $deduped = [];

        foreach ($options as $option) {
            $key = (string) $option['id'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $deduped[] = $option;
        }

        return $deduped;
    }

    private function dispatchSelection(): void
    {
        if (!$this->dispatchTo) {
            return;
        }

        $event = $this->dispatch('categoryDropdownSelected', name: $this->name, value: $this->selected);
        if (method_exists($event, 'to')) {
            $event->to($this->dispatchTo);
        }
    }

    private function formatCategoryName(array $category): string
    {
        $name = $category['category_name'] ?? $category['name'] ?? '';
        $parentName = $category['parent_name'] ?? null;
        $parentId = $category['parent_id'] ?? null;

        if ($parentId !== null && $parentName) {
            return "{$parentName} | {$name}";
        }

        return $name;
    }
}
