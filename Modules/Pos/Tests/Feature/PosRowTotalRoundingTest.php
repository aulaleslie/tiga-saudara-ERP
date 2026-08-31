<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Customer;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Services\PosCartService;
use Modules\Pos\Services\PosCartTotalsCalculator;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
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
}
