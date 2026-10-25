<?php

namespace Modules\Pos\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Modules\People\Entities\Customer;
use Modules\Sale\Entities\Sale;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class PosReturnRoundingMigrationAndModelTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting;
    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setting = Setting::factory()->create([
            'company_name' => 'Store 1',
        ]);

        $this->customer = Customer::factory()->create([
            'customer_name' => 'Walk-in Customer',
        ]);
    }

    /** @test */
    public function it_has_nullable_return_rounding_context_column_on_sales_table(): void
    {
        $this->assertTrue(Schema::hasColumn('sales', 'return_rounding_context'));
    }

    /** @test */
    public function legacy_sales_load_with_null_return_rounding_context(): void
    {
        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'date' => now()->toDateString(),
            'total_amount' => 100000,
            'paid_amount' => 100000,
            'due_amount' => 0,
            'status' => 'Completed',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
        ]);

        $this->assertNull($sale->return_rounding_context);

        $reloaded = Sale::find($sale->id);
        $this->assertNull($reloaded->return_rounding_context);
    }

    /** @test */
    public function it_casts_return_rounding_context_to_array(): void
    {
        $context = [
            'policy_version' => 1,
            'pos_business_id' => $this->setting->id,
            'frozen_increment' => 100.0,
            'created_at' => now()->toIso8601String(),
            'created_by' => 1,
            'commercial_lines' => [
                '10' => [
                    'baseline_quantity' => 120.0,
                    'baseline_returnable_amount' => 790000.0,
                    'committed_quantity' => 1.0,
                    'committed_amount' => 6600.0,
                ],
            ],
        ];

        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'date' => now()->toDateString(),
            'total_amount' => 790000,
            'paid_amount' => 790000,
            'due_amount' => 0,
            'status' => 'Completed',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'return_rounding_context' => $context,
        ]);

        $this->assertIsArray($sale->return_rounding_context);
        $this->assertSame(1, $sale->return_rounding_context['policy_version']);
        $this->assertEquals(100.0, (float) $sale->return_rounding_context['frozen_increment']);

        $reloaded = Sale::find($sale->id);
        $this->assertIsArray($reloaded->return_rounding_context);
        $this->assertEquals(790000.0, (float) $reloaded->return_rounding_context['commercial_lines']['10']['baseline_returnable_amount']);
    }

    /** @test */
    public function migration_down_drops_column_and_up_recreates_it(): void
    {
        $migration = require base_path('Modules/Pos/Database/Migrations/2026_10_24_000001_add_return_rounding_context_to_sales_table.php');

        $migration->down();
        $this->assertFalse(Schema::hasColumn('sales', 'return_rounding_context'));

        $migration->up();
        $this->assertTrue(Schema::hasColumn('sales', 'return_rounding_context'));
    }
}
