<?php

namespace Modules\Sale\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Sale\Entities\PosDraft;
use Modules\Sale\Services\PosCodeAllocator;
use Modules\Setting\Entities\Setting;
use Illuminate\Support\Facades\DB;

class PosDraftController extends Controller
{
    protected $allocator;

    public function __construct(PosCodeAllocator $allocator)
    {
        $this->allocator = $allocator;
    }

    public function store(Request $request)
    {
        $settingId = session('setting_id');
        $setting = Setting::findOrFail($settingId);

        return DB::transaction(function () use ($request, $setting) {
            $documentNumber = $this->allocator->allocate($setting);
            $posSession = $request->attributes->get('pos_session');

            $draft = PosDraft::create([
                'pos_session_id' => $posSession?->id,
                'setting_id' => $setting->id,
                'user_id' => auth()->id(),
                'status' => PosDraft::STATUS_OPEN,
                'payload' => $request->input('payload'),
                'document_number' => $documentNumber,
                'expires_at' => now()->addHours(24), // Default 24h expiry
            ]);

            return response()->json($draft, 201);
        });
    }
}
