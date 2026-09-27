<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Log;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Modules\People\Entities\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerLoggingTest extends TestCase
{
    use RefreshDatabase;

    public function test_logs_debug_and_error_when_saving_customer_fails()
    {
        Permission::firstOrCreate(['name' => 'customers.create']);
        Permission::firstOrCreate(['name' => 'customers.edit']);
        Permission::firstOrCreate(['name' => 'customers.access']);
        
        $user = User::factory()->create();
        $user->givePermissionTo(['customers.create', 'customers.edit', 'customers.access']);
        $this->actingAs($user);

        // Using session for setting_id as required by the controller
        session(['setting_id' => 1]);

        // Force an exception during save
        Customer::saving(function ($model) {
            throw new \Exception("Simulated exception during save");
        });

        // We can spy on the Log facade
        Log::spy();

        // Store test
        $response = $this->post(route('customers.store'), [
            'customer_name' => 'Dummy Name', 'contact_name' => 'Store Error Tester',
            'customer_phone' => '123123123',
        ]);
        
        // The controller catches it and redirects back
        $response->assertRedirect();

        // Verify correct logs were called
        Log::shouldHaveReceived('debug')->withArgs(function($message, $context) {
            return str_contains($message, 'Attempting to create customer') && isset($context['payload']);
        })->once();
        
        Log::shouldHaveReceived('error')->withArgs(function($message, $context) {
            return str_contains($message, 'Customer creation failed') && $context['error'] === 'Simulated exception during save';
        })->once();
    }
}
