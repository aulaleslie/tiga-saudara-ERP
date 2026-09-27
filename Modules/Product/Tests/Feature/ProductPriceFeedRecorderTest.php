<?php

namespace Modules\Product\Tests\Feature;

use Modules\Setting\Entities\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Product\Entities\ProductPriceFeedEvent;
use Modules\Product\Services\ProductPriceFeedRecorder;
use Tests\TestCase;

class ProductPriceFeedRecorderTest extends TestCase
{
    use RefreshDatabase;

    private ProductPriceFeedRecorder $recorder;
    private Setting $settingA;
    private Setting $settingB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recorder = new ProductPriceFeedRecorder();

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
        $this->settingB = Setting::create([
            'company_name' => 'Business Beta',
            'company_email' => 'beta@example.com',
            'company_phone' => '08123456788',
            'notification_email' => 'beta@example.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Beta',
            'company_address' => 'Beta St',
        ]);
    }

    public function test_records_created_event_snapshot(): void
    {
        $user = User::factory()->create(['name' => 'Test Admin']);

        $event = $this->recorder->record(
            ProductPriceFeedEvent::TYPE_PRODUCT_CREATED,
            ProductPriceFeedEvent::SUBJECT_PRODUCT,
            101,
            'Widget A',
            'WDG-001',
            [
                [
                    'setting_id' => $this->settingA->id,
                    'after' => ['sale_price' => 15000.0, 'last_purchase_price' => 10000.0],
                ],
            ],
            ProductPriceFeedEvent::SOURCE_MANUAL,
            $user
        );

        $this->assertNotNull($event);
        $this->assertEquals(ProductPriceFeedEvent::TYPE_PRODUCT_CREATED, $event->event_type);
        $this->assertEquals('Widget A', $event->subject_name);
        $this->assertEquals('WDG-001', $event->subject_code);
        $this->assertEquals('TEST ADMIN', $event->actor_name);
        $this->assertCount(1, $event->snapshots);

        $snapshot = $event->snapshots->first();
        $this->assertEquals($this->settingA->id, $snapshot->setting_id);
        $this->assertEquals('BUSINESS ALPHA', $snapshot->setting_name);
        $this->assertEquals(['sale_price' => 15000.0, 'last_purchase_price' => 10000.0], $snapshot->after_snapshot);
    }

    public function test_suppresses_no_op_updates(): void
    {
        $event = $this->recorder->record(
            ProductPriceFeedEvent::TYPE_PRODUCT_PRICE_UPDATED,
            ProductPriceFeedEvent::SUBJECT_PRODUCT,
            101,
            'Widget A',
            'WDG-001',
            [
                [
                    'setting_id' => $this->settingA->id,
                    'before' => ['sale_price' => 15000.0, 'tier_1_price' => 12000.000],
                    'after' => ['sale_price' => 15000.00, 'tier_1_price' => 12000.0],
                ],
            ]
        );

        $this->assertNull($event);
        $this->assertDatabaseCount('product_price_feed_events', 0);
        $this->assertDatabaseCount('product_price_feed_snapshots', 0);
    }

    public function test_records_changed_only_fields_in_update_snapshot(): void
    {
        $event = $this->recorder->record(
            ProductPriceFeedEvent::TYPE_PRODUCT_PRICE_UPDATED,
            ProductPriceFeedEvent::SUBJECT_PRODUCT,
            101,
            'Widget A',
            'WDG-001',
            [
                [
                    'setting_id' => $this->settingA->id,
                    'before' => ['sale_price' => 15000.0, 'tier_1_price' => 12000.0, 'last_purchase_price' => 10000.0],
                    'after' => ['sale_price' => 18000.0, 'tier_1_price' => 12000.0, 'last_purchase_price' => 10000.0],
                ],
            ]
        );

        $this->assertNotNull($event);
        $snapshot = $event->snapshots->first();
        $this->assertEquals(['sale_price' => 15000.0], $snapshot->before_snapshot);
        $this->assertEquals(['sale_price' => 18000.0], $snapshot->after_snapshot);
    }

    public function test_groups_multi_business_changes(): void
    {
        $opUuid = '550e8400-e29b-41d4-a716-446655440000';

        $event = $this->recorder->record(
            ProductPriceFeedEvent::TYPE_PRODUCT_PRICE_UPDATED,
            ProductPriceFeedEvent::SUBJECT_PRODUCT,
            101,
            'Widget Multi',
            'WDG-MULTI',
            [
                [
                    'setting_id' => $this->settingA->id,
                    'before' => ['sale_price' => 10000.0],
                    'after' => ['sale_price' => 12000.0],
                ],
                [
                    'setting_id' => $this->settingB->id,
                    'before' => ['sale_price' => 10000.0],
                    'after' => ['sale_price' => 12000.0],
                ],
            ],
            ProductPriceFeedEvent::SOURCE_MANUAL,
            null,
            $opUuid
        );

        $this->assertNotNull($event);
        $this->assertEquals($opUuid, $event->operation_uuid);
        $this->assertCount(2, $event->snapshots);
    }

    public function test_transaction_rollback_reverts_events(): void
    {
        try {
            DB::transaction(function () {
                $this->recorder->record(
                    ProductPriceFeedEvent::TYPE_PRODUCT_CREATED,
                    ProductPriceFeedEvent::SUBJECT_PRODUCT,
                    999,
                    'Rollback Item',
                    'RB-001',
                    [
                        [
                            'setting_id' => $this->settingA->id,
                            'after' => ['sale_price' => 5000.0],
                        ],
                    ]
                );

                throw new \Exception('Simulated Failure');
            });
        } catch (\Exception $e) {
            // expected
        }

        $this->assertDatabaseCount('product_price_feed_events', 0);
        $this->assertDatabaseCount('product_price_feed_snapshots', 0);
    }
}
