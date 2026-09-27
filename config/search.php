<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Typo-Tolerant Search
    |--------------------------------------------------------------------------
    |
    | Enable or disable typo-tolerant search using MySQL FULLTEXT with ngram.
    | When enabled, searches like "katrid" will match "CATRIDGE".
    | Set to false to use traditional LIKE-based search.
    |
    */
    'typo_tolerant' => env('SEARCH_TYPO_TOLERANT', true),
];
