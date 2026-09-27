<?php

declare(strict_types=1);

namespace Modules\Setting\Livewire;

use Livewire\Attributes\Modelable;
use Livewire\Attributes\Reactive;
use Livewire\Component;
use Modules\Setting\Entities\Location;

class MultiLocationSearchDropdown extends Component
{
    /** @var array<int, int|string> */
    #[Modelable]
    public array $selected = [];

    public string $name = 'location_ids';
    public string $placeholder = 'Pilih lokasi stok opname...';
    public string $search = '';
    public bool $open = false;

    #[Reactive]
    public ?string $error = null;
    public int $zIndex = 1050;

    public ?string $dispatchTo = null;
    public ?string $formName = null;

    /** @var array<int, array{id:int,name:string,setting_id:int,setting_name:string,is_pkp:bool}> */
    public array $options = [];

    protected $listeners = [
        'setSelectedLocations' => 'handleSetSelectedLocations',
        'addSelectedLocation' => 'addLocation',
        'removeSelectedLocation' => 'removeLocation',
    ];

    public function mount(
        array $selected = [],
        string $name = 'location_ids',
        string $placeholder = 'Pilih lokasi stok opname...',
        ?string $error = null,
        ?string $dispatchTo = null,
        ?string $formName = null,
        int $zIndex = 1050
    ): void {
        $this->name = $name;
        $this->placeholder = $placeholder;
        $this->error = $error;
        $this->dispatchTo = $dispatchTo;
        $this->formName = $formName;
        $this->zIndex = $zIndex;

        $this->options = $this->fetchLocations();

        $validSelected = [];
        foreach ($selected as $id) {
            if ($this->isLocationEligible($id) && !in_array((int) $id, $validSelected, true)) {
                $validSelected[] = (int) $id;
            }
        }
        $this->selected = $validSelected;
    }

    public function render()
    {
        return view('livewire.modules.setting.multi-location-search-dropdown');
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
        $this->addLocation($id);
    }

    public function addLocation(int|string $id): void
    {
        $numericId = (int) $id;

        if (!$this->isLocationEligible($numericId)) {
            $this->dispatch('location-dropdown-selection-rejected', name: $this->name, value: $id, reason: 'ineligible');
            return;
        }

        if (in_array($numericId, array_map('intval', $this->selected), true)) {
            $this->dispatch('location-dropdown-selection-rejected', name: $this->name, value: $id, reason: 'duplicate');
            return;
        }

        $this->selected[] = $numericId;
        $this->selected = array_values(array_unique($this->selected));
        $this->open = false;
        $this->search = '';

        $this->dispatchSelection();
    }

    public function removeLocation(int|string $id): void
    {
        $numericId = (int) $id;
        $this->selected = array_values(array_filter(
            $this->selected,
            fn ($item) => (int) $item !== $numericId
        ));

        $this->dispatchSelection();
    }

    public function handleSetSelectedLocations(array $locationIds): void
    {
        $valid = [];
        foreach ($locationIds as $id) {
            $numId = (int) $id;
            if ($this->isLocationEligible($numId) && !in_array($numId, $valid, true)) {
                $valid[] = $numId;
            }
        }

        $this->selected = $valid;
        $this->open = false;
        $this->search = '';
        $this->dispatchSelection();
    }

    /**
     * Check if a location is eligible for stock opname selection:
     * Must exist, be active, and not be consignment.
     * Allowed across all business settings.
     */
    public function isLocationEligible(int|string|null $id): bool
    {
        if (!$id) {
            return false;
        }

        return Location::query()
            ->whereKey($id)
            ->where('is_active', true)
            ->where('is_consignment', false)
            ->exists();
    }

    /**
     * @return array<int, array{id:int,name:string,setting_id:int,setting_name:string,is_pkp:bool}>
     */
    public function getFilteredOptionsProperty(): array
    {
        $selectedSet = array_flip(array_map('intval', $this->selected));

        $available = array_values(array_filter(
            $this->options,
            fn ($opt) => !isset($selectedSet[(int) $opt['id']])
        ));

        if ($this->search === '') {
            return $available;
        }

        $keyword = mb_strtolower($this->search);

        return array_values(array_filter($available, function ($option) use ($keyword) {
            return mb_stripos($option['name'], $keyword) !== false;
        }));
    }

    /**
     * @return array<int, array{id:int,name:string}>
     */
    public function getSelectedLocationsProperty(): array
    {
        $optionsById = collect($this->options)->keyBy('id');

        $result = [];
        foreach ($this->selected as $id) {
            $numId = (int) $id;
            $opt = $optionsById->get($numId);
            if ($opt) {
                $result[] = [
                    'id' => $numId,
                    'name' => $opt['name'],
                ];
            } else {
                // If not in pre-fetched options (rare), fetch directly
                $loc = Location::with('setting')->find($numId);
                if ($loc) {
                    $result[] = [
                        'id' => $numId,
                        'name' => $this->formatLabel($loc),
                    ];
                }
            }
        }

        return $result;
    }

    /**
     * @return array<int, array{id:int,name:string,setting_id:int,setting_name:string,is_pkp:bool}>
     */
    private function fetchLocations(): array
    {
        return Location::query()
            ->where('is_active', true)
            ->where('is_consignment', false)
            ->with('setting')
            ->orderBy('name')
            ->get()
            ->map(fn (Location $location) => [
                'id' => (int) $location->id,
                'name' => $this->formatLabel($location),
                'setting_id' => (int) ($location->setting_id ?? 0),
                'setting_name' => (string) ($location->setting?->company_name ?? ''),
                'is_pkp' => (bool) ($location->setting?->is_pkp ?? false),
            ])
            ->all();
    }

    private function formatLabel(Location $location): string
    {
        if ($location->setting?->company_name) {
            return "{$location->name} ({$location->setting->company_name})";
        }

        return (string) $location->name;
    }

    private function dispatchSelection(): void
    {
        $event = $this->dispatch('locationsSelected', name: $this->name, values: $this->selected);
        if ($this->dispatchTo && method_exists($event, 'to')) {
            $event->to($this->dispatchTo);
        }
    }
}
