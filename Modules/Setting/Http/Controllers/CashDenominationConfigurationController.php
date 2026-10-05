<?php

declare(strict_types=1);

namespace Modules\Setting\Http\Controllers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\SettingCashDenomination;
use Modules\Setting\Enums\CashDenomination;

class CashDenominationConfigurationController extends Controller
{
    public function index(): Factory|View|Application
    {
        abort_if(Gate::denies('cashDenominations.access'), 403);

        $settingId = (int) session('setting_id');
        $setting = Setting::query()->findOrFail($settingId);

        SettingCashDenomination::createDefaultsForSetting($settingId);

        $denominations = SettingCashDenomination::query()
            ->where('setting_id', $settingId)
            ->orderBy('position')
            ->get();

        return view('setting::cash-denominations.index', [
            'setting' => $setting,
            'denominationGroups' => $denominations->groupBy(
                static fn (SettingCashDenomination $configuration): string => $configuration->denomination->type(),
            ),
            'enabledDenominations' => $denominations
                ->where('is_enabled', true)
                ->pluck('denomination')
                ->map(static fn (CashDenomination $denomination): int => $denomination->value)
                ->all(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        abort_if(Gate::denies('cashDenominations.edit'), 403);

        $validated = $request->validate([
            'enabled_denominations' => ['sometimes', 'array'],
            'enabled_denominations.*' => [
                'integer',
                'distinct',
                Rule::in(CashDenomination::values()),
            ],
        ]);

        $settingId = (int) session('setting_id');
        Setting::query()->findOrFail($settingId);

        $enabledDenominations = array_map(
            static fn (mixed $value): int => (int) $value,
            $validated['enabled_denominations'] ?? [],
        );

        DB::transaction(function () use ($settingId, $enabledDenominations): void {
            SettingCashDenomination::createDefaultsForSetting($settingId);

            SettingCashDenomination::query()
                ->where('setting_id', $settingId)
                ->update(['is_enabled' => false]);

            if ($enabledDenominations !== []) {
                SettingCashDenomination::query()
                    ->where('setting_id', $settingId)
                    ->whereIn('denomination', $enabledDenominations)
                    ->update(['is_enabled' => true]);
            }
        });

        toast('Konfigurasi pecahan uang berhasil disimpan.', 'success');

        return redirect()->route('cash-denomination-configurations.index');
    }
}
