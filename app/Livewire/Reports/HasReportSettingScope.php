<?php

namespace App\Livewire\Reports;

use Modules\Setting\Entities\Setting;

trait HasReportSettingScope
{
    public array $selectedSettingIds = [];

    protected ?array $memoizedAvailableSettings = null;

    protected function getAvailableSettings(): array
    {
        if ($this->memoizedAvailableSettings !== null) {
            return $this->memoizedAvailableSettings;
        }

        $user = auth()->user();

        if (! $user) {
            return $this->memoizedAvailableSettings = [];
        }

        if ($user->hasRole('Super Admin')) {
            return $this->memoizedAvailableSettings = Setting::query()
                ->orderBy('company_name', 'asc')
                ->select('id', 'company_name')
                ->get()
                ->map(fn ($s) => ['id' => (int) $s->id, 'company_name' => (string) $s->company_name])
                ->toArray();
        }

        return $this->memoizedAvailableSettings = $user->settings()
            ->orderBy('company_name', 'asc')
            ->select('settings.id', 'settings.company_name')
            ->get()
            ->map(fn ($s) => ['id' => (int) $s->id, 'company_name' => (string) $s->company_name])
            ->toArray();
    }

    protected function allAvailableSettingIds(): array
    {
        return array_map('intval', array_column($this->getAvailableSettings(), 'id'));
    }

    public function getSanitizedSettingIds(?array $settingIds = null): array
    {
        $raw = $settingIds ?? $this->selectedSettingIds;
        $allowedIds = $this->allAvailableSettingIds();

        $sanitized = array_values(array_unique(array_filter(
            array_map('intval', (array) $raw),
            fn ($id) => in_array($id, $allowedIds, true)
        )));

        sort($sanitized);

        return array_values($sanitized);
    }

    protected function defaultSettingSelection(): array
    {
        $availableIds = $this->allAvailableSettingIds();
        $sessionId = (int) session('setting_id');
        if ($sessionId > 0 && in_array($sessionId, $availableIds, true)) {
            return [$sessionId];
        }
        return !empty($availableIds) ? [$availableIds[0]] : [];
    }

    /**
     * Resolves effective setting IDs with fallback to session or first assigned business.
     * Note: This fallback is intended ONLY for older sales and finance reports
     * (SaleByProduct, ProfitLoss, GeneralLedger, TrialBalance, CashFlow, BalanceSheet).
     * Produk reports must use getSanitizedSettingIds() directly and halt on empty selection.
     */
    protected function getEffectiveSettingIds(): array
    {
        $sanitized = $this->getSanitizedSettingIds();

        if (empty($sanitized)) {
            return $this->defaultSettingSelection();
        }

        return $sanitized;
    }

    protected function activeSettingIds(): array
    {
        $raw = (property_exists($this, 'appliedFilters') && isset($this->appliedFilters['selectedSettingIds']))
            ? $this->appliedFilters['selectedSettingIds']
            : ($this->selectedSettingIds ?? []);

        return $this->getSanitizedSettingIds($raw);
    }

    protected function isMultiBusiness(?array $activeSettingIds = null): bool
    {
        return count($activeSettingIds ?? $this->activeSettingIds()) > 1;
    }

    protected function validateSettingIds(array $settingIds, ?array $availableSettings = null): array
    {
        if ($availableSettings === null) {
            return $this->getSanitizedSettingIds($settingIds);
        }

        $allowedIds = array_map('intval', array_column($availableSettings, 'id'));

        $sanitized = array_values(array_unique(array_filter(
            array_map('intval', $settingIds),
            fn ($id) => in_array($id, $allowedIds, true)
        )));

        sort($sanitized);

        return array_values($sanitized);
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
