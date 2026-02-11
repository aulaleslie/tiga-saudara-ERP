<?php

namespace Modules\Sale\Tests\Feature;

use App\Models\PosReceipt;
use App\Models\PosSession;
use App\Models\User;
use Modules\Sale\Entities\Sale;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\SettingSaleLocation;

class PosReceiptAuthorizationAndTraceabilityTest extends PosDraftFeatureTestCase
{
    public function test_print_route_forbids_receipt_from_other_setting(): void
    {
        $receipt = $this->createReceiptWithSales($this->posSession->id, ['SL-TRACE-001']);

        $otherSetting = Setting::factory()->create();
        $otherLocation = Location::factory()->create(['setting_id' => $otherSetting->id]);
        SettingSaleLocation::updateOrCreate(
            ['location_id' => $otherLocation->id],
            ['setting_id' => $otherSetting->id, 'position' => 1]
        );

        $otherUser = User::factory()->create();
        $otherUser->settings()->attach($otherSetting->id, ['role_id' => 1]);
        $this->syncPermissions($otherUser, ['pos.transactions.access', 'pos.access']);

        $this->actingAs($otherUser);
        session(['setting_id' => $otherSetting->id]);

        $this->get(route('pos.receipt.print', $receipt->id))->assertForbidden();
    }

    public function test_print_route_shows_linked_sale_references(): void
    {
        $receipt = $this->createReceiptWithSales($this->posSession->id, ['SL-TRACE-A', 'SL-TRACE-B']);

        $response = $this->get(route('pos.receipt.print', $receipt->id));
        $response->assertOk();
        $response->assertSee('Ref Penjualan');
        $response->assertSee('SL-TRACE-A');
        $response->assertSee('SL-TRACE-B');
    }

    public function test_transactions_history_filters_by_session_id(): void
    {
        $otherSession = PosSession::create([
            'user_id' => $this->user->id,
            'setting_id' => $this->setting->id,
            'location_id' => $this->location->id,
            'device_name' => 'SECOND SESSION',
            'cash_float' => 0,
            'expected_cash' => 0,
            'status' => PosSession::STATUS_ACTIVE,
            'started_at' => now(),
        ]);

        $receiptA = $this->createReceiptWithSales($this->posSession->id, ['SL-HISTORY-A']);
        $receiptB = $this->createReceiptWithSales($otherSession->id, ['SL-HISTORY-B']);

        $filteredReceiptNumbers = PosReceipt::query()
            ->whereHas('sales', function ($query) {
                $query->where('setting_id', $this->setting->id);
            })
            ->where('pos_session_id', $this->posSession->id)
            ->pluck('receipt_number')
            ->all();

        $this->assertContains($receiptA->receipt_number, $filteredReceiptNumbers);
        $this->assertNotContains($receiptB->receipt_number, $filteredReceiptNumbers);
    }

    private function createReceiptWithSales(int $posSessionId, array $saleReferences): PosReceipt
    {
        $receipt = PosReceipt::create([
            'receipt_number' => 'PDT-TRACE-' . strtoupper(substr(md5((string) microtime(true)), 0, 6)),
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'total_amount' => 100000,
            'paid_amount' => 100000,
            'due_amount' => 0,
            'change_due' => 0,
            'payment_status' => 'Paid',
            'payment_method' => $this->cashMethod->name,
            'payment_breakdown' => [],
            'note' => null,
            'pos_session_id' => $posSessionId,
        ]);

        foreach ($saleReferences as $index => $reference) {
            Sale::create([
                'setting_id' => $this->setting->id,
                'date' => now()->format('Y-m-d'),
                'reference' => $reference,
                'customer_id' => $this->customer->id,
                'customer_name' => $this->customer->customer_name,
                'tax_percentage' => 0,
                'discount_percentage' => 0,
                'shipping_amount' => 0,
                'total_amount' => 50000 + ($index * 1000),
                'paid_amount' => 50000 + ($index * 1000),
                'due_amount' => 0,
                'status' => Sale::STATUS_DISPATCHED,
                'payment_status' => 'Paid',
                'payment_method' => $this->cashMethod->name,
                'pos_receipt_id' => $receipt->id,
                'pos_session_id' => $posSessionId,
            ]);
        }

        return $receipt;
    }
}
