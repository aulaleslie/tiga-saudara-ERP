<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Customer;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosCheckoutSale;
use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Entities\PosReturnLine;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Services\PosCartService;
use Modules\Pos\Services\PosCartTotalsCalculator;
use Modules\Pos\Services\PosReturnSnapshotService;
use Modules\Pos\Services\PosReturnSubmissionService;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Tests\TestCase;

class PosRowTotalRoundingTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Setting $setting;
    protected PosSession $session;
    protected Product $product;
    protected PosCartService $cartService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setting = Setting::factory()->create([
            'company_name' => 'PT Test POS',
            'company_email' => 'test@pos.com',
            'company_phone' => '081234567890',
            'notification_email' => 'notify@pos.com',
            'is_pkp' => false,
            'row_total_rounding_increment' => 100.00,
        ]);

        $this->user = User::factory()->create();
        \Spatie\Permission\Models\Permission::findOrCreate('pos.transactions.save', 'web');
        \Spatie\Permission\Models\Permission::findOrCreate('pos.transactions.load', 'web');
        $this->user->givePermissionTo(['pos.transactions.save', 'pos.transactions.load']);

        $terminal = PosTerminal::create([
            'setting_id' => $this->setting->id,
            'name' => 'Terminal 1',
            'code' => 'T1',
            'is_active' => true,
        ]);

        $this->session = PosSession::create([
            'setting_id' => $this->setting->id,
            'terminal_id' => $terminal->id,
            'cashier_user_id' => $this->user->id,
            'status' => PosSession::STATUS_OPEN,
            'opened_at' => now(),
            'opened_by' => $this->user->id,
            'opening_float_total' => 100000,
            'expected_cash_total' => 100000,
            'counted_cash_total' => 100000,
            'variance_total' => 0,
            'active_marker' => 1,
        ]);

        $unit = Unit::create([
            'name' => 'Pcs',
            'short_name' => 'pcs',
            'operator' => '*',
            'operation_value' => 1,
        ]);

        $category = Category::create([
            'setting_id' => $this->setting->id,
            'category_code' => 'CAT-POS',
            'category_name' => 'Category POS',
            'created_by' => $this->user->id,
        ]);

        $this->product = Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $category->id,
            'product_name' => 'Barang POS',
            'product_code' => 'BRG-POS',
            'product_quantity' => 100,
            'product_cost' => 50000,
            'product_price' => 78999.96,
            'product_unit' => $unit->id,
            'is_active' => true,
            'is_sold' => true,
            'stock_managed' => true,
        ]);

        ProductPrice::create([
            'product_id' => $this->product->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 78960.00,
        ]);

        $location = \Modules\Setting\Entities\Location::create([
            'name' => 'POS Location',
            'setting_id' => $this->setting->id,
        ]);
        \Modules\Setting\Entities\SettingSaleLocation::create([
            'setting_id' => $this->setting->id,
            'location_id' => $location->id,
            'is_enabled' => true,
        ]);
        \Modules\Product\Entities\ProductStock::withoutEvents(fn () => \Modules\Product\Entities\ProductStock::create([
            'product_id' => $this->product->id,
            'location_id' => $location->id,
            'quantity' => 100,
            'quantity_non_tax' => 100,
            'quantity_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
        ]));
        \App\Support\SalesLocationResolver::forget($this->setting->id);

        $this->cartService = app(PosCartService::class);
    }

    public function test_pos_cart_totals_calculator_rounds_automatic_row_total(): void
    {
        $calculator = new PosCartTotalsCalculator();

        $snapshot = $calculator->calculate(
            lines: [
                [
                    'line_id' => 1,
                    'qty' => 1,
                    'unit_price' => 78999.96,
                    'price_source' => 'BASE',
                ],
            ],
            billDiscount: ['type' => 'fixed', 'value' => 0],
            isPkp: false,
            settingId: $this->setting->id
        );

        $this->assertEquals(79000.00, $snapshot['lines'][0]['line_subtotal']);
        $this->assertEquals(79000.00, $snapshot['totals']['grand_total']);
    }

    public function test_pos_cart_totals_calculator_bypasses_row_override(): void
    {
        $calculator = new PosCartTotalsCalculator();

        $snapshot = $calculator->calculate(
            lines: [
                [
                    'line_id' => 1,
                    'qty' => 1,
                    'unit_price' => 78999.96,
                    'price_source' => 'LINE_TOTAL_OVERRIDE',
                    'line_gross_minor' => 7899996,
                    'line_discount_minor' => 0,
                    'line_net_minor' => 7899996,
                ],
            ],
            billDiscount: ['type' => 'fixed', 'value' => 0],
            isPkp: false,
            settingId: $this->setting->id
        );

        $this->assertEquals(78999.96, $snapshot['lines'][0]['line_subtotal']);
        $this->assertEquals(78999.96, $snapshot['totals']['grand_total']);
    }

    public function test_pos_cart_totals_calculator_rounds_packed_automatic_row_total(): void
    {
        $calculator = new PosCartTotalsCalculator();

        $snapshot = $calculator->calculate(
            lines: [
                [
                    'line_id' => 1,
                    'qty' => 1,
                    'unit_price' => 78999.96,
                    'price_source' => 'PACKED',
                    'line_total' => 7899996, // 78999.96 in minor units
                ],
            ],
            billDiscount: ['type' => 'fixed', 'value' => 0],
            isPkp: false,
            settingId: $this->setting->id
        );

        $this->assertEquals(79000.00, $snapshot['lines'][0]['line_subtotal']);
        $this->assertEquals(79000.00, $snapshot['totals']['grand_total']);
    }

    public function test_loaded_draft_row_survives_an_increment_change_until_an_interaction(): void
    {
        // 1. A draft row committed under increment 50 stores 78,950.
        $this->setting->update(['row_total_rounding_increment' => 50]);

        $calculator = new PosCartTotalsCalculator();
        $line = [
            'line_id' => 1,
            'qty' => 1,
            'unit_price' => 78950.00,
            'price_source' => 'BASE',
        ];

        $committed = $calculator->calculate(
            lines: [$line],
            billDiscount: ['type' => 'fixed', 'value' => 0],
            isPkp: false,
            settingId: $this->setting->id
        );
        $this->assertEquals(78950.00, $committed['lines'][0]['line_subtotal']);

        // 2. The business changes its increment to 100.
        $this->setting->update(['row_total_rounding_increment' => 100]);

        // 3-4. Reading the loaded draft without editing must NOT reprice it.
        // The stored authoritative total is carried on the line, and a clean
        // row consumes it rather than being recalculated.
        $loadedLine = array_merge($line, [
            'line_total_minor' => 7895000,
            PosCartTotalsCalculator::LINE_CLEAN_FLAG => true,
        ]);

        $reloaded = $calculator->calculate(
            lines: [$loadedLine],
            billDiscount: ['type' => 'fixed', 'value' => 0],
            isPkp: false,
            settingId: $this->setting->id
        );
        $this->assertEquals(78950.00, $reloaded['lines'][0]['line_subtotal']);
        $this->assertEquals(78950.00, $reloaded['totals']['grand_total']);

        // 5-6. An eligible pricing interaction clears the clean flag, and the
        // recalculation uses the CURRENT increment of 100.
        $interacted = $calculator->calculate(
            lines: [array_merge($loadedLine, [PosCartTotalsCalculator::LINE_CLEAN_FLAG => false])],
            billDiscount: ['type' => 'fixed', 'value' => 0],
            isPkp: false,
            settingId: $this->setting->id
        );
        $this->assertEquals(79000.00, $interacted['lines'][0]['line_subtotal']);
    }

    /**
     * Persist calculated cart lines through the real snapshot mapper and hydrate
     * them back, returning the recalculated snapshot lines. This exercises the
     * actual draft round trip rather than hand-built metadata.
     *
     * @param  array<int, array<string, mixed>>  $calculatedLines
     * @return array<int, array<string, mixed>>
     */
    private function roundTripThroughDraft(array $calculatedLines): array
    {
        $transaction = \Modules\Pos\Entities\PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => 'TX-RT-' . uniqid(),
            'status' => \Modules\Pos\Entities\PosTransaction::STATUS_DRAFT,
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => $this->session->id,
        ]);

        $mapper = app(\Modules\Pos\Services\PosTransactionSnapshotMapper::class);

        $keyed = [];
        foreach ($calculatedLines as $i => $calculatedLine) {
            $keyed[$i + 1] = $calculatedLine;
        }
        $mapper->persistLines($transaction, $keyed);

        $hydrated = $mapper->hydrateCart($transaction->fresh());

        return (new PosCartTotalsCalculator())->calculate(
            lines: array_values($hydrated['lines']),
            billDiscount: ['type' => 'fixed', 'value' => 0],
            isPkp: false,
            settingId: $this->setting->id
        )['lines'];
    }

    public function test_packed_draft_persists_the_rounded_total_not_the_raw_packed_amount(): void
    {
        // A PACKED row's breakdown carries the RAW pre-rounding amount. If that
        // were persisted as the authoritative net, the row would reload at
        // 78,999.96 instead of the 79,000 actually committed.
        $committed = (new PosCartTotalsCalculator())->calculate(
            lines: [[
                'line_id' => 1,
                'product_id' => $this->product->id,
                'product_name' => $this->product->product_name,
                'qty' => 1,
                'unit_price' => 78999.96,
                'price_source' => 'PACKED',
                'line_total' => 7899996,
                'breakdown' => ['line_total_minor' => 7899996],
            ]],
            billDiscount: ['type' => 'fixed', 'value' => 0],
            isPkp: false,
            settingId: $this->setting->id
        );

        $this->assertEquals(79000.00, $committed['lines'][0]['line_subtotal']);

        $reloaded = $this->roundTripThroughDraft([$committed['lines'][0]]);

        $this->assertEquals(79000.00, $reloaded[0]['line_subtotal']);
    }

    public function test_reloaded_discounted_row_keeps_its_gross_and_discount_breakdown(): void
    {
        // Rounding is not reversible: gross 80,000 less a 1,050 discount nets
        // 78,950 raw, which rounds to 79,000. Reconstructing gross backwards
        // from the rounded net would report 80,050.
        $committed = (new PosCartTotalsCalculator())->calculate(
            lines: [[
                'line_id' => 1,
                'product_id' => $this->product->id,
                'product_name' => $this->product->product_name,
                'qty' => 1,
                'unit_price' => 80000.00,
                'price_source' => 'BASE',
                'line_discount_type' => 'fixed',
                'line_discount_value' => 1050.00,
            ]],
            billDiscount: ['type' => 'fixed', 'value' => 0],
            isPkp: false,
            settingId: $this->setting->id
        );

        $this->assertEquals(79000.00, $committed['lines'][0]['line_subtotal']);
        $this->assertEquals(80000.00, $committed['lines'][0]['line_gross']);
        $this->assertEquals(1050.00, $committed['lines'][0]['line_discount_amount']);

        $reloaded = $this->roundTripThroughDraft([$committed['lines'][0]]);

        $this->assertEquals(79000.00, $reloaded[0]['line_subtotal']);
        $this->assertEquals(80000.00, $reloaded[0]['line_gross'], 'Gross must not drift to 80050.');
        $this->assertEquals(1050.00, $reloaded[0]['line_discount_amount']);
    }

    public function test_reloaded_percentage_discounted_row_keeps_its_breakdown(): void
    {
        // The reverse-percentage path has the same irreversibility problem.
        $committed = (new PosCartTotalsCalculator())->calculate(
            lines: [[
                'line_id' => 1,
                'product_id' => $this->product->id,
                'product_name' => $this->product->product_name,
                'qty' => 1,
                'unit_price' => 80000.00,
                'price_source' => 'BASE',
                'line_discount_type' => 'percentage',
                'line_discount_value' => 1.3,
            ]],
            billDiscount: ['type' => 'fixed', 'value' => 0],
            isPkp: false,
            settingId: $this->setting->id
        );

        $committedGross = $committed['lines'][0]['line_gross'];
        $committedDiscount = $committed['lines'][0]['line_discount_amount'];

        $reloaded = $this->roundTripThroughDraft([$committed['lines'][0]]);

        $this->assertEquals($committed['lines'][0]['line_subtotal'], $reloaded[0]['line_subtotal']);
        $this->assertEquals($committedGross, $reloaded[0]['line_gross']);
        $this->assertEquals($committedDiscount, $reloaded[0]['line_discount_amount']);
    }

    public function test_loaded_clean_packed_row_also_survives_an_increment_change(): void
    {
        $this->setting->update(['row_total_rounding_increment' => 100]);

        $reloaded = (new PosCartTotalsCalculator())->calculate(
            lines: [[
                'line_id' => 1,
                'qty' => 1,
                'unit_price' => 78950.00,
                'price_source' => 'PACKED',
                'line_total' => 7895000,
                'line_total_minor' => 7895000,
                PosCartTotalsCalculator::LINE_CLEAN_FLAG => true,
            ]],
            billDiscount: ['type' => 'fixed', 'value' => 0],
            isPkp: false,
            settingId: $this->setting->id
        );

        $this->assertEquals(78950.00, $reloaded['lines'][0]['line_subtotal']);
    }

    public function test_draft_round_trip_preserves_the_stored_total_across_an_increment_change(): void
    {
        // 1. Commit a draft row under increment 50: 78,999.96 -> 78,950.
        $this->setting->update(['row_total_rounding_increment' => 50]);

        $calculator = new PosCartTotalsCalculator();
        $calculated = $calculator->calculate(
            lines: [[
                'line_id' => 1,
                'product_id' => $this->product->id,
                'product_name' => $this->product->product_name,
                'qty' => 1,
                'unit_price' => 78960.00,
                'price_source' => 'BASE',
            ]],
            billDiscount: ['type' => 'fixed', 'value' => 0],
            isPkp: false,
            settingId: $this->setting->id
        );
        $this->assertEquals(78950.00, $calculated['lines'][0]['line_subtotal']);

        // The calculator publishes the authoritative net for persistence.
        $this->assertSame(7895000, $calculated['lines'][0]['line_authoritative_net_minor']);

        // 2. Persist it the way the snapshot mapper does, then change the
        //    business increment to 100.
        $storedMeta = ['line_total_minor' => (int) $calculated['lines'][0]['line_authoritative_net_minor']];
        $this->setting->update(['row_total_rounding_increment' => 100]);

        // 3-4. Reload the draft (mapper marks restored rows clean) and read it
        //      without editing: the stored total must survive.
        $reloadedLine = [
            'line_id' => 1,
            'product_id' => $this->product->id,
            'qty' => 1,
            'unit_price' => 78960.00,
            'price_source' => 'BASE',
            'line_total_minor' => $storedMeta['line_total_minor'],
            PosCartTotalsCalculator::LINE_CLEAN_FLAG => true,
        ];

        $reloaded = $calculator->calculate(
            lines: [$reloadedLine],
            billDiscount: ['type' => 'fixed', 'value' => 0],
            isPkp: false,
            settingId: $this->setting->id
        );
        $this->assertEquals(78950.00, $reloaded['lines'][0]['line_subtotal']);

        // 5-6. A quantity interaction clears the clean flag, and the row
        //      recalculates under the current increment of 100.
        $edited = $calculator->calculate(
            lines: [array_merge($reloadedLine, [
                'qty' => 1,
                PosCartTotalsCalculator::LINE_CLEAN_FLAG => false,
            ])],
            billDiscount: ['type' => 'fixed', 'value' => 0],
            isPkp: false,
            settingId: $this->setting->id
        );
        $this->assertEquals(79000.00, $edited['lines'][0]['line_subtotal']);
    }

    public function test_hydrated_draft_line_is_marked_clean_and_carries_its_stored_total(): void
    {
        // The mapper is what makes a reloaded draft stable: it must restore the
        // stored authoritative net and mark the row clean.
        $transaction = \Modules\Pos\Entities\PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => 'TXN-ROUND-' . uniqid(),
            'status' => \Modules\Pos\Entities\PosTransaction::STATUS_DRAFT,
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => $this->session->id,
        ]);

        \Modules\Pos\Entities\PosTransactionLine::create([
            'pos_transaction_id' => $transaction->id,
            'line_no' => 1,
            'product_id' => $this->product->id,
            'product_name_snapshot' => $this->product->product_name,
            'product_code_snapshot' => $this->product->product_code,
            'qty' => 1,
            'unit_price' => 78960.00,
            'tax_id' => null,
            'tax_name_snapshot' => null,
            'tax_rate_snapshot' => 0,
            'line_discount_type' => 'fixed',
            'line_discount_value' => 0,
            'line_meta' => [
                'price_source' => 'BASE',
                'line_total' => 78950.00,
                'line_total_minor' => 7895000,
            ],
        ]);

        $hydrated = app(\Modules\Pos\Services\PosTransactionSnapshotMapper::class)->hydrateCart($transaction);
        $line = reset($hydrated['lines']);

        $this->assertTrue((bool) $line[PosCartTotalsCalculator::LINE_CLEAN_FLAG]);
        $this->assertSame(7895000, (int) $line['line_total_minor']);

        // And calculating that hydrated cart under a changed increment keeps it.
        $this->setting->update(['row_total_rounding_increment' => 100]);

        $snapshot = (new PosCartTotalsCalculator())->calculate(
            lines: array_values($hydrated['lines']),
            billDiscount: ['type' => 'fixed', 'value' => 0],
            isPkp: false,
            settingId: $this->setting->id
        );

        $this->assertEquals(78950.00, $snapshot['lines'][0]['line_subtotal']);
    }

    public function test_a_mismatched_fingerprint_forces_recalculation(): void
    {
        // A row marked clean whose pricing inputs no longer match its stored
        // fingerprint must be recalculated, not served from the cached total.
        // This is the fail-safe for a mutation path that forgot to dirty it.
        $this->setting->update(['row_total_rounding_increment' => 100]);

        $line = [
            'line_id' => 1,
            'product_id' => $this->product->id,
            'qty' => 1,
            'unit_price' => 78960.00,
            'price_source' => 'BASE',
            'line_total_minor' => 7895000,
            PosCartTotalsCalculator::LINE_CLEAN_FLAG => true,
        ];

        // Fingerprint captured for qty 1 ...
        $fingerprint = PosCartTotalsCalculator::pricingFingerprint($line);

        // ... but the row is now qty 2 without having been dirtied.
        $staleLine = array_merge($line, [
            'qty' => 2,
            PosCartTotalsCalculator::LINE_PRICING_FINGERPRINT => $fingerprint,
        ]);

        $snapshot = (new PosCartTotalsCalculator())->calculate(
            lines: [$staleLine],
            billDiscount: ['type' => 'fixed', 'value' => 0],
            isPkp: false,
            settingId: $this->setting->id
        );

        // Recalculated from qty 2 x 78960 = 157920 -> 157900, not the stale 78950.
        $this->assertEquals(157900.00, $snapshot['lines'][0]['line_subtotal']);
    }

    public function test_a_matching_fingerprint_still_reuses_the_stored_total(): void
    {
        $this->setting->update(['row_total_rounding_increment' => 100]);

        $line = [
            'line_id' => 1,
            'product_id' => $this->product->id,
            'qty' => 1,
            'unit_price' => 78960.00,
            'price_source' => 'BASE',
            'line_total_minor' => 7895000,
            PosCartTotalsCalculator::LINE_CLEAN_FLAG => true,
        ];
        $line[PosCartTotalsCalculator::LINE_PRICING_FINGERPRINT] =
            PosCartTotalsCalculator::pricingFingerprint($line);

        $snapshot = (new PosCartTotalsCalculator())->calculate(
            lines: [$line],
            billDiscount: ['type' => 'fixed', 'value' => 0],
            isPkp: false,
            settingId: $this->setting->id
        );

        $this->assertEquals(78950.00, $snapshot['lines'][0]['line_subtotal']);
    }

    public function test_a_legacy_row_without_a_fingerprint_keeps_its_stored_total(): void
    {
        // Deployment migration behaviour. Drafts saved before this change carry
        // no fingerprint. An absent fingerprint is trusted persisted state, not
        // a mismatch: the stored authoritative total is preserved rather than
        // repriced under whatever increment is now configured. Treating it as
        // dirty would reprice historical drafts on their first load after
        // deploy, which is exactly what the stability requirement forbids.
        $this->setting->update(['row_total_rounding_increment' => 100]);

        $legacyLine = [
            'line_id' => 1,
            'product_id' => $this->product->id,
            'qty' => 1,
            'unit_price' => 78960.00,
            'price_source' => 'BASE',
            'line_total_minor' => 7895000,
            PosCartTotalsCalculator::LINE_CLEAN_FLAG => true,
            // No LINE_PRICING_FINGERPRINT: this row predates the change.
        ];

        $this->assertArrayNotHasKey(PosCartTotalsCalculator::LINE_PRICING_FINGERPRINT, $legacyLine);

        $snapshot = (new PosCartTotalsCalculator())->calculate(
            lines: [$legacyLine],
            billDiscount: ['type' => 'fixed', 'value' => 0],
            isPkp: false,
            settingId: $this->setting->id
        );

        // Committed under increment 50; not re-rounded to 79000 by the current 100.
        $this->assertEquals(78950.00, $snapshot['lines'][0]['line_subtotal']);
    }

    public function test_a_legacy_row_reprices_once_an_eligible_interaction_occurs(): void
    {
        // The other half of the migration contract: a legacy row is preserved
        // only until it is actually edited. An eligible pricing interaction
        // dirties it, and it then recalculates under the current increment.
        $this->setting->update(['row_total_rounding_increment' => 100]);

        $legacyLine = [
            'line_id' => 1,
            'product_id' => $this->product->id,
            'qty' => 1,
            'unit_price' => 78960.00,
            'price_source' => 'BASE',
            'line_total_minor' => 7895000,
            PosCartTotalsCalculator::LINE_CLEAN_FLAG => false,
        ];

        $snapshot = (new PosCartTotalsCalculator())->calculate(
            lines: [$legacyLine],
            billDiscount: ['type' => 'fixed', 'value' => 0],
            isPkp: false,
            settingId: $this->setting->id
        );

        $this->assertEquals(79000.00, $snapshot['lines'][0]['line_subtotal']);
    }

    /**
     * Seed the session cart with one BASE row that is marked clean, as a
     * reloaded draft row would be, and return its line id.
     */
    private function seedCleanCartLine(): int
    {
        $this->cartService->addLine($this->setting->id, (int) $this->session->id, $this->product->id, 1);

        $store = app(\Modules\Pos\Services\PosCartSessionStore::class);
        $cart = $store->getCart($this->setting->id, (int) $this->session->id);

        $lineId = (int) array_key_first($cart['lines']);
        $line = $cart['lines'][$lineId];

        $line[PosCartTotalsCalculator::LINE_CLEAN_FLAG] = true;
        $line['line_total_minor'] = 7895000;
        $line[PosCartTotalsCalculator::LINE_PRICING_FINGERPRINT] =
            PosCartTotalsCalculator::pricingFingerprint($line);

        $cart['lines'][$lineId] = $line;
        $store->putCart($this->setting->id, (int) $this->session->id, $cart);

        return $lineId;
    }

    private function cartLine(int $lineId): array
    {
        $cart = app(\Modules\Pos\Services\PosCartSessionStore::class)
            ->getCart($this->setting->id, (int) $this->session->id);

        return $cart['lines'][$lineId];
    }

    public function test_quantity_mutation_marks_a_clean_row_dirty(): void
    {
        $lineId = $this->seedCleanCartLine();
        $this->assertTrue((bool) $this->cartLine($lineId)[PosCartTotalsCalculator::LINE_CLEAN_FLAG]);

        $this->cartService->updateLine($this->setting->id, (int) $this->session->id, $lineId, ['qty' => 3]);

        $line = $this->cartLine($lineId);
        $this->assertFalse((bool) $line[PosCartTotalsCalculator::LINE_CLEAN_FLAG]);
        $this->assertArrayNotHasKey(PosCartTotalsCalculator::LINE_PRICING_FINGERPRINT, $line);
    }

    public function test_discount_mutation_marks_a_clean_row_dirty(): void
    {
        $lineId = $this->seedCleanCartLine();

        $this->cartService->updateLine($this->setting->id, (int) $this->session->id, $lineId, [
            'line_discount_type' => 'fixed',
            'line_discount_value' => 500,
        ]);

        $this->assertFalse((bool) $this->cartLine($lineId)[PosCartTotalsCalculator::LINE_CLEAN_FLAG]);
    }

    public function test_adding_quantity_to_an_existing_row_marks_it_dirty(): void
    {
        $lineId = $this->seedCleanCartLine();

        // Adding the same product merges into the existing row, raising its qty.
        $this->cartService->addLine($this->setting->id, (int) $this->session->id, $this->product->id, 1);

        $this->assertFalse((bool) $this->cartLine($lineId)[PosCartTotalsCalculator::LINE_CLEAN_FLAG]);
    }

    public function test_customer_tier_selection_marks_rows_dirty(): void
    {
        $lineId = $this->seedCleanCartLine();

        $customer = Customer::factory()->create();
        $this->cartService->updateCustomerSelection($this->setting->id, (int) $this->session->id, $customer->id);

        $this->assertFalse((bool) $this->cartLine($lineId)[PosCartTotalsCalculator::LINE_CLEAN_FLAG]);
    }

    public function test_a_non_pricing_operation_leaves_the_row_clean_and_unchanged(): void
    {
        $lineId = $this->seedCleanCartLine();
        $before = $this->cartLine($lineId);

        // Editing the cart note touches no pricing input.
        $this->cartService->updateNote($this->setting->id, (int) $this->session->id, 'catatan kasir');

        $after = $this->cartLine($lineId);

        $this->assertTrue((bool) $after[PosCartTotalsCalculator::LINE_CLEAN_FLAG]);
        $this->assertSame(
            $before[PosCartTotalsCalculator::LINE_PRICING_FINGERPRINT],
            $after[PosCartTotalsCalculator::LINE_PRICING_FINGERPRINT]
        );
        $this->assertSame((int) $before['line_total_minor'], (int) $after['line_total_minor']);
    }

    public function test_pos_zero_increment_disables_rounding(): void
    {
        $this->setting->update(['row_total_rounding_increment' => 0]);

        $snapshot = (new PosCartTotalsCalculator())->calculate(
            lines: [[
                'line_id' => 1,
                'qty' => 1,
                'unit_price' => 78999.96,
                'price_source' => 'BASE',
            ]],
            billDiscount: ['type' => 'fixed', 'value' => 0],
            isPkp: false,
            settingId: $this->setting->id
        );

        $this->assertEquals(78999.96, $snapshot['lines'][0]['line_subtotal']);
    }

    public function test_pos_row_rounds_half_up_at_exact_midpoint(): void
    {
        $snapshot = (new PosCartTotalsCalculator())->calculate(
            lines: [[
                'line_id' => 1,
                'qty' => 1,
                'unit_price' => 78950.00,
                'price_source' => 'BASE',
            ]],
            billDiscount: ['type' => 'fixed', 'value' => 0],
            isPkp: false,
            settingId: $this->setting->id
        );

        $this->assertEquals(79000.00, $snapshot['lines'][0]['line_subtotal']);
    }

    public function test_pos_rounding_applies_after_line_discount(): void
    {
        // Gross 80000 less a 1050 line discount = 78950 raw, which then rounds
        // half-up to 79000. Rounding must happen after the discount, not before.
        $snapshot = (new PosCartTotalsCalculator())->calculate(
            lines: [[
                'line_id' => 1,
                'qty' => 1,
                'unit_price' => 80000.00,
                'price_source' => 'BASE',
                'line_discount_type' => 'fixed',
                'line_discount_value' => 1050.00,
            ]],
            billDiscount: ['type' => 'fixed', 'value' => 0],
            isPkp: false,
            settingId: $this->setting->id
        );

        $this->assertEquals(79000.00, $snapshot['lines'][0]['line_subtotal']);
    }

    public function test_pos_grand_total_is_not_rerounded_after_bill_discount(): void
    {
        // The row rounds to 79000; a 1234 bill discount then leaves a grand total
        // of 77766, which is deliberately NOT a multiple of the increment.
        $snapshot = (new PosCartTotalsCalculator())->calculate(
            lines: [[
                'line_id' => 1,
                'qty' => 1,
                'unit_price' => 78999.96,
                'price_source' => 'BASE',
            ]],
            billDiscount: ['type' => 'fixed', 'value' => 1234],
            isPkp: false,
            settingId: $this->setting->id
        );

        // The row rounds to 79000 before the bill discount; the discount is then
        // applied on top, and the resulting grand total is left as-is rather than
        // being snapped back to a multiple of the increment.
        $this->assertEquals(77766.00, $snapshot['totals']['grand_total']);
        // Deliberately not a multiple of the 100 increment.
        $this->assertNotSame(0, ((int) round($snapshot['totals']['grand_total'])) % 100);
    }

    public function test_pos_multiple_rows_each_round_independently_and_sum(): void
    {
        $snapshot = (new PosCartTotalsCalculator())->calculate(
            lines: [
                [
                    'line_id' => 1,
                    'qty' => 1,
                    'unit_price' => 78999.96, // -> 79000
                    'price_source' => 'BASE',
                ],
                [
                    'line_id' => 2,
                    'qty' => 1,
                    'unit_price' => 78949.00, // -> 78900
                    'price_source' => 'BASE',
                ],
            ],
            billDiscount: ['type' => 'fixed', 'value' => 0],
            isPkp: false,
            settingId: $this->setting->id
        );

        $this->assertEquals(79000.00, $snapshot['lines'][0]['line_subtotal']);
        $this->assertEquals(78900.00, $snapshot['lines'][1]['line_subtotal']);
        // Grand total is the sum of the already-rounded rows.
        $this->assertEquals(157900.00, $snapshot['totals']['grand_total']);
    }

    /**
     * Build a split-planner context for a single bundle line whose (already
     * rounded) row total is $lineSubtotal and whose one component allocates
     * $componentPrice.
     */
    private function bundlePlanContext(float $lineSubtotal, float $componentPrice, int $qty = 1): array
    {
        $location = \Modules\Setting\Entities\Location::firstOrCreate(
            ['name' => 'Loc POS'],
            ['setting_id' => $this->setting->id]
        );

        return [
            'setting_id' => $this->setting->id,
            'cart_snapshot' => [
                'lines' => [
                    [
                        'line_id' => 1,
                        'product_id' => $this->product->id,
                        'product_name' => 'Paket POS',
                        'product_code' => 'PKT-POS',
                        'qty' => $qty,
                        'unit_price' => $lineSubtotal / $qty,
                        'tax_id' => null,
                        'tax_rate' => 0,
                        'line_discount_type' => 'fixed',
                        'line_discount_value' => 0,
                        'line_discount_amount' => 0,
                        'bill_discount_amount' => 0,
                        'line_subtotal' => $lineSubtotal,
                        'serial_number_required' => false,
                        'assigned_serials' => [],
                        'bundle_id' => 77,
                        'bundle_items' => [
                            [
                                'product_id' => $this->product->id,
                                'quantity' => 1,
                                'informational_item_price' => $componentPrice,
                                'stock_managed' => false,
                            ],
                        ],
                    ],
                ],
            ],
            'allocations' => [
                '0_P' => [
                    [
                        'source_setting_id' => $this->setting->id,
                        'source_location_id' => $location->id,
                        'allocated_qty' => $qty,
                        'tax_bucket_used' => false,
                        'tax_policy_snapshot' => [
                            'source_is_pkp' => false,
                            'tax_id' => null,
                            'tax_name' => null,
                            'tax_rate' => 0,
                        ],
                    ],
                ],
            ],
        ];
    }

    public function test_bundle_parent_residual_absorbs_the_rounding_difference(): void
    {
        // The documented case: a bundle row rounded to 79000 with a component
        // allocating 8999 leaves the parent residual at exactly 70001. The
        // component allocation stays exact; the parent absorbs the difference.
        $plan = app(\Modules\Pos\Services\PosCheckoutSplitPlannerService::class)
            ->plan($this->bundlePlanContext(79000.00, 8999.00));

        // The parent residual surfaces as the parent group line's unit price.
        $residualMinor = 0;
        foreach ($plan['groups'] as $group) {
            foreach ($group['lines'] as $line) {
                if ((int) ($line['bundle_id'] ?? 0) === 77) {
                    $residualMinor += (int) round(((float) $line['unit_price']) * 100)
                        * (int) $line['qty'];
                }
            }
        }

        $this->assertSame(7000100, $residualMinor, 'Parent residual must be 79000 - 8999 = 70001.');
    }

    public function test_bundle_negative_residual_is_rejected(): void
    {
        // Components allocating more than the rounded row total must be refused
        // rather than silently producing negative parent revenue.
        $this->expectException(\Modules\Pos\Services\Exceptions\PosCheckoutValidationException::class);

        app(\Modules\Pos\Services\PosCheckoutSplitPlannerService::class)
            ->plan($this->bundlePlanContext(79000.00, 80000.00));
    }

    public function test_split_owner_fragments_sum_to_the_rounded_row_without_rerounding(): void
    {
        // A rounded row of 79000 over qty 3 must have its per-owner fragments sum
        // back to exactly the rounded row; the remainder is assigned
        // deterministically rather than each fragment being re-rounded.
        $plan = app(\Modules\Pos\Services\PosCheckoutSplitPlannerService::class)
            ->plan($this->bundlePlanContext(79000.00, 0.00, 3));

        $subtotalMinor = 0;
        foreach ($plan['groups'] as $group) {
            foreach ($group['lines'] as $line) {
                $subtotalMinor += (int) round(((float) ($line['line_subtotal'] ?? 0)) * 100);
            }
        }

        $this->assertSame(7900000, $subtotalMinor);
    }

    public function test_component_allocations_are_never_snapped_to_the_increment(): void
    {
        // The component allocates 8999 — not a multiple of the 100 increment. If
        // it were snapped to 9000 the parent residual would be 70000; the residual
        // landing on 70001 proves the allocation reached settlement intact.
        $plan = app(\Modules\Pos\Services\PosCheckoutSplitPlannerService::class)
            ->plan($this->bundlePlanContext(79000.00, 8999.00));

        // The component owner group fulfils no parent quantity; the other group
        // carries the parent residual.
        $componentTotal = 0.0;
        $parentResidual = 0.0;
        foreach ($plan['groups'] as $group) {
            foreach ($group['lines'] as $planLine) {
                if (! empty($planLine['parent_not_fulfilled_by_group'])) {
                    $componentTotal += (float) $planLine['line_subtotal'];
                } else {
                    $parentResidual += (float) $planLine['line_subtotal'];
                }
            }
        }

        // The 8999 allocation is never snapped to the 100 increment ...
        $this->assertEquals(8999.00, $componentTotal);
        // ... and the parent absorbs the remainder, reconstituting the rounded row.
        $this->assertEquals(70001.00, $parentResidual);
        $this->assertEquals(79000.00, $componentTotal + $parentResidual);
    }

    public function test_draft_save_persists_authoritative_rounded_row_amounts_reproducing_case_3393(): void
    {
        // Case 3393: 4 items in cart under increment 100
        // Item 1: 1 x 250,000 = 250,000
        // Item 2: 24 x 1,083 = 25,992 (rounds to 26,000)
        // Item 3: 1 x 14,000 = 14,000
        // Item 4: 1 x 28,000 = 28,000
        // Expected total: 250,000 + 26,000 + 14,000 + 28,000 = 318,000
        $this->setting->update(['row_total_rounding_increment' => 100.00]);

        $category = Category::firstOrCreate(
            ['category_code' => 'CAT-3393'],
            [
                'category_name' => 'Category 3393',
                'setting_id' => $this->setting->id,
                'created_by' => $this->user->id,
            ]
        );

        $unit = Unit::firstOrCreate([
            'name' => 'Pcs',
            'short_name' => 'pcs',
        ]);

        $product1 = Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'product_name' => 'Item 1',
            'product_code' => 'ITM-3393-1',
            'product_quantity' => 100,
            'product_cost' => 100000,
            'product_price' => 250000.00,
            'is_active' => true,
            'is_sold' => true,
            'stock_managed' => false,
        ]);
        ProductPrice::create([
            'product_id' => $product1->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 250000.00,
        ]);

        $product2 = Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'product_name' => 'Item 2',
            'product_code' => 'ITM-3393-2',
            'product_quantity' => 100,
            'product_cost' => 500,
            'product_price' => 1083.00,
            'is_active' => true,
            'is_sold' => true,
            'stock_managed' => false,
        ]);
        ProductPrice::create([
            'product_id' => $product2->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 1083.00,
        ]);

        $product3 = Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'product_name' => 'Item 3',
            'product_code' => 'ITM-3393-3',
            'product_quantity' => 100,
            'product_cost' => 5000,
            'product_price' => 14000.00,
            'is_active' => true,
            'is_sold' => true,
            'stock_managed' => false,
        ]);
        ProductPrice::create([
            'product_id' => $product3->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 14000.00,
        ]);

        $product4 = Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'product_name' => 'Item 4',
            'product_code' => 'ITM-3393-4',
            'product_quantity' => 100,
            'product_cost' => 10000,
            'product_price' => 28000.00,
            'is_active' => true,
            'is_sold' => true,
            'stock_managed' => false,
        ]);
        ProductPrice::create([
            'product_id' => $product4->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 28000.00,
        ]);

        // Add to session cart
        $this->cartService->addLine($this->setting->id, (int) $this->session->id, $product1->id, 1);
        $this->cartService->addLine($this->setting->id, (int) $this->session->id, $product2->id, 24);
        $this->cartService->addLine($this->setting->id, (int) $this->session->id, $product3->id, 1);
        $this->cartService->addLine($this->setting->id, (int) $this->session->id, $product4->id, 1);

        $cartStore = app(\Modules\Pos\Services\PosCartSessionStore::class);
        $cartBeforeSave = $cartStore->getCart($this->setting->id, (int) $this->session->id);

        $transactionService = app(\Modules\Pos\Services\PosTransactionService::class);
        $transaction = $transactionService->saveAndNew($this->setting->id, $this->session, $this->user, $cartBeforeSave);

        // 1. Transaction header totals must be 318,000.00
        $this->assertEquals(318000.00, (float) ($transaction->snapshot_totals['grand_total'] ?? 0));
        $this->assertEquals(318000.00, (float) ($transaction->snapshot_totals['subtotal'] ?? 0));

        // 2. Lines must carry authoritative rounded net minor and gross minor
        $lines = $transaction->lines()->orderBy('line_no')->get();
        $this->assertCount(4, $lines);

        $line2 = $lines[1];
        $this->assertSame((int) $product2->id, (int) $line2->product_id);
        $this->assertEquals(24, (int) $line2->qty);
        $this->assertEquals(1083.00, (float) $line2->unit_price);

        $line2Meta = $line2->line_meta ?? [];
        $this->assertArrayHasKey('line_total_minor', $line2Meta, 'Automatic rounded row must persist line_total_minor');
        $this->assertSame(2600000, (int) $line2Meta['line_total_minor'], 'Line 2 rounded net minor must be 2,600,000 cents (26,000.00)');
        $this->assertSame(2599200, (int) $line2Meta['line_gross_minor'], 'Line 2 gross minor must be 2,599,200 cents (25,992.00)');
        $this->assertArrayHasKey(PosCartTotalsCalculator::LINE_PRICING_FINGERPRINT, $line2Meta);

        // 3. Hydrating or loading the draft must restore the lines with line_total_minor and clean flag
        $mapper = app(\Modules\Pos\Services\PosTransactionSnapshotMapper::class);
        $hydrated = $mapper->hydrateCart($transaction);
        $hydratedLines = array_values($hydrated['lines']);

        $this->assertTrue((bool) $hydratedLines[1][PosCartTotalsCalculator::LINE_CLEAN_FLAG]);
        $this->assertSame(2600000, (int) $hydratedLines[1]['line_total_minor']);

        // 4. Recalculating the hydrated cart must keep line 2 at 26,000 and grand total at 318,000 without drift
        $calculator = new PosCartTotalsCalculator();
        $recalculated = $calculator->calculate(
            lines: $hydratedLines,
            billDiscount: ['type' => 'fixed', 'value' => 0],
            isPkp: false,
            settingId: $this->setting->id
        );

        $this->assertEquals(26000.00, (float) $recalculated['lines'][1]['line_subtotal']);
        $this->assertEquals(318000.00, (float) $recalculated['totals']['grand_total']);
    }

    public function test_draft_reload_and_resave_stability_under_increment_changes_packed_overrides_and_disabled_rounding(): void
    {
        // Test stability across:
        // 1. Increment change (50 -> 100)
        // 2. Packed automatic row
        // 3. Manual unit override
        // 4. Manual total override
        // 5. Disabled rounding (increment = 0)
        $this->setting->update(['row_total_rounding_increment' => 50.00]);

        // Create products
        $prodBase = Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $this->product->category_id,
            'unit_id' => $this->product->unit_id,
            'product_name' => 'Prod Base Rounding',
            'product_code' => 'P-BASE-RND',
            'product_quantity' => 100,
            'product_cost' => 500,
            'product_price' => 1083.00,
            'is_active' => true,
            'is_sold' => true,
            'stock_managed' => false,
        ]);
        ProductPrice::create([
            'product_id' => $prodBase->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 1083.00,
        ]);

        $prodPacked = Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $this->product->category_id,
            'unit_id' => $this->product->unit_id,
            'product_name' => 'Prod Packed Rounding',
            'product_code' => 'P-PACK-RND',
            'product_quantity' => 100,
            'product_cost' => 1000,
            'product_price' => 10000.00,
            'is_active' => true,
            'is_sold' => true,
            'stock_managed' => false,
        ]);
        ProductPrice::create([
            'product_id' => $prodPacked->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 10000.00,
        ]);

        // Add lines to cart:
        // Line 1: prodBase, qty 24 (24 * 1083 = 25992 -> under increment 50, rounds to 26000)
        $this->cartService->addLine($this->setting->id, (int) $this->session->id, $prodBase->id, 24);
        // Line 2: prodPacked, qty 1, packed breakdown raw total 78999.96 -> rounds to 79000 under increment 50
        $this->cartService->addLine($this->setting->id, (int) $this->session->id, $prodPacked->id, 1);

        $cartStore = app(\Modules\Pos\Services\PosCartSessionStore::class);
        $cart = $cartStore->getCart($this->setting->id, (int) $this->session->id);
        $cart['lines'][2]['price_source'] = 'PACKED';
        $cart['lines'][2]['breakdown'] = ['line_total_minor' => 7899996];
        $cart['lines'][2]['line_total'] = 7899996;
        $cartStore->putCart($this->setting->id, (int) $this->session->id, $cart);

        $transactionService = app(\Modules\Pos\Services\PosTransactionService::class);
        $transaction = $transactionService->saveAndNew($this->setting->id, $this->session, $this->user, $cartStore->getCart($this->setting->id, (int) $this->session->id));

        // Line 1: 26,000; Line 2: 79,000; Total = 105,000
        $this->assertEquals(105000.00, (float) $transaction->snapshot_totals['grand_total']);

        // Business changes increment to 100. Under increment 100, 25992 still rounds to 26000.
        // What if business changes increment to 0 (disabled rounding)?
        $this->setting->update(['row_total_rounding_increment' => 0.00]);

        // Load draft into cart
        $loadedSnapshot = $transactionService->loadToCart($this->setting->id, (int) $this->session->id, $transaction, $this->user);

        // Even with rounding disabled in settings, unedited loaded draft rows must preserve their authoritative committed total!
        $this->assertEquals(26000.00, (float) $loadedSnapshot['lines'][0]['line_subtotal']);
        $this->assertEquals(79000.00, (float) $loadedSnapshot['lines'][1]['line_subtotal']);
        $this->assertEquals(105000.00, (float) $loadedSnapshot['totals']['grand_total']);

        // Saving again without editing must keep the same totals
        $cartLoaded = $cartStore->getCart($this->setting->id, (int) $this->session->id);
        $resaved = $transactionService->saveAndNew($this->setting->id, $this->session, $this->user, $cartLoaded);
        $this->assertEquals(105000.00, (float) $resaved->snapshot_totals['grand_total']);

        // Now test manual override row:
        // Load again
        $transactionService->loadToCart($this->setting->id, (int) $this->session->id, $resaved, $this->user);
        $cartLoaded2 = $cartStore->getCart($this->setting->id, (int) $this->session->id);

        // Apply manual line unit price override on line 1: unit price 1200.50, qty 24 = 28812.00
        $cartLoaded2['lines'][1]['price_source'] = 'LINE_UNIT_PRICE_OVERRIDE';
        $cartLoaded2['lines'][1]['unit_price'] = 1200.50;
        $cartLoaded2['lines'][1]['line_gross_minor'] = 2881200;
        $cartLoaded2['lines'][1]['line_discount_minor'] = 0;
        $cartLoaded2['lines'][1]['line_net_minor'] = 2881200;
        $cartLoaded2['lines'][1]['line_total_minor'] = 2881200;
        $cartStore->putCart($this->setting->id, (int) $this->session->id, $cartLoaded2);

        $savedWithOverride = $transactionService->saveAndNew($this->setting->id, $this->session, $this->user, $cartStore->getCart($this->setting->id, (int) $this->session->id));

        // Line 1 override is 28812.00; Line 2 packed is 79000.00 -> Total = 107812.00
        $this->assertEquals(107812.00, (float) $savedWithOverride->snapshot_totals['grand_total']);

        // Re-enable increment = 500. Reload draft.
        $this->setting->update(['row_total_rounding_increment' => 500.00]);
        $loadedWithOverride = $transactionService->loadToCart($this->setting->id, (int) $this->session->id, $savedWithOverride, $this->user);

        $this->assertEquals(28812.00, (float) $loadedWithOverride['lines'][0]['line_subtotal']);
        $this->assertEquals(79000.00, (float) $loadedWithOverride['lines'][1]['line_subtotal']);
        $this->assertEquals(107812.00, (float) $loadedWithOverride['totals']['grand_total']);
    }

    public function test_pos_transaction_line_amount_resolver_handles_all_permutations(): void
    {
        // 1. Canonical override line
        $overrideLine = [
            'qty' => 1.5,
            'unit_price' => 12345.67,
            'line_discount_type' => 'fixed',
            'line_discount_value' => 200.00,
            'line_meta' => [
                'price_source' => 'LINE_TOTAL_OVERRIDE',
                'line_gross_minor' => 1851851, // 18,518.51
                'line_discount_minor' => 20000, // 200.00
                'line_net_minor' => 1831851, // 18,318.51
                'bill_discount_amount' => 100.50,
            ],
        ];
        $res1 = \Modules\Pos\Services\PosTransactionLineAmountResolver::resolve($overrideLine);
        $this->assertEquals(18518.51, $res1['gross']);
        $this->assertEquals(200.00, $res1['discount']);
        $this->assertEquals(18318.51, $res1['net_before_bill']);
        $this->assertEquals(100.50, $res1['bill_discount']);
        $this->assertEquals(18218.01, $res1['charged_total']);

        // 2. Large amount and percentage discount
        $largeLine = [
            'qty' => 100,
            'unit_price' => 1250000.00, // 125,000,000.00 gross
            'line_discount_type' => 'percentage',
            'line_discount_value' => 10.00, // 12,500,000.00 discount
            'line_meta' => [
                'price_source' => 'BASE',
                'line_gross_minor' => 12500000000,
                'line_discount_minor' => 1250000000,
                'line_total_minor' => 11250000000, // 112,500,000.00
            ],
        ];
        $res2 = \Modules\Pos\Services\PosTransactionLineAmountResolver::resolve($largeLine);
        $this->assertEquals(125000000.00, $res2['gross']);
        $this->assertEquals(12500000.00, $res2['discount']);
        $this->assertEquals(112500000.00, $res2['net_before_bill']);
        $this->assertEquals(0.00, $res2['rounding_adjustment']);

        // 3. Fallback standard calculation with rounding adjustment
        $fallbackLine = [
            'qty' => 3,
            'unit_price' => 333.33,
            'line_discount_type' => 'fixed',
            'line_discount_value' => 0.00,
            'line_meta' => [
                'price_source' => 'BASE',
            ],
        ];
        $res3 = \Modules\Pos\Services\PosTransactionLineAmountResolver::resolve($fallbackLine);
        $this->assertEquals(999.99, $res3['gross']);
        $this->assertEquals(0.00, $res3['discount']);
        $this->assertEquals(999.99, $res3['net_before_bill']);
    }

    public function test_downstream_taxable_row_amounts_and_global_discounts_reconcile_in_minor_units(): void
    {
        $this->setting->update([
            'is_pkp' => true,
            'row_total_rounding_increment' => 100.00,
        ]);

        $tax = \Modules\Setting\Entities\Tax::create([
            'name' => 'PPN 11%',
            'value' => 11.0,
            'is_active' => true,
        ]);

        $calculator = new PosCartTotalsCalculator();

        // 1 item: 78,999.96 -> rounds to 79,000. Bill discount: 1,000.
        // Net subtotal = 78,000.00.
        // Tax is extracted from gross 78,000.00:
        // tax = round(78000 * 1100 / 11100) = round(85800000 / 11100) = round(7729.7297) = 7730.
        $snapshot = $calculator->calculate(
            lines: [[
                'line_id' => 1,
                'product_id' => $this->product->id,
                'qty' => 1,
                'unit_price' => 78999.96,
                'tax_id' => $tax->id,
                'tax_rate' => 11.0,
                'price_source' => 'BASE',
            ]],
            billDiscount: ['type' => 'fixed', 'value' => 1000.00],
            isPkp: true,
            settingId: $this->setting->id
        );

        $this->assertEquals(79000.00, $snapshot['lines'][0]['line_net_before_bill']);
        $this->assertEquals(1000.00, $snapshot['lines'][0]['bill_discount_amount']);
        $this->assertEquals(78000.00, $snapshot['lines'][0]['line_subtotal']);
        $this->assertEquals(7730.00, $snapshot['lines'][0]['line_tax_total']);
        $this->assertEquals(78000.00, $snapshot['totals']['grand_total']);
        // Verify tax total is exactly 7730.00 without additional increment rounding
        $this->assertEquals(7730.00, $snapshot['totals']['tax_total']);
    }

    public function test_successive_partial_returns_against_rounded_source_values_across_setting_change(): void
    {
        \Spatie\Permission\Models\Permission::findOrCreate('pos.returns.create', 'web');
        $this->user->givePermissionTo('pos.returns.create');

        $customer = Customer::create([
            'setting_id' => $this->setting->id,
            'customer_name' => 'Customer Return Test',
            'customer_phone' => '0811111111',
            'customer_email' => 'returntest@test.com',
            'city' => 'City',
            'country' => 'Indonesia',
            'address' => 'Jl Return',
        ]);

        // Source transaction has a line rounded to 26,000.00 for qty 2 (raw unit_price: 12,999.00 -> subtotal 26,000.00).
        $transaction = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => 'TXN-RET-ROUNDED',
            'status' => PosTransaction::STATUS_COMPLETED,
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => $this->session->id,
            'customer_id' => $customer->id,
            'snapshot_totals' => [
                'subtotal' => 26000.00,
                'grand_total' => 26000.00,
            ],
        ]);

        $checkout = PosCheckout::create([
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => $transaction->id,
            'pos_session_id' => $this->session->id,
            'terminal_id' => $this->session->terminal_id,
            'cashier_user_id' => $this->user->id,
            'customer_id' => $customer->id,
            'status' => PosCheckout::STATUS_POSTED,
            'subtotal' => 26000.00,
            'grand_total' => 26000.00,
            'receipt_number' => 'RCP-RET-ROUNDED',
            'idempotency_key' => 'IDEM-RET-01',
            'payload_hash' => 'HASH-RET-01',
        ]);

        $transaction->update(['completed_checkout_id' => $checkout->id]);

        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'total_amount' => 26000.00,
            'paid_amount' => 26000.00,
            'due_amount' => 0,
            'date' => now()->toDateString(),
            'status' => 'DISPATCHED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'reference' => 'SO-RET-01',
        ]);

        $checkoutSale = PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->session->terminal->location_id ?? 1,
            'grand_total' => 26000.00,
            'subtotal' => 26000.00,
            'split_key' => 'SPLIT-01',
            'tax_bucket' => 'NON_TAX',
        ]);

        // SaleDetail reflects the effective rounded captured price: unit_price 13,000.00, subtotal 26,000.00
        $saleDetail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'quantity' => 2,
            'price' => 13000.00,
            'unit_price' => 13000.00,
            'sub_total' => 26000.00,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $snapshotService = app(PosReturnSnapshotService::class);
        $submissionService = app(PosReturnSubmissionService::class);

        // 1. Build initial return snapshot
        $snapshot1 = $snapshotService->build($transaction->id);
        $this->assertEquals(2.0, $snapshot1['lines'][0]['returnable_quantity']);
        $this->assertEquals(13000.00, $snapshot1['lines'][0]['unit_price']);
        $this->assertEquals(26000.00, $snapshot1['lines'][0]['line_total']);

        // First partial return: return 1 qty via actual submission service
        $this->actingAs($this->user);
        $return1 = $submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'source_snapshot_hash' => $snapshot1['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $saleDetail->id,
                    'quantity' => 1,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                ],
            ],
        ]);

        $this->assertNotNull($return1);
        $this->assertEquals(PosReturn::STATUS_DRAFT, $return1->status);
        $this->assertEquals(13000.00, (float) $return1->total_amount);
        $this->assertEquals(13000.00, (float) $return1->lines->first()->expected_cash_amount);

        // Approve return 1 so it consumes return quantity
        $return1->update([
            'status' => PosReturn::STATUS_COMPLETED,
            'approval_status' => PosReturn::APPROVAL_STATUS_APPROVED,
        ]);

        // 2. Setting changes increment to 500 in the meantime
        $this->setting->update(['row_total_rounding_increment' => 500.00]);

        // 3. Build snapshot for second return
        $snapshot2 = $snapshotService->build($transaction->id);
        $this->assertEquals(1.0, $snapshot2['lines'][0]['returnable_quantity']);
        $this->assertEquals(13000.00, $snapshot2['lines'][0]['unit_price']);

        // Second partial return: return remaining 1 qty via actual submission service
        $return2 = $submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'source_snapshot_hash' => $snapshot2['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $saleDetail->id,
                    'quantity' => 1,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                ],
            ],
        ]);

        $this->assertNotNull($return2);
        $this->assertEquals(PosReturn::STATUS_DRAFT, $return2->status);
        $this->assertEquals(13000.00, (float) $return2->total_amount);
        $this->assertEquals(13000.00, (float) $return2->lines->first()->expected_cash_amount);

        // Approve return 2
        $return2->update([
            'status' => PosReturn::STATUS_COMPLETED,
            'approval_status' => PosReturn::APPROVAL_STATUS_APPROVED,
        ]);

        // Sum of both partial returns equals exactly the original captured total (26,000.00)
        $this->assertEquals(26000.00, (float) ($return1->total_amount + $return2->total_amount));

        // Verify remaining returnable quantity is now 0
        $snapshotFinal = $snapshotService->build($transaction->id);
        $this->assertEquals(0.0, $snapshotFinal['lines'][0]['returnable_quantity']);
    }
}

