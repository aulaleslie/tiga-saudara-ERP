<?php

namespace Modules\Pos\Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class POSReturnRouteAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected $user;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->user = User::factory()->create();
        
        // Ensure permissions exist
        Permission::findOrCreate('pos.access', 'web');
        Permission::findOrCreate('pos.returns.view', 'web');
        Permission::findOrCreate('pos.returns.create', 'web');
    }

    /** @test */
    public function guest_cannot_access_pos_returns()
    {
        $this->get(route('pos.returns.index'))->assertRedirect(route('login'));
    }

    /** @test */
    public function user_without_permission_cannot_access_pos_returns()
    {
        $this->actingAs($this->user);
        $this->get(route('pos.returns.index'))->assertStatus(403);
    }

    /** @test */
    public function user_with_permission_can_access_pos_returns_index()
    {
        $this->user->givePermissionTo(['pos.access', 'pos.returns.view']);
        
        $this->actingAs($this->user);
        $this->get(route('pos.returns.index'))->assertStatus(200);
    }

    /** @test */
    public function user_without_create_permission_cannot_access_pos_returns_create()
    {
        $this->user->givePermissionTo(['pos.access', 'pos.returns.view']);
        
        $this->actingAs($this->user);
        $this->get(route('pos.returns.create'))->assertStatus(403);
    }

    /** @test */
    public function user_with_create_permission_can_access_pos_returns_create()
    {
        $this->user->givePermissionTo(['pos.access', 'pos.returns.view', 'pos.returns.create']);
        
        $this->actingAs($this->user);
        $this->get(route('pos.returns.create'))->assertStatus(200);
    }
}
