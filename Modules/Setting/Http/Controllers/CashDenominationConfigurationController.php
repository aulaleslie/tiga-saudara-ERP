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
use Modules\Setting\Entities\CashDenomination;

class CashDenominationConfigurationController extends Controller
{
    public function index(): Factory|View|Application
    {
        abort_if(Gate::denies('cashDenominations.access'), 403);

        $denominations = CashDenomination::query()
            ->orderBy('position')
            ->get();

        return view('setting::cash-denominations.index', [
            'denominationGroups' => $denominations->groupBy('type'),
            'enabledDenominationIds' => $denominations
                ->where('is_enabled', true)
                ->pluck('id')
                ->all(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        abort_if(Gate::denies('cashDenominations.edit'), 403);

        $validated = $request->validate([
            'enabled_denomination_ids' => ['sometimes', 'array'],
            'enabled_denomination_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('cash_denominations', 'id'),
            ],
        ]);

        $enabledDenominationIds = array_map(
            static fn (mixed $value): int => (int) $value,
            $validated['enabled_denomination_ids'] ?? [],
        );

        DB::transaction(function () use ($enabledDenominationIds): void {
            CashDenomination::query()->update(['is_enabled' => false]);

            if ($enabledDenominationIds !== []) {
                CashDenomination::query()
                    ->whereKey($enabledDenominationIds)
                    ->update(['is_enabled' => true]);
            }
        });

        toast('Konfigurasi pecahan uang global berhasil disimpan.', 'success');

        return redirect()->route('cash-denomination-configurations.index');
    }
}
