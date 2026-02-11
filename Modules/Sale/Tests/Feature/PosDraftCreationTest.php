<?php

namespace Modules\Sale\Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\PosSession;
use Modules\Setting\Entities\Setting;
use Modules\Sale\Entities\PosDraft;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

class PosDraftCreationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setting = Setting::factory()->create([
            'pos_document_prefix' => 'TST'
        ]);
        $this->user = User::factory()->create();
        $this->user->settings()->attach($this->setting->id, ['role_id' => 1]);
        
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        $this->posSession = PosSession::factory()->create([
            'user_id' => $this->user->id,
            'setting_id' => $this->setting->id,
            'status' => PosSession::STATUS_ACTIVE,
        ]);
    }

    public function test_can_create_pos_draft_with_allocated_code()
    {
        $payload = [
            'customer_id' => 1,
            'items' => [
                ['id' => 1, 'qty' => 1, 'price' => 1000]
            ],
            'total' => 1000
        ];

        $response = $this->post(route('app.pos.drafts.store'), [
            'payload' => $payload
        ]);

        $response->assertStatus(201);
        
        $this->assertDatabaseHas('pos_drafts', [
            'setting_id' => $this->setting->id,
            'user_id' => $this->user->id,
            'pos_session_id' => $this->posSession->id,
            'status' => PosDraft::STATUS_OPEN,
        ]);

        $draft = PosDraft::first();
        $this->assertNotNull($draft->document_number);
        $this->assertStringStartsWith('TST-', $draft->document_number);
        $this->assertEquals($payload, $draft->payload);
    }
}
