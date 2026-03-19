<?php

namespace Modules\Pos\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StorePosSessionOpenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('pos.sessions.open');
    }

    public function rules(): array
    {
        $settingId = (int) session('setting_id');
        $requiresTerminalSelection = (bool) $this->user()?->can('pos.sessions.require-terminal');
        $terminalId = $this->input('terminal_id');
        $hasTerminal = !empty($terminalId);

        return [
            'terminal_id' => [
                $requiresTerminalSelection ? 'required' : 'nullable',
                'integer',
                Rule::exists('pos_terminals', 'id')
                    ->where(static fn ($query) => $query
                        ->where('setting_id', $settingId)
                        ->where('is_active', true)
                    ),
            ],
            'opening_float_total' => [
                $hasTerminal ? 'required' : 'nullable',
                'numeric',
                'gt:0',
            ],
            'opening_denominations' => ['nullable', 'array'],
            'opening_denominations.*' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
