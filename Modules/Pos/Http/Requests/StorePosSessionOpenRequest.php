<?php

namespace Modules\Pos\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Contracts\Validation\Validator;
use Modules\Pos\Exceptions\PosOpeningCashException;
use Modules\Pos\Services\PosOpeningCashNormalizer;
use Modules\Pos\Services\PosRolePolicyService;

class StorePosSessionOpenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('pos.sessions.open');
    }

    public function rules(): array
    {
        $settingId = (int) session('setting_id');
        $user = $this->user();
        $rolePolicy = app(PosRolePolicyService::class);
        $isFloorStaffStyleSession = $user !== null
            && ! $rolePolicy->hasCheckoutAuthority($user)
            && $rolePolicy->detectRole($user) === PosRolePolicyService::PROFILE_HELPER;

        return [
            'terminal_id' => [
                Rule::prohibitedIf($isFloorStaffStyleSession),
                'nullable',
                'integer',
                Rule::exists('pos_terminals', 'id')
                    ->where(static fn ($query) => $query
                        ->where('setting_id', $settingId)
                        ->where('is_active', true)
                    ),
            ],
            'opening_cash_input_mode' => [
                'nullable',
                Rule::in(PosOpeningCashNormalizer::modes()),
            ],
            // Detailed opening-cash rules (counts, permission, totals) are enforced by
            // PosOpeningCashNormalizer in withValidator() and again in the lifecycle service.
            'opening_float_total' => ['nullable'],
            'opening_denominations' => ['nullable', 'array'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            try {
                app(PosOpeningCashNormalizer::class)->normalize(
                    $this->filled('terminal_id'),
                    $this->input('opening_cash_input_mode'),
                    $this->input('opening_float_total'),
                    $this->input('opening_denominations'),
                    $this->user()
                );
            } catch (PosOpeningCashException $exception) {
                $validator->errors()->add($exception->field, $exception->getMessage());
            }
        });
    }
}
