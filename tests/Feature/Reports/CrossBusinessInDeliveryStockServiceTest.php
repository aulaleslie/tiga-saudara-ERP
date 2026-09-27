<?php

namespace Tests\Feature\Reports;

use App\Services\Reports\CrossBusinessInDeliveryStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Product\Entities\Product;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Entities\ReceivedNote;
use Modules\Purchase\Entities\ReceivedNoteDetail;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class CrossBusinessInDeliveryStockServiceTest extends TestCase
{
    use RefreshDatabase;

    private Setting $biz1;
    private Setting $biz2;
    private Location $loc1;
    private Location $loc2;
    private Product $productA;
    private Product $productB;
    private CrossBusinessInDeliveryStockService $service;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('currencies')->insert([
            'id' => 1,
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->biz1 = Setting::create([
            'company_name' => 'Bisnis 1',
            'company_email' => 'biz1@example.com',
            'company_phone' => '111',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 'biz1@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Address 1',
        ]);

        $this->biz2 = Setting::create([
            'company_name' => 'Bisnis 2',
            'company_email' => 'biz2@example.com',
            'company_phone' => '222',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 'biz2@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Address 2',
        ]);

        $this->loc1 = Location::create([
            'name' => 'Gudang 1',
            'setting_id' => $this->biz1->id,
            'is_active' => true,
        ]);

        $this->loc2 = Location::create([
            'name' => 'Gudang 2',
            'setting_id' => $this->biz2->id,
            'is_active' => true,
        ]);

        $this->productA = Product::create([
            'setting_id' => $this->biz1->id,
            'product_name' => 'Produk A',
            'product_code' => 'PRD-A',
            'product_cost' => 1000,
            'product_price' => 2000,
            'product_unit' => 'pc',
            'stock_managed' => true,
            'is_active' => true,
        ]);

        $this->productB = Product::create([
            'setting_id' => $this->biz1->id,
            'product_name' => 'Produk B',
            'product_code' => 'PRD-B',
            'product_cost' => 1000,
            'product_price' => 2000,
            'product_unit' => 'pc',
            'stock_managed' => true,
            'is_active' => true,
        ]);

        $this->service = new CrossBusinessInDeliveryStockService();
    }

    private function createPurchase(Setting $setting, string $status, ?string $archivedAt = null): Purchase
    {
        return Purchase::create([
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(7)->format('Y-m-d'),
            'reference' => 'PO-' . uniqid(),
            'status' => $status,
            'setting_id' => $setting->id,
            'total_amount' => 10000,
            'paid_amount' => 0,
            'due_amount' => 10000,
            'payment_method' => 'Cash',
            'payment_status' => Purchase::PAYMENT_STATUS_UNPAID,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'is_tax_included' => false,
            'archived_at' => $archivedAt,
        ]);
    }

    private function createPurchaseDetail(Purchase $purchase, Product $product, float $quantity): PurchaseDetail
    {
        return PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => $quantity,
            'unit_price' => 1000,
            'price' => 1000,
            'sub_total' => $quantity * 1000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);
    }

    private function createReceivedNote(Purchase $purchase, Location $location, string $status): ReceivedNote
    {
        return ReceivedNote::create([
            'po_id' => $purchase->id,
            'location_id' => $location->id,
            'external_delivery_number' => 'DN-' . uniqid(),
            'date' => now()->format('Y-m-d'),
            'status' => $status,
        ]);
    }

    private function createReceivedNoteDetail(ReceivedNote $rn, PurchaseDetail $poDetail, float $quantityReceived): ReceivedNoteDetail
    {
        return ReceivedNoteDetail::create([
            'received_note_id' => $rn->id,
            'po_detail_id' => $poDetail->id,
            'quantity_received' => $quantityReceived,
        ]);
    }

    /** @test */
    public function it_aggregates_approved_unreceived_and_partially_received_purchases()
    {
        // PO 1: Approved, 10 units unreceived
        $po1 = $this->createPurchase($this->biz1, Purchase::STATUS_APPROVED);
        $this->createPurchaseDetail($po1, $this->productA, 10.0);

        // PO 2: Received Partially, 10 ordered, 3 approved received => 7 outstanding
        $po2 = $this->createPurchase($this->biz1, Purchase::STATUS_RECEIVED_PARTIALLY);
        $d2 = $this->createPurchaseDetail($po2, $this->productA, 10.0);
        $rn2 = $this->createReceivedNote($po2, $this->loc1, ReceivedNote::STATUS_APPROVED);
        $this->createReceivedNoteDetail($rn2, $d2, 3.0);

        $matrix = $this->service->getInDeliveryMatrix([$this->productA->id], [$this->biz1->id]);

        // Total for productA in biz1 = 10 + 7 = 17
        $this->assertEquals(17.0, $matrix[$this->productA->id][$this->biz1->id]);
    }

    /** @test */
    public function it_ignores_unapproved_receiving_notes()
    {
        // PO: Received partially, 10 ordered
        $po = $this->createPurchase($this->biz1, Purchase::STATUS_RECEIVED_PARTIALLY);
        $detail = $this->createPurchaseDetail($po, $this->productA, 10.0);

        // Pending RN with 4 units received: should NOT be subtracted
        $rnPending = $this->createReceivedNote($po, $this->loc1, ReceivedNote::STATUS_PENDING);
        $this->createReceivedNoteDetail($rnPending, $detail, 4.0);

        // Rejected RN with 2 units received: should NOT be subtracted
        $rnRejected = $this->createReceivedNote($po, $this->loc1, ReceivedNote::STATUS_REJECTED);
        $this->createReceivedNoteDetail($rnRejected, $detail, 2.0);

        $matrix = $this->service->getInDeliveryMatrix([$this->productA->id], [$this->biz1->id]);

        $this->assertEquals(10.0, $matrix[$this->productA->id][$this->biz1->id]);
    }

    /** @test */
    public function it_excludes_archived_and_ineligible_status_purchases()
    {
        // Archived approved purchase
        $poArchived = $this->createPurchase($this->biz1, Purchase::STATUS_APPROVED, now()->toDateTimeString());
        $this->createPurchaseDetail($poArchived, $this->productA, 10.0);

        // Drafted purchase
        $poDraft = $this->createPurchase($this->biz1, Purchase::STATUS_DRAFTED);
        $this->createPurchaseDetail($poDraft, $this->productA, 10.0);

        // Waiting approval purchase
        $poWaiting = $this->createPurchase($this->biz1, Purchase::STATUS_WAITING_APPROVAL);
        $this->createPurchaseDetail($poWaiting, $this->productA, 10.0);

        // Fully received purchase
        $poReceived = $this->createPurchase($this->biz1, Purchase::STATUS_RECEIVED);
        $this->createPurchaseDetail($poReceived, $this->productA, 10.0);

        // Rejected purchase
        $poRejected = $this->createPurchase($this->biz1, Purchase::STATUS_REJECTED);
        $this->createPurchaseDetail($poRejected, $this->productA, 10.0);

        $matrix = $this->service->getInDeliveryMatrix([$this->productA->id], [$this->biz1->id]);

        $this->assertEmpty($matrix);
    }

    /** @test */
    public function it_clamps_over_received_details_to_zero_so_they_do_not_offset_other_details()
    {
        $po = $this->createPurchase($this->biz1, Purchase::STATUS_RECEIVED_PARTIALLY);

        // Detail 1: ordered 10, received 12 (over-received by 2) -> clamped to 0
        $d1 = $this->createPurchaseDetail($po, $this->productA, 10.0);
        $rn1 = $this->createReceivedNote($po, $this->loc1, ReceivedNote::STATUS_APPROVED);
        $this->createReceivedNoteDetail($rn1, $d1, 12.0);

        // Detail 2: ordered 5, received 0 -> 5 outstanding
        $d2 = $this->createPurchaseDetail($po, $this->productA, 5.0);

        $matrix = $this->service->getInDeliveryMatrix([$this->productA->id], [$this->biz1->id]);

        // Must be 0 + 5 = 5 (NOT 5 - 2 = 3)
        $this->assertEquals(5.0, $matrix[$this->productA->id][$this->biz1->id]);
    }

    /** @test */
    public function it_handles_multi_business_and_fractional_quantities()
    {
        // Biz 1: Product A ordered 15.75, received 5.25 -> 10.50
        $po1 = $this->createPurchase($this->biz1, Purchase::STATUS_RECEIVED_PARTIALLY);
        $d1 = $this->createPurchaseDetail($po1, $this->productA, 15.75);
        $rn1 = $this->createReceivedNote($po1, $this->loc1, ReceivedNote::STATUS_APPROVED);
        $this->createReceivedNoteDetail($rn1, $d1, 5.25);

        // Biz 2: Product A ordered 8.125 -> 8.125
        $po2 = $this->createPurchase($this->biz2, Purchase::STATUS_APPROVED);
        $this->createPurchaseDetail($po2, $this->productA, 8.125);

        // Biz 2: Product B ordered 20.0 -> 20.0
        $po3 = $this->createPurchase($this->biz2, Purchase::STATUS_APPROVED);
        $this->createPurchaseDetail($po3, $this->productB, 20.0);

        // Query only biz1
        $matrixBiz1 = $this->service->getInDeliveryMatrix([$this->productA->id, $this->productB->id], [$this->biz1->id]);
        $this->assertEquals(10.5, $matrixBiz1[$this->productA->id][$this->biz1->id]);
        $this->assertArrayNotHasKey($this->productB->id, $matrixBiz1);

        // Query both businesses
        $matrixBoth = $this->service->getInDeliveryMatrix([$this->productA->id, $this->productB->id], [$this->biz1->id, $this->biz2->id]);
        $this->assertEquals(10.5, $matrixBoth[$this->productA->id][$this->biz1->id]);
        $this->assertEquals(8.125, $matrixBoth[$this->productA->id][$this->biz2->id]);
        $this->assertEquals(20.0, $matrixBoth[$this->productB->id][$this->biz2->id]);
    }
}
