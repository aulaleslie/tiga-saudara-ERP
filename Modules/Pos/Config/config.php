<?php

return [
    'name' => 'Pos',
    'default_cash_threshold' => 10000000,
    'checkout' => [
        'split_posting' => [
            'enabled' => env('POS_CHECKOUT_SPLIT_POSTING_ENABLED', false),
        ],
    ],
];
