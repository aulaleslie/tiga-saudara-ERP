<?php

namespace App\Livewire\Utils;

use Livewire\Component;
use Spatie\Tags\Tag;

class TagSelector extends Component
{
    public string $query = '';
    public array $selectedTags = [];
    public array $suggestions = [];

    public function mount(array $initialTags = []): void
    {
        $this->selectedTags = $initialTags;
    }

    public function updatedQuery()
    {
        if (strlen($this->query) < 2) {
            $this->suggestions = [];
            return;
        }

        $this->suggestions = Tag::where('name->en', 'LIKE', '%' . $this->query . '%')
            ->limit(5)
            ->pluck('name')
            ->toArray();
    }

    public function selectTag($tag): void
    {
        if (!in_array($tag, $this->selectedTags)) {
            $this->selectedTags[] = $tag;
            $this->dispatch('tagsUpdated', $this->selectedTags);
        }

        $this->reset(['query', 'suggestions']);
    }

    public function removeTag($tag): void
    {
        $this->selectedTags = array_filter(
            $this->selectedTags,
            fn ($t) => $t !== $tag
        );

        $this->dispatch('tagsUpdated', $this->selectedTags);
    }

    public function createTag()
    {
        $tag = Tag::findOrCreate($this->query, 'en');
        $this->selectTag($tag->name);
    }

    public function resetQuery()
    {
        $this->query = '';
        $this->suggestions = [];
    }

    public function render()
    {
        return view('livewire.utils.tag-selector');
    }
}
