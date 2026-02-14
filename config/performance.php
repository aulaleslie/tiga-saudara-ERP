<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Livewire Hot Path Debug Logging
    |--------------------------------------------------------------------------
    |
    | Enable this only when diagnosing Livewire interaction bottlenecks.
    | Keep disabled in normal usage to avoid extra I/O in hot render/update
    | paths (purchase create supplier/payment-term updates and product cart).
    |
    */
    'livewire_hotpath_debug' => (bool) env('LIVEWIRE_HOTPATH_DEBUG', false),
];

