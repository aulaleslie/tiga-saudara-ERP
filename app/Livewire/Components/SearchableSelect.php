<?php

namespace App\Livewire\Components;

use Livewire\Component;
use Illuminate\Database\Eloquent\Model;

class SearchableSelect extends Component
{
    public $query = '';
    public $searchResults = [];
    public $selectedValue = null;
    public $selectedLabel = '';
    public $isFocused = false;
    public $isLoading = false;
    public $placeholder = 'Cari...';
    public $label = '';
    public $name = '';
    public $required = false;
    public $disabled = false;
    public $modelClass = '';
    public $searchField = 'name';
    public $displayField = 'name';
    public $valueField = 'id';
    public $additionalWhere = [];
    public $limit = 20;
    public $minQueryLength = 1;
    public $selected = null;
    public $quickAddButton = null;
    public $quickAddEntity = null;
    public $quickAddPermission = null;
    public $quickAddModalEvent = null;
    public $quickAddTooltip = null;
    public $listenForCreatedEvent = null;

    protected function getListeners()
    {
        $listeners = [
            'clearSelection' => 'clearSelection',
            'setSelectedValue' => 'setSelectedValue'
        ];

        if ($this->listenForCreatedEvent) {
            $listeners[$this->listenForCreatedEvent] = 'handleCreatedEvent';
        }

        return $listeners;
    }

    public function mount()
    {
        $this->searchResults = $this->getInitialResults();
        $this->setSelectedValue($this->selected);
    }

    public function updatedSelected($value)
    {
        $this->setSelectedValue($value);
        $this->searchResults = $this->getInitialResults();
    }

    public function updatedQuery()
    {
        if (strlen($this->query) >= $this->minQueryLength) {
            $this->isLoading = true;
            $this->searchResults = $this->performSearch();
            $this->isLoading = false;
        } else {
            $this->searchResults = $this->getInitialResults();
        }
    }

    public function updatedIsFocused()
    {
        if (!$this->isFocused) {
            // Reset to initial results when losing focus
            $this->searchResults = $this->getInitialResults();
        }
    }

    public function selectItem($id, $label)
    {
        $this->selectedValue = $id;
        $this->selectedLabel = $label;
        $this->query = $label;
        $this->isFocused = false;
        $this->searchResults = $this->getInitialResults();

        $this->dispatch('itemSelected', [
            'name' => $this->name,
            'value' => $id,
            'label' => $label
        ]);
    }

    public function clearSelection()
    {
        $this->selectedValue = null;
        $this->selectedLabel = '';
        $this->query = '';
        $this->searchResults = $this->getInitialResults();
        $this->dispatch('itemSelected', [
            'name' => $this->name,
            'value' => null,
            'label' => null,
        ]);
    }

    public function setSelectedValue($value, $label = null)
    {
        // Support payload-style events: ['name' => 'field', 'value' => 1, 'label' => 'Net 30']
        if (is_array($value)) {
            $payload = $value;
            $label = $payload['label'] ?? null;
            $name = $payload['name'] ?? null;
            if ($name && $name !== $this->name) {
                return;
            }
            $value = $payload['value'] ?? null;
        }

        $this->selected = $value;
        $this->selectedValue = $value;
        if ($label) {
            $this->selectedLabel = $label;
            $this->query = $label;
        } elseif ($value && $this->modelClass) {
            $model = $this->modelClass::find($value);
            if ($model) {
                $this->selectedLabel = $model->{$this->displayField};
                $this->query = $this->selectedLabel;
            }
        } else {
            $this->selectedLabel = '';
            $this->query = '';
        }
    }

    public function handleCreatedEvent($data)
    {
        $this->setSelectedValue($data['id'], $data['name'] ?? null);
        $this->searchResults = $this->getInitialResults();
    }

    private function performSearch()
    {
        if (!$this->modelClass) {
            return [];
        }

        $query = $this->modelClass::query()
            ->where($this->searchField, 'like', '%' . $this->query . '%');

        foreach ($this->additionalWhere as $field => $value) {
            $query->where($field, $value);
        }

        return $query->limit($this->limit)
            ->get([$this->valueField, $this->displayField])
            ->map(function ($item) {
                return [
                    'id' => $item->{$this->valueField},
                    'label' => $item->{$this->displayField}
                ];
            })
            ->toArray();
    }

    private function getInitialResults()
    {
        if (!$this->modelClass) {
            return [];
        }

        $query = $this->modelClass::query();

        foreach ($this->additionalWhere as $field => $value) {
            $query->where($field, $value);
        }

        $results = $query->limit(10)
            ->get([$this->valueField, $this->displayField])
            ->map(function ($item) {
                return [
                    'id' => $item->{$this->valueField},
                    'label' => $item->{$this->displayField}
                ];
            })
            ->toArray();

        // Ensure the selected item is included if it exists
        if ($this->selectedValue) {
            $exists = collect($results)->contains('id', $this->selectedValue);
            if (!$exists) {
                $model = $this->modelClass::find($this->selectedValue);
                if ($model) {
                    array_unshift($results, [
                        'id' => $model->{$this->valueField},
                        'label' => $model->{$this->displayField}
                    ]);
                }
            }
        }

        return $results;
    }

    public function render()
    {
        return view('livewire.components.searchable-select');
    }
}
