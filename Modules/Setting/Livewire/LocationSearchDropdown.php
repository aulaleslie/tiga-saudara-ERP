<?php

namespace Modules\Setting\Livewire;

use Livewire\Component;
use Livewire\Attributes\Modelable;
use Livewire\Attributes\Reactive;
use Modules\Setting\Entities\Location;

class LocationSearchDropdown extends Component
{
    #[Modelable]
    public int|string|null $selected = null;
    public string $name = 'location_id';
    public string $placeholder = 'Pilih lokasi...';
    public string $search = '';
    public bool $open = false;
    public bool $allowCreate = false;
    #[Reactive]
    public ?string $error = null;
    public int $zIndex = 1050;
    public ?int $selectedSettingId = null;
    public ?string $consignmentFilter = null; // null | 'consignment' | 'standard'

    /**
     * When true (the default), search/selection is scoped to
     * $selectedSettingId (or the current session tenant) only. This matches
     * the component's original behavior, so existing callers (stock opname,
     * breakage, adjustment) are unaffected unless they opt out.
     */
    public bool $tenantScoped = true;

    /**
     * Explicit opt-out of tenant scoping: when true, results are not
     * restricted to the active/current tenant and may span multiple
     * businesses; labels then include the business name to disambiguate
     * duplicate location names. Setting this to true also sets
     * $tenantScoped to false during mount, since the two are mutually
     * exclusive scopes; a caller must not pass both as true.
     */
    public bool $crossBusiness = false;

    /**
     * Location IDs to exclude from search results and from accepting a
     * selection (e.g. the already-selected origin, for a destination field).
     *
     * @var array<int, int>
     */
    public array $excludedLocationIds = [];

    /** @var array<int, array{id:int|string,name:string}> */
    public array $options = [];
    public ?string $selectedLabel = null;

    protected $listeners = [
        'locationCreated' => 'handleLocationCreated',
        'setSelectedLocation' => 'handleSetSelectedLocation',
    ];

    public ?string $dispatchTo = null;
    public ?string $formName = null;

    public function mount(
        array $options = [],
        int|string|null $selected = null,
        string $name = 'location_id',
        string $placeholder = 'Pilih lokasi...',
        bool $allowCreate = false,
        ?string $error = null,
        ?string $dispatchTo = null,
        ?string $formName = null,
        int $zIndex = 1050,
        ?int $selectedSettingId = null,
        ?string $consignmentFilter = null,
        bool $tenantScoped = true,
        bool $crossBusiness = false,
        array $excludedLocationIds = []
    ): void {
        $this->name = $name;
        $this->placeholder = $placeholder;
        $this->allowCreate = $allowCreate;
        $this->error = $error;
        $this->dispatchTo = $dispatchTo;
        $this->formName = $formName;
        $this->zIndex = $zIndex;
        $this->selectedSettingId = $selectedSettingId;
        $this->consignmentFilter = $consignmentFilter;
        // crossBusiness is an explicit opt-out of tenant scoping; a caller
        // that asks for cross-business search never stays tenant-scoped,
        // regardless of what it passed for tenantScoped.
        $this->crossBusiness = $crossBusiness;
        $this->tenantScoped = $crossBusiness ? false : $tenantScoped;
        $this->excludedLocationIds = array_values(array_filter(array_map('intval', $excludedLocationIds)));

        $this->options = $this->prepareOptions($options);
        if (!count($this->options)) {
            $this->options = $this->fetchLocations();
        }

        $this->selected = $this->isSelectionInScope($selected) ? $selected : null;
        $this->selectedLabel = $this->resolveLabel($this->selected);
    }

    public function render()
    {
        return view('livewire.modules.setting.location-search-dropdown');
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
        if (!$this->isSelectionInScope($id)) {
            return;
        }

        $this->selected = $id;
        $this->selectedLabel = $this->resolveLabel($id);
        $this->open = false;
        $this->search = '';

        $this->dispatchSelection();
    }

    public function updatedSelected($value): void
    {
        if (!$this->isSelectionInScope($value)) {
            $this->selected = null;
            $this->selectedLabel = null;

            return;
        }

        $this->selectedLabel = $this->resolveLabel($value);
    }

    /**
     * A selected ID must belong to the component's configured query scope
     * (active, tenant/business filters, consignment filter, exclusions) even
     * if the client attempts to set it directly; this is the authoritative
     * guard against crafted out-of-scope selections. Server-side services
     * remain the final authorization boundary.
     */
    private function isSelectionInScope(int|string|null $id): bool
    {
        if (!$id) {
            return true;
        }

        if (in_array((int) $id, $this->excludedLocationIds, true)) {
            return false;
        }

        $settingId = $this->selectedSettingId ?? session('setting_id');

        $query = Location::query()
            ->whereKey($id)
            ->active();

        if ($this->tenantScoped) {
            $query->when($settingId, fn ($q) => $q->where('setting_id', $settingId));
        }

        if ($this->consignmentFilter === 'consignment') {
            $query->where('is_consignment', true);
        } elseif ($this->consignmentFilter === 'standard') {
            $query->where('is_consignment', false);
        }

        return $query->exists();
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

    public function handleLocationCreated(array $location): void
    {
        $option = [
            'id' => $location['id'] ?? null,
            'name' => $location['name'] ?? '',
        ];

        $this->upsertOption($option);
        if ($option['id'] !== null) {
            $this->select($option['id']);
        }
    }

    public function handleSetSelectedLocation($locationId, ?string $name = null): void
    {
        if ($name && $this->name !== $name) {
            return;
        }

        if (!$this->isSelectionInScope($locationId)) {
            $this->selected = null;
            $this->selectedLabel = null;
            $this->open = false;
            $this->search = '';

            return;
        }

        $this->selected = $locationId ?: null;
        $this->selectedLabel = $this->resolveLabel($this->selected);
        $this->open = false;
        $this->search = '';
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

        if (!$this->isSelectionInScope($id)) {
            return null;
        }

        $location = Location::query()
            ->with($this->crossBusiness ? ['setting'] : [])
            ->find($id);

        if (!$location) {
            return null;
        }

        $option = [
            'id' => $location->id,
            'name' => $this->formatLabel($location),
        ];

        $this->upsertOption($option);

        return $option['name'];
    }

    /**
     * Cross-business labels include the company/business name to disambiguate
     * duplicate location names across tenants.
     */
    private function formatLabel(Location $location): string
    {
        if ($this->crossBusiness && $location->setting?->company_name) {
            return "{$location->name} ({$location->setting->company_name})";
        }

        return $location->name;
    }

    /**
     * @param  array<int, mixed>  $options
     * @return array<int, array{id:int|string,name:string}>
     */
    private function prepareOptions(array $options): array
    {
        $normalized = $this->normalizeOptions($options);
        return $this->dedupeById($normalized);
    }

    /**
     * @return array<int, array{id:int|string,name:string}>
     */
    private function fetchLocations(): array
    {
        $settingId = $this->selectedSettingId ?? session('setting_id');

        return Location::query()
            ->active()
            ->when($this->tenantScoped, fn ($q) => $q->when($settingId, fn ($q2) => $q2->where('setting_id', $settingId)))
            ->when(!empty($this->excludedLocationIds), fn ($q) => $q->whereNotIn('id', $this->excludedLocationIds))
            ->when($this->consignmentFilter === 'consignment', fn ($q) => $q->where('is_consignment', true))
            ->when($this->consignmentFilter === 'standard', fn ($q) => $q->where('is_consignment', false))
            ->when($this->crossBusiness, fn ($q) => $q->with('setting'))
            ->orderBy('name')
            ->get()
            ->map(fn (Location $location) => [
                'id' => $location->id,
                'name' => $this->formatLabel($location),
            ])
            ->all();
    }

    /**
     * @param  array<int, mixed>  $options
     * @return array<int, array{id:int|string,name:string}>
     */
    private function normalizeOptions(array $options): array
    {
        $normalized = [];

        foreach ($options as $key => $value) {
            $id = null;
            $label = null;

            if (is_array($value)) {
                $id = $value['id'] ?? $key;
                $label = $value['name'] ?? $value['display_name'] ?? null;
            } else {
                $id = $key;
                $label = (string) $value;
            }

            if ($id === null || $label === null) {
                continue;
            }

            $normalized[] = [
                'id' => is_numeric($id) ? (int) $id : $id,
                'name' => $label,
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

    /**
     * @param  array<int, array{id:int|string,name:string}>  $options
     * @return array<int, array{id:int|string,name:string}>
     */
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
        $event = $this->dispatch('locationDropdownSelected', name: $this->name, value: $this->selected);
        if ($this->dispatchTo && method_exists($event, 'to')) {
            $event->to($this->dispatchTo);
        }
    }
}
