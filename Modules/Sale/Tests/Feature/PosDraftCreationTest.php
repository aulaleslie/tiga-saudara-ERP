<?php

namespace Modules\Sale\Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\PosSession;
use Modules\Setting\Entities\Setting;
use Modules\Sale\Entities\PosDraft;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\SettingSaleLocation;
use Spatie\Permission\Models\Permission;

class PosDraftCreationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setting = Setting::factory()->create([
            'pos_document_prefix' => 'TST',
            'pos_draft_flow_enabled' => true,
        ]);
        $this->user = User::factory()->create();
        $this->user->settings()->attach($this->setting->id, ['role_id' => 1]);

        Permission::findOrCreate('pos.create', 'web');
        $this->user->givePermissionTo('pos.create');
        
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        $location = Location::factory()->create([
            'setting_id' => $this->setting->id,
        ]);
        SettingSaleLocation::updateOrCreate(
            ['location_id' => $location->id],
            ['setting_id' => $this->setting->id, 'position' => 1]
        );

        $this->posSession = PosSession::factory()->create([
            'user_id' => $this->user->id,
            'setting_id' => $this->setting->id,
            'status' => PosSession::STATUS_ACTIVE,
        ]);
    }

    public function test_can_create_pos_draft_with_allocated_code()
    {
        $payload = [
            'cart' => [
                [
                    'id' => 'SKU-1',
                    'name' => 'Test Item',
                    'qty' => 1,
                    'price' => 1000,
                    'options' => [
                        'product_id' => null,
                        'sub_total' => 1000,
                    ],
                ],
            ],
            'total_amount' => 1000,
        ];

        $response = $this->postJson(route('app.pos.drafts.store'), [
            'payload' => $payload,
        ]);

        $response->assertStatus(201);
        
        $this->assertDatabaseHas('pos_drafts', [
            'setting_id' => $this->setting->id,
            'user_id' => $this->user->id,
            'pos_session_id' => $this->posSession->id,
            'status' => PosDraft::STATUS_AJUKAN_PEMBAYARAN,
        ]);

        $draft = PosDraft::first();
        $this->assertNotNull($draft->document_number);
        $this->assertStringStartsWith('TST-', $draft->document_number);
        $this->assertEquals($payload['cart'], $draft->payload['cart']);
    }
}
