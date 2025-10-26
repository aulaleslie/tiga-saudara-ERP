<?php

return [
    'name' => 'Setting',

    /*
    |--------------------------------------------------------------------------
    | Maximum POS-enabled locations per setting
    |--------------------------------------------------------------------------
    |
    | Configure the maximum number of locations that can be marked as a POS
    | point for a single business setting. A value of 0 (or any value below 1)
    | disables the limit, allowing an unrestricted number of POS locations.
    */
    'max_pos_locations' => (int) env('SETTING_MAX_POS_LOCATIONS', 0),
];
