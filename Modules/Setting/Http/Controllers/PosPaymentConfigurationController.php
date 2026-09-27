<?php

namespace Modules\Setting\Http\Controllers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\SettingPosPaymentMethod;

class PosPaymentConfigurationController extends Controller
{
    public function index(): Factory|View|Application
    {
        abort_if(Gate::denies('paymentMethods.access'), 403);

        $currentSettingId = (int) session('setting_id');
        $setting = Setting::findOrFail($currentSettingId);

        // Load all payment methods, join with their enable/disable status for the current setting
        $paymentMethods = PaymentMethod::query()
            ->with(['chartOfAccount'])
            ->leftJoin('setting_pos_payment_methods', function ($join) use ($currentSettingId) {
                $join->on('payment_methods.id', '=', 'setting_pos_payment_methods.payment_method_id')
                    ->where('setting_pos_payment_methods.setting_id', '=', $currentSettingId);
            })
            ->select([
                'payment_methods.*',
                'setting_pos_payment_methods.is_enabled'
            ])
            ->orderBy('payment_methods.name')
            ->get()
            ->map(function ($paymentMethod) {
                $paymentMethod->is_enabled = (bool) $paymentMethod->is_enabled;
                return $paymentMethod;
            });

        return view('setting::pos-payments.index', [
            'setting'        => $setting,
            'paymentMethods' => $paymentMethods,
        ]);
    }

    public function toggle(Request $request, int $paymentMethodId): RedirectResponse
    {
        abort_if(Gate::denies('paymentMethods.edit'), 403);

        $validated = $request->validate([
            'is_enabled' => 'required|boolean',
        ]);

        $currentSettingId = (int) session('setting_id');
        
        SettingPosPaymentMethod::updateOrCreate(
            [
                'setting_id'        => $currentSettingId,
                'payment_method_id' => $paymentMethodId,
            ],
            [
                'is_enabled' => $validated['is_enabled'],
            ]
        );

        $statusMsg = $validated['is_enabled'] ? 'diaktifkan' : 'dinonaktifkan';
        toast("Metode pembayaran berhasil $statusMsg.", 'success');

        return redirect()->route('pos-payment-configurations.index');
    }

    public function bulkEnable(): RedirectResponse
    {
        abort_if(Gate::denies('paymentMethods.edit'), 403);

        $currentSettingId = (int) session('setting_id');
        $paymentMethodIds = PaymentMethod::pluck('id');

        foreach ($paymentMethodIds as $id) {
            SettingPosPaymentMethod::updateOrCreate(
                ['setting_id' => $currentSettingId, 'payment_method_id' => $id],
                ['is_enabled' => true]
            );
        }

        toast('Semua metode pembayaran berhasil diaktifkan.', 'success');
        return redirect()->route('pos-payment-configurations.index');
    }

    public function bulkDisable(): RedirectResponse
    {
        abort_if(Gate::denies('paymentMethods.edit'), 403);

        $currentSettingId = (int) session('setting_id');
        $paymentMethodIds = PaymentMethod::pluck('id');

        foreach ($paymentMethodIds as $id) {
            SettingPosPaymentMethod::updateOrCreate(
                ['setting_id' => $currentSettingId, 'payment_method_id' => $id],
                ['is_enabled' => false]
            );
        }

        toast('Semua metode pembayaran berhasil dinonaktifkan.', 'success');
        return redirect()->route('pos-payment-configurations.index');
    }
}
