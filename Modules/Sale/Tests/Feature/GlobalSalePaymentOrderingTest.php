<?php

namespace Modules\Sale\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\People\Entities\Customer;
use Modules\Sale\Entities\Sale;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class GlobalSalePaymentOrderingTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting;
    protected Customer $customer;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('PRAGMA foreign_keys = OFF');

        $this->setting = Setting::factory()->create();
        session(['setting_id' => $this->setting->id]);

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        Gate::define('salePayments.global.access', fn () => true);
        Gate::define('salePayments.create', fn () => true);

        $this->customer = Customer::factory()->create();
    }

    private function createSale(array $attributes = []): Sale
    {
        return Sale::create(array_merge([
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'reference' => 'SO-' . uniqid(),
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->canonical_name,
            'payment_method' => 'Cash',
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'total_amount' => 10000,
            'paid_amount' => 0,
            'due_amount' => 10000,
            'setting_id' => $this->setting->id,
        ], $attributes));
    }

    public function test_create_form_pins_entry_sale_and_orders_remaining_by_due_date_and_id_with_nulls_last()
    {
        // Entry sale with late due date
        $entrySale = $this->createSale([
            'due_date' => '2026-10-01',
            'note' => 'Entry sale note',
        ]);

        // Sale with earlier due date
        $earlierSale = $this->createSale([
            'due_date' => '2026-09-01',
            'note' => 'Earlier sale note',
        ]);

        // Undated sale
        $undatedSale = $this->createSale([
            'due_date' => null,
            'note' => 'Undated sale note',
        ]);

        // Same due date sales to verify ID tie-breaking
        $tieSale1 = $this->createSale([
            'due_date' => '2026-09-15',
        ]);
        $tieSale2 = $this->createSale([
            'due_date' => '2026-09-15',
        ]);

        $response = $this->get(route('sales.global-payments.create', $entrySale->id));
        $response->assertOk();

        /** @var \Illuminate\Database\Eloquent\Collection $candidates */
        $candidates = $response->viewData('candidates');

        // Verify row ordering
        $this->assertEquals([
            $entrySale->id,
            $earlierSale->id,
            min($tieSale1->id, $tieSale2->id),
            max($tieSale1->id, $tieSale2->id),
            $undatedSale->id,
        ], $candidates->pluck('id')->all());

        // Verify escaped note rendering for candidates
        $response->assertSee('Entry sale note');
        $response->assertSee('Earlier sale note');
        $response->assertSee('Undated sale note');

        // Verify starting allocation default is full balance for entry sale, 0 for others
        $response->assertSee('name="allocations[' . $entrySale->id . ']"', false);
        $response->assertSee('value="10000"', false);
        $response->assertSee('name="allocations[' . $earlierSale->id . ']"', false);
        $response->assertSee('value="0"', false);
    }
}
