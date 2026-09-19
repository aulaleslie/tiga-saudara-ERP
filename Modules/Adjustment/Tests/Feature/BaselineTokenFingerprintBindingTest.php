<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Adjustment\Services\AdjustmentProductResolver;
use Modules\Adjustment\Services\CountDraftService;
use Modules\Currency\Entities\Currency;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class BaselineTokenFingerprintBindingTest extends TestCase
{
    use RefreshDatabase;

    private AdjustmentProductResolver $resolver;
    private User $user;
    private Setting $setting;
    private Location $locationA;
    private Location $locationB;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = app(AdjustmentProductResolver::class);
        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $currency = Currency::firstOrCreate(
            ['code' => 'IDR'],
            [
                'currency_name' => 'Rupiah',
                'symbol' => 'RP',
                'thousand_separator' => '.',
                'decimal_separator' => ',',
                'exchange_rate' => 1,
            ]
        );

        $this->setting = Setting::create([
            'company_name' => 'Test Business',
            'company_email' => 'test@test.test',
            'company_phone' => '0800000000',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'test@test.test',
            'footer_text' => 'Footer',
            'company_address' => 'Bandung',
            'is_pkp' => true,
        ]);

        $this->locationA = Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'Gudang A',
            'is_active' => true,
            'is_consignment' => false,
        ]);

        $this->locationB = Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'Gudang B',
            'is_active' => true,
            'is_consignment' => false,
        ]);

        $this->product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Test Product',
            'product_code' => 'TP001',
            'barcode' => 'BC001',
            'product_cost' => 1000,
            'product_price' => 2000,
            'product_quantity' => 10,
            'product_stock_alert' => 1,
            'stock_managed' => true,
            'serial_number_required' => false,
        ]);

        ProductStock::create([
            'product_id' => $this->product->id,
            'location_id' => $this->locationA->id,
            'quantity' => 15,
            'quantity_tax' => 15,
            'quantity_non_tax' => 0,
            'broken_quantity' => 3,
            'broken_quantity_tax' => 3,
            'broken_quantity_non_tax' => 0,
        ]);
    }

    /** @test */
    public function baseline_signature_and_token_bind_to_location_set_fingerprint(): void
    {
        $fingerprint = CountDraftService::locationSetFingerprint([$this->locationA->id, $this->locationB->id]);

        $baseline = $this->resolver->captureBaseline(
            $this->product->id,
            $this->locationA->id,
            $this->user->id,
            'session-1',
            null,
            $fingerprint
        );

        $this->assertNotEmpty($baseline['token']);
        $this->assertNotEmpty($baseline['signature']);

        // Signature verification succeeds with matching fingerprint
        $this->assertTrue(
            $this->resolver->verifyBaselineSignature(
                $baseline,
                $this->product->id,
                $this->locationA->id,
                $fingerprint
            )
        );

        // Retrieval succeeds with matching fingerprint
        $retrieved = $this->resolver->getBaselineSnapshot(
            $baseline['token'],
            $this->product->id,
            $this->locationA->id,
            $this->user->id,
            'session-1',
            null,
            $fingerprint
        );
        $this->assertNotNull($retrieved);
        $this->assertEquals(15, $retrieved['existing_good_total']);
    }

    /** @test */
    public function baseline_signature_and_token_reject_mismatched_location_set_fingerprint(): void
    {
        $fingerprintPool1 = CountDraftService::locationSetFingerprint([$this->locationA->id, $this->locationB->id]);
        $fingerprintPool2 = CountDraftService::locationSetFingerprint([$this->locationA->id]); // Different pool

        $baseline = $this->resolver->captureBaseline(
            $this->product->id,
            $this->locationA->id,
            $this->user->id,
            'session-1',
            null,
            $fingerprintPool1
        );

        // Signature verification fails with different fingerprint
        $this->assertFalse(
            $this->resolver->verifyBaselineSignature(
                $baseline,
                $this->product->id,
                $this->locationA->id,
                $fingerprintPool2
            )
        );

        // Snapshot retrieval returns null for mismatched fingerprint
        $retrieved = $this->resolver->getBaselineSnapshot(
            $baseline['token'],
            $this->product->id,
            $this->locationA->id,
            $this->user->id,
            'session-1',
            null,
            $fingerprintPool2
        );
        $this->assertNull($retrieved, 'Snapshot must return null when replayed with a different location pool');
    }

    /** @test */
    public function invalid_or_expired_token_returns_null_without_disclosing_stock(): void
    {
        $retrieved = $this->resolver->getBaselineSnapshot(
            'non-existent-token-' . uniqid(),
            $this->product->id,
            $this->locationA->id,
            $this->user->id
        );

        $this->assertNull($retrieved);
    }
}
