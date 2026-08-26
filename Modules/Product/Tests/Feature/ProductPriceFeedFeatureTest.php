<?php

namespace Modules\Product\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Product\Entities\ProductPriceFeedEvent;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductPriceFeedFeatureTest extends TestCase
{
    use RefreshDatabase;

    private Setting $settingA;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settingA = Setting::create([
            'company_name' => 'Business Alpha',
            'company_email' => 'alpha@example.com',
            'company_phone' => '08123456789',
            'notification_email' => 'alpha@example.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Alpha',
            'company_address' => 'Alpha St',
        ]);

        $superAdminRole = Role::firstOrCreate(['name' => 'Super Admin']);
        $this->user = User::factory()->create();
        $this->user->assignRole($superAdminRole);
    }

    public function test_authenticated_user_can_view_history_page_and_detail_json(): void
    {
        $event = app(\Modules\Product\Services\ProductPriceFeedRecorder::class)->record(
            ProductPriceFeedEvent::TYPE_PRODUCT_CREATED,
            ProductPriceFeedEvent::SUBJECT_PRODUCT,
            10,
            'UI Test Item',
            'UI-001',
            [
                ['setting_id' => $this->settingA->id, 'after' => ['sale_price' => 20000]],
            ]
        );

        $response = $this->actingAs($this->user)->get(route('products.price-feed.index'));
        $response->assertOk();
        $response->assertSee('UI Test Item');

        $detailResponse = $this->actingAs($this->user)->get(route('products.price-feed.show', $event->id));
        $detailResponse->assertOk();
        $detailResponse->assertJsonPath('subject_name', 'UI Test Item');
    }
}
