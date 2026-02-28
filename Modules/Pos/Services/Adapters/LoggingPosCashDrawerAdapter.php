<?php

namespace Modules\Pos\Services\Adapters;

use Illuminate\Support\Facades\Log;
use Modules\Pos\Services\Contracts\PosCashDrawerAdapter;

class LoggingPosCashDrawerAdapter implements PosCashDrawerAdapter
{
    /**
     * @param  array<string, mixed>  $context
     * @return array{
     *     success:bool,
     *     message:string
     * }
     */
    public function openDrawer(array $context): array
    {
        Log::info('Simulating POS Cash Drawer Open.', $context);

        return [
            'success' => true,
            'message' => 'Drawer open simulated successfully (log only).',
        ];
    }
}
