<?php

namespace App\Livewire;

use Illuminate\Contracts\Auth\Guard;
use Livewire\Component;
use Modules\Setting\Entities\Setting;

class BusinessSelector extends Component
{
    public ?int $selectedSettingId = null;
    public bool $isRequired = false;
    public ?string $error = null;
    public string $selectId = 'business-selector';

    public function mount(?int $selectedSettingId = null, bool $isRequired = false, string $selectId = 'business-selector'): void
    {
        $this->selectedSettingId = $selectedSettingId ?? session('setting_id');
        $this->isRequired = $isRequired;
        $this->selectId = $selectId;
    }

    public function getAvailableSettingsProperty(): array
    {
        $user = auth()->user();

        if ($user->hasRole('Super Admin')) {
            return Setting::orderBy('company_name')
                ->get()
                ->map(fn ($s) => ['id' => $s->id, 'company_name' => $s->company_name])
                ->toArray();
        }

        return $user->settings()
            ->orderBy('company_name')
            ->get()
            ->map(fn ($s) => ['id' => $s->id, 'company_name' => $s->company_name])
            ->toArray();
    }

    public function render()
    {
        return view('livewire.business-selector', [
            'availableSettings' => $this->getAvailableSettingsProperty(),
        ]);
    }
}
