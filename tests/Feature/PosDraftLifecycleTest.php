<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use Modules\Sale\Entities\PosDraft;
use App\Models\User;
use Modules\Setting\Entities\Setting;
use Modules\Currency\Entities\Currency;
use Illuminate\Support\Carbon;

class PosDraftLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $setting;

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

        $this->user = User::factory()->create();
        
        $this->setting = Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'test@example.com',
            'company_phone' => '1234567890',
            'company_address' => 'Test Address',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
        ]);
        
        $this->actingAs($this->user);
    }

    public function test_draft_creation_defaults()
    {
        $draft = PosDraft::create([
            'setting_id' => $this->setting->id,
            'user_id' => $this->user->id,
            'status' => PosDraft::STATUS_OPEN,
            'payload' => ['cart' => []],
        ]);

        $this->assertDatabaseHas('pos_drafts', [
            'id' => $draft->id,
            'status' => PosDraft::STATUS_OPEN,
            'user_id' => $this->user->id,
            'setting_id' => $this->setting->id,
        ]);

        $this->assertNull($draft->expires_at);
        $this->assertNull($draft->pos_receipt_id);
    }

    public function test_draft_expiry_logic()
    {
        Carbon::setTestNow('2025-01-01 12:00:00');

        $draft = PosDraft::create([
            'setting_id' => $this->setting->id,
            'user_id' => $this->user->id,
            'status' => PosDraft::STATUS_OPEN,
            'expires_at' => now()->addHour(),
        ]);

        $this->assertFalse($draft->isExpired());

        Carbon::setTestNow('2025-01-01 13:00:01');
        
        $this->assertTrue($draft->refresh()->isExpired());
    }

    public function test_draft_scope_active()
    {
        Carbon::setTestNow('2025-01-01 12:00:00');

        // Clean up any drafts from previous tests if RefreshDatabase didn't work (unlikely)
        PosDraft::query()->delete();

        $activeDraft = PosDraft::create([
            'setting_id' => $this->setting->id,
            'user_id' => $this->user->id,
            'status' => PosDraft::STATUS_OPEN,
            'expires_at' => now()->addHour(),
        ]);

        $expiredDraft = PosDraft::create([
            'setting_id' => $this->setting->id,
            'user_id' => $this->user->id,
            'status' => PosDraft::STATUS_EXPIRED,
            'expires_at' => now()->subHour(),
        ]);

        $completedDraft = PosDraft::create([
            'setting_id' => $this->setting->id,
            'user_id' => $this->user->id,
            'status' => PosDraft::STATUS_COMPLETED,
        ]);
        
        $results = PosDraft::active()->get();
        
        $this->assertTrue($results->contains($activeDraft));
        $this->assertFalse($results->contains($expiredDraft));
        $this->assertFalse($results->contains($completedDraft));
    }
}
