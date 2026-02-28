<?php

namespace Modules\Pos\Livewire;

use Livewire\Component;
use Livewire\Attributes\Modelable;
use Livewire\Attributes\Reactive;
use Modules\Pos\Entities\PosTerminal;

class PosTerminalSearchDropdown extends Component
{
    #[Modelable]
    public int|string|null $selected = null;
    public string $name = 'terminal_id';
    public string $placeholder = 'Pilih terminal...';
    public string $search = '';
    public bool $isOpen = false;
    #[Reactive]
    public ?string $error = null;

    /** @var array<int, array{id:int|string,name:string}> */
    public array $options = [];
    public ?string $selectedLabel = null;

    public function mount(
        int|string|null $selected = null,
        string $name = 'terminal_id',
        string $placeholder = 'Pilih terminal...',
        ?string $error = null
    ): void {
        $this->name = $name;
        $this->placeholder = $placeholder;
        $this->error = $error;

        $this->options = $this->fetchTerminals();
        $this->selected = $selected ?: null;
        $this->selectedLabel = $this->resolveLabel($this->selected);
    }

    public function render()
    {
        return view('livewire.modules.pos.pos-terminal-search-dropdown');
    }

    public function toggleDropdown(): void
    {
        $this->isOpen = !$this->isOpen;
        if ($this->isOpen) {
            $this->search = '';
        }
    }

    public function closeDropdown(): void
    {
        $this->isOpen = false;
    }

    public function select(int|string $id): void
    {
        $this->selected = $id;
        $this->selectedLabel = $this->resolveLabel($id);
        $this->isOpen = false;
        $this->search = '';
    }

    public function updatedSelected($value): void
    {
        $this->selectedLabel = $this->resolveLabel($value);
    }

    /**
     * @return array<int, array{id:int|string,name:string}>
     */
    public function getFilteredOptionsProperty(): array
    {
        if ($this->search === '') {
            return $this->options;
        }

        $keyword = mb_strtolower($this->search);

        return array_values(array_filter($this->options, function ($option) use ($keyword) {
            return mb_stripos($option['name'], $keyword) !== false;
        }));
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

        // Fallback for an ID not in current options
        $terminal = PosTerminal::query()
            ->where('setting_id', session('setting_id'))
            ->find($id);

        if (!$terminal) {
            return null;
        }

        $name = "{$terminal->code} - {$terminal->name}";
        $this->upsertOption([
            'id' => $terminal->id,
            'name' => $name,
        ]);

        return $name;
    }

    /**
     * @return array<int, array{id:int|string,name:string}>
     */
    private function fetchTerminals(): array
    {
        $settingId = (int) session('setting_id');

        return PosTerminal::query()
            ->where('setting_id', $settingId)
            ->where('is_active', true)
            ->orderBy('code')
            ->get()
            ->map(fn (PosTerminal $terminal) => [
                'id' => $terminal->id,
                'name' => "{$terminal->code} - {$terminal->name}",
            ])
            ->all();
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
    }
}
