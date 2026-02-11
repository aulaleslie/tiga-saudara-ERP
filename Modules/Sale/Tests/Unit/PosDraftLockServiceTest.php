<?php

namespace Modules\Sale\Tests\Unit;

use App\Exceptions\PosException;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\Sale\Entities\PosDraft;
use Modules\Sale\Services\PosDraftLockService;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class PosDraftLockServiceTest extends TestCase
{
    use RefreshDatabase;

    private Setting $setting;
    private User $userA;
    private User $userB;

    protected function setUp(): void
    {
        parent::setUp();

        $currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'test@example.com',
            'company_phone' => '08123',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
            'pos_document_prefix' => 'PST',
        ]);

        $this->userA = User::factory()->create();
        $this->userB = User::factory()->create();
    }

    public function test_acquire_lock_blocks_other_user_until_expired(): void
    {
        $draft = PosDraft::create([
            'setting_id' => $this->setting->id,
            'user_id' => $this->userA->id,
            'status' => PosDraft::STATUS_AJUKAN_PEMBAYARAN,
            'document_number' => 'PST-2026-02-00001',
            'expires_at' => now()->addHour(),
            'payload' => ['cart' => [['id' => 1, 'qty' => 1, 'price' => 10]]],
        ]);

        $service = app(PosDraftLockService::class);

        $locked = $service->acquire($draft, $this->userA->id);
        $this->assertSame($this->userA->id, (int) $locked->locked_by_user_id);
        $this->assertTrue($locked->hasActiveLock());

        $this->expectException(PosException::class);
        $this->expectExceptionMessage('Draft sedang dikunci oleh kasir lain.');
        $service->acquire($draft->fresh(), $this->userB->id);
    }

    public function test_heartbeat_extends_lock_and_release_clears_it(): void
    {
        $draft = PosDraft::create([
            'setting_id' => $this->setting->id,
            'user_id' => $this->userA->id,
            'status' => PosDraft::STATUS_AJUKAN_PEMBAYARAN,
            'document_number' => 'PST-2026-02-00002',
            'expires_at' => now()->addHour(),
            'payload' => ['cart' => [['id' => 1, 'qty' => 1, 'price' => 10]]],
        ]);

        $service = app(PosDraftLockService::class);

        $locked = $service->acquire($draft, $this->userA->id);
        $before = $locked->locked_until;

        $heartbeat = $service->heartbeat($locked, $this->userA->id);
        $this->assertTrue($heartbeat->locked_until->greaterThanOrEqualTo($before));

        $released = $service->release($heartbeat, $this->userA->id);
        $this->assertNull($released->locked_by_user_id);
        $this->assertNull($released->locked_until);
    }

    public function test_other_user_can_acquire_after_lock_expired(): void
    {
        $draft = PosDraft::create([
            'setting_id' => $this->setting->id,
            'user_id' => $this->userA->id,
            'status' => PosDraft::STATUS_AJUKAN_PEMBAYARAN,
            'document_number' => 'PST-2026-02-00003',
            'expires_at' => now()->addHour(),
            'locked_by_user_id' => $this->userA->id,
            'locked_at' => now()->subHour(),
            'locked_until' => now()->subMinute(),
            'payload' => ['cart' => [['id' => 1, 'qty' => 1, 'price' => 10]]],
        ]);

        $service = app(PosDraftLockService::class);
        $locked = $service->acquire($draft, $this->userB->id);

        $this->assertSame($this->userB->id, (int) $locked->locked_by_user_id);
        $this->assertTrue($locked->hasActiveLock());
    }
}
