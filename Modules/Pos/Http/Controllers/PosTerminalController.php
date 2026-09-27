<?php

namespace Modules\Pos\Http\Controllers;

use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Http\Requests\StorePosTerminalRequest;
use Modules\Pos\Http\Requests\UpdatePosTerminalRequest;

class PosTerminalController extends Controller
{
    public function index(): Renderable
    {
        $settingId = $this->currentSettingId();
        $setting = settings();

        $terminals = PosTerminal::query()
            ->with(['policy', 'activeSessions.cashier'])
            ->where('setting_id', $settingId)
            ->orderBy('code')
            ->get();

        $saleLocations = \Modules\Setting\Entities\SettingSaleLocation::query()
            ->with('location')
            ->where('setting_id', $settingId)
            ->where('is_enabled', true)
            ->orderBy('position')
            ->get();

        return view('pos::terminals.index', compact('terminals', 'setting', 'saleLocations'));
    }

    public function create(): Renderable
    {
        return view('pos::terminals.create');
    }

    public function store(StorePosTerminalRequest $request): RedirectResponse
    {
        $settingId = $this->currentSettingId();

        $terminal = PosTerminal::query()->create([
            'setting_id' => $settingId,
            'code' => $request->string('code')->value(),
            'name' => $request->string('name')->value(),
            'is_active' => $request->boolean('is_active', true),
            'metadata' => $request->input('metadata'),
        ]);

        $terminal->policy()->create($this->policyPayload($request));

        toast('POS terminal created successfully.', 'success');

        return redirect()->route('pos.terminals.index');
    }

    public function edit(int $terminal): Renderable
    {
        $settingId = $this->currentSettingId();
        $terminal = $this->terminalForSettingOrFail($settingId, $terminal);

        return view('pos::terminals.edit', compact('terminal'));
    }

    public function update(UpdatePosTerminalRequest $request, int $terminal): RedirectResponse
    {
        $settingId = $this->currentSettingId();
        $terminal = $this->terminalForSettingOrFail($settingId, $terminal);

        $terminal->update([
            'code' => $request->string('code')->value(),
            'name' => $request->string('name')->value(),
            'is_active' => $request->boolean('is_active'),
            'metadata' => $request->input('metadata'),
        ]);

        $terminal->policy()->updateOrCreate(
            ['terminal_id' => $terminal->id],
            $this->policyPayload($request)
        );

        toast('POS terminal updated successfully.', 'info');

        return redirect()->route('pos.terminals.index');
    }

    public function destroy(int $terminal): RedirectResponse
    {
        $settingId = $this->currentSettingId();
        $terminal = $this->terminalForSettingOrFail($settingId, $terminal);

        if (! $terminal->is_active) {
            toast('POS terminal already inactive.', 'warning');

            return redirect()->route('pos.terminals.index');
        }

        $terminal->update(['is_active' => false]);

        toast('POS terminal deactivated.', 'warning');

        return redirect()->route('pos.terminals.index');
    }

    private function currentSettingId(): int
    {
        $settingId = (int) session('setting_id');

        abort_if($settingId <= 0, 403, 'Setting context is required.');

        return $settingId;
    }

    private function terminalForSettingOrFail(int $settingId, int $terminalId): PosTerminal
    {
        return PosTerminal::query()
            ->with('policy')
            ->where('setting_id', $settingId)
            ->findOrFail($terminalId);
    }

    private function policyPayload(StorePosTerminalRequest|UpdatePosTerminalRequest $request): array
    {
        return [
            'require_opening_float' => $request->boolean('require_opening_float', true),
            'allow_total_only_float_input' => $request->boolean('allow_total_only_float_input', true),
            'close_variance_approval_threshold' => (float) ($request->input('close_variance_approval_threshold', 0)),
            'cash_threshold' => $request->filled('cash_threshold') ? (float) $request->input('cash_threshold') : null,
            'auto_open_drawer_on_session_open' => $request->boolean('auto_open_drawer_on_session_open'),
            'auto_open_drawer_on_cash_sale' => $request->boolean('auto_open_drawer_on_cash_sale'),
            'auto_open_drawer_on_pickup' => $request->boolean('auto_open_drawer_on_pickup'),
            'auto_open_drawer_on_close' => $request->boolean('auto_open_drawer_on_close'),
            'require_pickup_supervisor_approval' => $request->boolean('require_pickup_supervisor_approval', true),
            'metadata' => $request->input('policy_metadata'),
        ];
    }
}
