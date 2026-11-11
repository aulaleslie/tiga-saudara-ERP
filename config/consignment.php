<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Consignment buyout activation
    |--------------------------------------------------------------------------
    | Both switches default to OFF. Read screens stay available regardless; these
    | switches only gate new mutations.
    |
    | ui_enabled            - exposes buyout mutation routes (create/edit/submit/approve/
    |                         reject/cancel/finalize/reverse/attribution/return declaration).
    |                         When false those routes return 404 with no side effect.
    | finalization_enabled  - lets the finalization and reversal services post ownership,
    |                         inventory and payable effects. When false they refuse to run
    |                         even if called directly.
    |
    | Authorization still applies when enabled: users also need the consignments.buyouts.*
    | permissions. Values are cached by `php artisan config:cache`; after changing the
    | environment, re-run config:cache (or config:clear) and restart PHP workers/queues.
    | Disabling stops new actions only; existing buyouts, reservations and lineage stay
    | readable and continue to be honoured by POS, reports and returns.
    */
    'buyouts' => [
        'ui_enabled' => (bool) env('CONSIGNMENT_BUYOUTS_UI_ENABLED', false),
        'finalization_enabled' => (bool) env('CONSIGNMENT_BUYOUTS_FINALIZATION_ENABLED', false),
    ],
];
