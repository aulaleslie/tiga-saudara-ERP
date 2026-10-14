<?php

namespace Modules\Pos\Tests\Unit;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Customer;
use Modules\Pos\Services\PosChildSaleEligibilityService;
use Modules\Sale\Entities\Sale;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class PosChildSaleEligibilityServiceTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Customer $customer;
    protected Setting $setting;
    protected PosChildSaleEligibilityService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setting = Setting::firstOrCreate(['id' => 1], [
            'company_name' => 'Store 1',
            'company_email' => 's1@example.com',
            'company_phone' => '0811',
            'company_address' => 'Addr 1',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 's1@example.com',
            'footer_text' => 'F1',
            'document_prefix' => 'D1',
            'purchase_prefix_document' => 'P1',
            'sale_prefix_document' => 'S1',
            'pos_enabled' => true,
        ]);

        $this->user = User::factory()->create();
        $this->customer = Customer::create([
            'customer_name' => 'Customer Test',
            'customer_phone' => '081234567890',
            'customer_email' => 'cust@example.com',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Street',
        ]);

        $this->service = new PosChildSaleEligibilityService();
    }

    protected function createSale(array $attributes = []): Sale
    {
        return Sale::create(array_merge([
            'date' => now()->toDateString(),
            'reference' => 'SL-TEST-' . uniqid(),
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 100000,
            'paid_amount' => 0,
            'due_amount' => 100000,
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => Sale::PAYMENT_STATUS_UNPAID,
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id,
        ], $attributes));
    }

    public function test_ordinary_eligible_sales_are_eligible(): void
    {
        $eligibleStatuses = [
            Sale::STATUS_APPROVED,
            Sale::STATUS_DISPATCHED_PARTIALLY,
            Sale::STATUS_DISPATCHED,
            Sale::STATUS_RETURNED_PARTIALLY,
        ];

        foreach ($eligibleStatuses as $status) {
            $sale = $this->createSale(['status' => $status]);
            $this->assertTrue(
                $this->service->isEligible($sale),
                "Unarchived Sale with status {$status} must be eligible."
            );
            $this->assertTrue(
                PosChildSaleEligibilityService::check($sale),
                "Static check for unarchived Sale with status {$status} must be eligible."
            );
        }
    }

    public function test_unarchived_returned_zero_sale_is_eligible(): void
    {
        $sale = $this->createSale([
            'status' => Sale::STATUS_RETURNED,
            'total_amount' => 0,
            'paid_amount' => 0,
            'due_amount' => 0,
        ]);

        $this->assertNull($sale->archived_at);
        $this->assertTrue(
            $this->service->isEligible($sale),
            'Unarchived fully returned Sale with zero total and zero due must be eligible.'
        );
    }

    public function test_archived_returned_zero_sale_is_eligible(): void
    {
        $sale = $this->createSale([
            'status' => Sale::STATUS_RETURNED,
            'total_amount' => 0,
            'paid_amount' => 0,
            'due_amount' => 0,
            'archived_at' => now(),
            'archived_by' => $this->user->id,
        ]);

        $this->assertNotNull($sale->archived_at);
        $this->assertTrue(
            $this->service->isEligible($sale),
            'Archived fully returned Sale with zero total and zero due must be eligible.'
        );
    }

    public function test_returned_sale_with_residual_total_or_debt_is_not_eligible(): void
    {
        // Residual total with zero due
        $saleWithTotal = $this->createSale([
            'status' => Sale::STATUS_RETURNED,
            'total_amount' => 50000,
            'paid_amount' => 50000,
            'due_amount' => 0,
        ]);
        $this->assertFalse(
            $this->service->isEligible($saleWithTotal, 0.0, 50000.0),
            'Returned Sale with positive total must not be eligible.'
        );

        // Zero total with positive due
        $saleWithDue = $this->createSale([
            'status' => Sale::STATUS_RETURNED,
            'total_amount' => 0,
            'paid_amount' => 0,
            'due_amount' => 50000,
        ]);
        $this->assertFalse(
            $this->service->isEligible($saleWithDue, 50000.0, 0.0),
            'Returned Sale with positive due must not be eligible.'
        );

        // Both total and due positive
        $saleWithBoth = $this->createSale([
            'status' => Sale::STATUS_RETURNED,
            'total_amount' => 50000,
            'paid_amount' => 0,
            'due_amount' => 50000,
        ]);
        $this->assertFalse(
            $this->service->isEligible($saleWithBoth, 50000.0, 50000.0),
            'Returned Sale with residual total and debt must not be eligible.'
        );
    }

    public function test_unrelated_archived_or_rejected_zero_value_sales_are_not_eligible(): void
    {
        // REJECTED with zero total and zero due
        $rejectedSale = $this->createSale([
            'status' => Sale::STATUS_REJECTED,
            'total_amount' => 0,
            'paid_amount' => 0,
            'due_amount' => 0,
        ]);
        $this->assertFalse(
            $this->service->isEligible($rejectedSale, 0.0, 0.0),
            'REJECTED Sale must not qualify for the zero-value exception.'
        );

        // Archived APPROVED with zero total and zero due
        $archivedApprovedSale = $this->createSale([
            'status' => Sale::STATUS_APPROVED,
            'total_amount' => 0,
            'paid_amount' => 0,
            'due_amount' => 0,
            'archived_at' => now(),
            'archived_by' => $this->user->id,
        ]);
        $this->assertFalse(
            $this->service->isEligible($archivedApprovedSale, 0.0, 0.0),
            'Archived ordinary Sale must not be eligible even if total and live due are zero.'
        );

        // Archived DISPATCHED with zero total and zero due
        $archivedDispatchedSale = $this->createSale([
            'status' => Sale::STATUS_DISPATCHED,
            'total_amount' => 0,
            'paid_amount' => 0,
            'due_amount' => 0,
            'archived_at' => now(),
            'archived_by' => $this->user->id,
        ]);
        $this->assertFalse(
            $this->service->isEligible($archivedDispatchedSale, 0.0, 0.0),
            'Archived DISPATCHED Sale must not be eligible even if total and live due are zero.'
        );
    }

    public function test_explicit_live_due_and_total_amount_parameters_take_precedence(): void
    {
        $sale = $this->createSale([
            'status' => Sale::STATUS_RETURNED,
            'total_amount' => 100000,
            'paid_amount' => 0,
            'due_amount' => 100000,
        ]);

        // When explicit zero values are supplied (e.g. from projection's canonical calculation)
        $this->assertTrue(
            $this->service->isEligible($sale, 0.0, 0.0),
            'Explicit zero parameters must qualify a RETURNED sale.'
        );

        // When explicit non-zero values are supplied
        $this->assertFalse(
            $this->service->isEligible($sale, 0.0, 1000.0),
            'Explicit non-zero total must disqualify a RETURNED sale.'
        );
    }
}
