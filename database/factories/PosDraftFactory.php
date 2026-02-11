<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Sale\Entities\PosDraft;
use Modules\Setting\Entities\Setting;
use App\Models\User;
use App\Models\PosSession;

class PosDraftFactory extends Factory
{
    protected $model = PosDraft::class;

    public function definition(): array
    {
        return [
            'pos_session_id' => PosSession::factory(),
            'setting_id' => Setting::factory(),
            'user_id' => User::factory(),
            'status' => PosDraft::STATUS_OPEN,
            'expires_at' => now()->addHour(),
        ];
    }
}
