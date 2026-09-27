<?php

namespace App\Livewire\Reports;

use Modules\Setting\Entities\Setting;

trait HasReportSettingScope
{
    public array $selectedSettingIds = [];

    protected function getAvailableSettings(): array
    {
        return Setting::query()
            ->orderBy('company_name')
            ->select('id', 'company_name')
            ->get()
            ->toArray();
    }

    protected function getEffectiveSettingIds(): array
    {
        if (empty($this->selectedSettingIds)) {
            return [(int) session('setting_id')];
        }

        return array_filter(
            array_map('intval', $this->selectedSettingIds),
            fn($id) => $id > 0
        );
    }

    protected function validateSettingIds(array $settingIds, array $availableSettings): array
    {
        $validIds = collect($availableSettings)->pluck('id')->toArray();
        return array_values(array_unique(array_intersect($settingIds, $validIds)));
    }

    protected function getScopeLabel(array $availableSettings, array $effectiveSettingIds): string
    {
        $count = count($effectiveSettingIds);
        $totalCount = count($availableSettings);

        if ($count === 1) {
            $setting = collect($availableSettings)->firstWhere('id', $effectiveSettingIds[0]);
            return $setting ? $setting['company_name'] : 'Unknown Company';
        }

        if ($count === $totalCount && $totalCount > 0) {
            return 'Semua Perusahaan';
        }

        return 'Beberapa Perusahaan';
    }
}
