<?php

namespace Modules\Pos\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdatePosTerminalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('pos.terminals.edit');
    }

    public function rules(): array
    {
        $settingId = (int) session('setting_id');
        $terminal = $this->route('terminal');
        $terminalId = is_object($terminal) ? (int) $terminal->id : (int) $terminal;

        return [
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('pos_terminals', 'code')
                    ->ignore($terminalId)
                    ->where(static fn ($query) => $query->where('setting_id', $settingId)),
            ],
            'name' => ['required', 'string', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
            'require_opening_float' => ['nullable', 'boolean'],
            'allow_total_only_float_input' => ['nullable', 'boolean'],
            'close_variance_approval_threshold' => ['nullable', 'numeric', 'min:0'],
            'cash_threshold' => ['nullable', 'numeric', 'min:0'],
            'auto_open_drawer_on_session_open' => ['nullable', 'boolean'],
            'auto_open_drawer_on_cash_sale' => ['nullable', 'boolean'],
            'auto_open_drawer_on_pickup' => ['nullable', 'boolean'],
            'auto_open_drawer_on_close' => ['nullable', 'boolean'],
            'require_pickup_supervisor_approval' => ['nullable', 'boolean'],
            'metadata' => ['nullable', 'array'],
            'policy_metadata' => ['nullable', 'array'],
        ];
    }
}
