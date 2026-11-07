<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Held custody policy for standard Sales Returns
    |--------------------------------------------------------------------------
    | When enabled, the FIRST receiving of a standard return holds the goods in custody
    | (unavailable to every stock consumer) until an authorized inspection releases them.
    | When disabled, NEW standard receiving is blocked (goods never become sellable on receipt);
    | existing custody, its display and inspection/release stay available. Enable only after
    | `saleReturns.inspect` has been assigned to the people who will release goods.
    | Already-received returns are never changed.
    */
    'custody_enabled' => (bool) env('SALES_RETURN_CUSTODY_ENABLED', true),

    'custody_policy_version' => 1,
];
