<?php

return [
    'v2_dispatch_enabled' => env('STOCK_TRANSFERS_V2_DISPATCH_ENABLED', false),

    /*
     * Workflow version 3 creation activation. When enabled, new Stock
     * Transfers are created as version 3 (location-free goods entry,
     * approver allocations, approval-time dispatch, confirmation receipt).
     * Disabling it only stops new v3 creation: existing v3 documents remain
     * fully readable and operable, and legacy documents are never affected.
     */
    'v3_creation_enabled' => env('STOCK_TRANSFERS_V3_CREATION_ENABLED', false),
];
