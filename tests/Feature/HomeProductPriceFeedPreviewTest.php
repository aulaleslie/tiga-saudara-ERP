<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Product\Entities\ProductPriceFeedEvent;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class HomeProductPriceFeedPreviewTest extends TestCase
{
    use RefreshDatabase;

    private Setting $setting;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setting = Setting::create([
            'company_name' => 'Home Test Business',
            'company_email' => 'home@example.com',
            'company_phone' => '08123456789',
            'notification_email' => 'home@example.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Home',
            'company_address' => 'Home St',
        ]);
    }

    public function test_home_page_displays_feed_preview_card_and_history_link(): void
    {
        $superAdminRole = Role::firstOrCreate(['name' => 'Super Admin']);
        $user = User::factory()->create();
        $user->assignRole($superAdminRole);

        app(\Modules\Product\Services\ProductPriceFeedRecorder::class)->record(
            ProductPriceFeedEvent::TYPE_PRODUCT_CREATED,
            ProductPriceFeedEvent::SUBJECT_PRODUCT,
            1,
            'Preview Item Home',
            'PIH-001',
            [
                ['setting_id' => $this->setting->id, 'after' => ['sale_price' => 10000]],
            ]
        );

        $response = $this->actingAs($user)->get(route('home'));
        $response->assertOk();
        $response->assertSee('Preview Item Home');
        $response->assertSee(route('products.price-feed.index'));
    }
}
