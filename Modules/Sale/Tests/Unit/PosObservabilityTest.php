<?php

namespace Modules\Sale\Tests\Unit;

use App\Models\User;
use App\Support\PosMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Modules\Sale\Entities\PosDraft;
use Modules\Sale\Services\PosDraftLockService;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class PosObservabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_metric_increment_stores_counter_with_label_hash(): void
    {
        Cache::flush();

        $labels = ['setting_id' => 99, 'event' => 'draft_created'];
        PosMetrics::increment('draft_created', $labels);
        PosMetrics::increment('draft_created', $labels);

        $key = $this->metricKey('draft_created', $labels);
        $this->assertSame(2, (int) Cache::get($key, 0));
    }

    public function test_lock_timeout_metric_emitted_when_expired_lock_is_taken_over(): void
    {
        Cache::flush();

        $setting = Setting::factory()->create();
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $draft = PosDraft::query()->create([
            'setting_id' => $setting->id,
            'user_id' => $userA->id,
            'status' => PosDraft::STATUS_AJUKAN_PEMBAYARAN,
            'document_number' => 'POS-2026-02-00001',
            'expires_at' => now()->addHour(),
            'locked_by_user_id' => $userA->id,
            'locked_at' => now()->subHour(),
            'locked_until' => now()->subMinute(),
            'payload' => ['cart' => [['id' => 1, 'qty' => 1, 'price' => 100]]],
        ]);

        app(PosDraftLockService::class)->acquire($draft, $userB->id);

        $key = $this->metricKey('lock_timeout', ['setting_id' => $setting->id]);
        $this->assertSame(1, (int) Cache::get($key, 0));
    }

    private function metricKey(string $metric, array $labels = []): string
    {
        $labelHash = empty($labels)
            ? 'global'
            : substr(sha1(json_encode($labels)), 0, 12);

        return sprintf('pos_metric:%s:%s', $metric, $labelHash);
    }
}
