<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

class PosMetrics
{
    public static function increment(string $metric, array $labels = []): void
    {
        $labelHash = empty($labels)
            ? 'global'
            : substr(sha1(json_encode($labels)), 0, 12);

        $key = sprintf('pos_metric:%s:%s', $metric, $labelHash);

        if (! Cache::has($key)) {
            Cache::put($key, 0, now()->addDay());
        }

        Cache::increment($key);
    }
}
