<?php

namespace Tests\Feature\Console\Commands;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class RepairNotificationActionUrlsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Setting $setting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->setting = Setting::factory()->create();
    }

    public function test_preview_mode_does_not_mutate_database()
    {
        $notification = Notification::create([
            'user_id' => $this->user->id,
            'setting_id' => $this->setting->id,
            'category' => 'approval',
            'type' => 'document_approval',
            'title' => 'Title',
            'message' => 'Msg',
            'fingerprint' => 'fp-preview',
            'action_url' => 'http://192.168.1.100:8000/purchases/1',
        ]);

        $this->artisan('notifications:repair-action-urls', [
            '--origin' => ['http://192.168.1.100:8000'],
        ])
            ->expectsOutputToContain('PREVIEW/DRY-RUN')
            ->expectsOutputToContain('Repairable Rows')
            ->assertSuccessful();

        $this->assertEquals('http://192.168.1.100:8000/purchases/1', $notification->fresh()->action_url);
    }

    public function test_apply_mode_repairs_lan_and_cloudflare_urls_preserving_query_and_fragment()
    {
        $lanNotification = Notification::create([
            'user_id' => $this->user->id,
            'setting_id' => $this->setting->id,
            'category' => 'approval',
            'type' => 'document_approval',
            'title' => 'LAN Doc',
            'message' => 'LAN Msg',
            'fingerprint' => 'fp-lan',
            'action_url' => 'http://192.168.1.100:8000/purchases/15?tab=items&page=2#section-a',
        ]);

        $cfNotification = Notification::create([
            'user_id' => $this->user->id,
            'setting_id' => $this->setting->id,
            'category' => 'stock',
            'type' => 'location_low_stock',
            'title' => 'CF Stock',
            'message' => 'CF Msg',
            'fingerprint' => 'fp-cf',
            'action_url' => 'https://erp.tigasaudara.com/products/42?active=1#inventory',
        ]);

        $alreadyRelative = Notification::create([
            'user_id' => $this->user->id,
            'setting_id' => $this->setting->id,
            'category' => 'approval',
            'type' => 'document_approval',
            'title' => 'Rel Doc',
            'message' => 'Rel Msg',
            'fingerprint' => 'fp-rel',
            'action_url' => '/sale-returns/9',
        ]);

        $unrecognizedNotification = Notification::create([
            'user_id' => $this->user->id,
            'setting_id' => $this->setting->id,
            'category' => 'approval',
            'type' => 'document_approval',
            'title' => 'Other Doc',
            'message' => 'Other Msg',
            'fingerprint' => 'fp-other',
            'action_url' => 'https://otherdomain.com/expenses/3',
        ]);

        $this->artisan('notifications:repair-action-urls', [
            '--origin' => ['http://192.168.1.100:8000', 'https://erp.tigasaudara.com'],
            '--apply' => true,
        ])
            ->expectsOutputToContain('APPLY')
            ->assertSuccessful();

        $this->assertEquals(
            '/purchases/15?tab=items&page=2#section-a',
            $lanNotification->fresh()->action_url
        );

        $this->assertEquals(
            '/products/42?active=1#inventory',
            $cfNotification->fresh()->action_url
        );

        $this->assertEquals(
            '/sale-returns/9',
            $alreadyRelative->fresh()->action_url
        );

        $this->assertEquals(
            'https://otherdomain.com/expenses/3',
            $unrecognizedNotification->fresh()->action_url
        );
    }

    public function test_repair_preserves_notification_state_and_timestamps()
    {
        $readAt = Carbon::now()->subDays(2);
        $resolvedAt = Carbon::now()->subDay();
        $createdAt = Carbon::now()->subDays(5);
        $updatedAt = Carbon::now()->subDays(3);

        $notification = Notification::create([
            'user_id' => $this->user->id,
            'setting_id' => $this->setting->id,
            'category' => 'approval',
            'type' => 'document_approval',
            'title' => 'Preserved Title',
            'message' => 'Preserved Message',
            'source_type' => 'Modules\Purchase\Entities\Purchase',
            'source_id' => 99,
            'fingerprint' => 'fp-preserve',
            'action_url' => 'http://192.168.1.100:8000/purchases/99',
            'metadata' => ['key' => 'value'],
            'read_at' => $readAt,
            'resolved_at' => $resolvedAt,
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
        ]);

        $this->artisan('notifications:repair-action-urls', [
            '--origin' => ['http://192.168.1.100:8000'],
            '--apply' => true,
        ])->assertSuccessful();

        $refreshed = $notification->fresh();

        $this->assertEquals('/purchases/99', $refreshed->action_url);
        $this->assertEquals($this->user->id, $refreshed->user_id);
        $this->assertEquals($this->setting->id, $refreshed->setting_id);
        $this->assertEquals('approval', $refreshed->category);
        $this->assertEquals('document_approval', $refreshed->type);
        $this->assertEquals('Preserved Title', $refreshed->title);
        $this->assertEquals('Preserved Message', $refreshed->message);
        $this->assertEquals('Modules\Purchase\Entities\Purchase', $refreshed->source_type);
        $this->assertEquals(99, $refreshed->source_id);
        $this->assertEquals('fp-preserve', $refreshed->fingerprint);
        $this->assertEquals(['key' => 'value'], $refreshed->metadata);
        $this->assertEquals($readAt->toIso8601String(), $refreshed->read_at->toIso8601String());
        $this->assertEquals($resolvedAt->toIso8601String(), $refreshed->resolved_at->toIso8601String());
        $this->assertEquals($createdAt->toIso8601String(), $refreshed->created_at->toIso8601String());
        $this->assertEquals($updatedAt->toIso8601String(), $refreshed->updated_at->toIso8601String());
    }

    public function test_repair_is_idempotent_when_run_repeatedly()
    {
        Notification::create([
            'user_id' => $this->user->id,
            'setting_id' => $this->setting->id,
            'category' => 'approval',
            'type' => 'document_approval',
            'title' => 'Title',
            'message' => 'Msg',
            'fingerprint' => 'fp-idem',
            'action_url' => 'http://192.168.1.100:8000/purchases/5',
        ]);

        // First run with apply
        $this->artisan('notifications:repair-action-urls', [
            '--origin' => ['http://192.168.1.100:8000'],
            '--apply' => true,
        ])->assertSuccessful();

        // Second run with apply
        $this->artisan('notifications:repair-action-urls', [
            '--origin' => ['http://192.168.1.100:8000'],
            '--apply' => true,
        ])
            ->expectsOutputToContain('Already Origin-Relative')
            ->assertSuccessful();

        $this->assertEquals('/purchases/5', Notification::where('fingerprint', 'fp-idem')->first()->action_url);
    }
}
