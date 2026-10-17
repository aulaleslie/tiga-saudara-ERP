<?php

namespace Modules\Setting\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class PosReceiptVisibilitySettingModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_newly_created_setting_receives_agreed_visibility_defaults(): void
    {
        $setting = Setting::factory()->create();

        $this->assertFalse($setting->pos_receipt_show_note);
        $this->assertFalse($setting->pos_receipt_show_return_history);
        $this->assertFalse($setting->pos_receipt_show_price_adjustments);
        $this->assertFalse($setting->pos_receipt_show_ppn);
        $this->assertTrue($setting->pos_receipt_show_signature_space);

        $visibility = $setting->getReceiptVisibility();
        $this->assertFalse($visibility['show_note']);
        $this->assertFalse($visibility['show_return_history']);
        $this->assertFalse($visibility['show_price_adjustments']);
        $this->assertFalse($visibility['show_ppn']);
        $this->assertTrue($visibility['show_signature_space']);
    }

    public function test_explicit_false_remains_false_for_signature_space(): void
    {
        $setting = Setting::factory()->create([
            'pos_receipt_show_signature_space' => false,
        ]);

        $this->assertFalse($setting->fresh()->pos_receipt_show_signature_space);
        $visibility = $setting->fresh()->getReceiptVisibility();
        $this->assertFalse($visibility['show_signature_space']);
    }

    public function test_explicit_true_values_persist_and_reflect_in_visibility(): void
    {
        $setting = Setting::factory()->create([
            'pos_receipt_show_note' => true,
            'pos_receipt_show_return_history' => true,
            'pos_receipt_show_price_adjustments' => true,
            'pos_receipt_show_ppn' => true,
            'pos_receipt_show_signature_space' => false,
        ]);

        $fresh = $setting->fresh();
        $this->assertTrue($fresh->pos_receipt_show_note);
        $this->assertTrue($fresh->pos_receipt_show_return_history);
        $this->assertTrue($fresh->pos_receipt_show_price_adjustments);
        $this->assertTrue($fresh->pos_receipt_show_ppn);
        $this->assertFalse($fresh->pos_receipt_show_signature_space);

        $visibility = $fresh->getReceiptVisibility();
        $this->assertTrue($visibility['show_note']);
        $this->assertTrue($visibility['show_return_history']);
        $this->assertTrue($visibility['show_price_adjustments']);
        $this->assertTrue($visibility['show_ppn']);
        $this->assertFalse($visibility['show_signature_space']);
    }

    public function test_missing_or_null_legacy_attributes_resolve_to_agreed_defaults(): void
    {
        $setting = new Setting();
        // Unset attributes to simulate legacy record without receipt visibility columns
        unset($setting->pos_receipt_show_signature_space);
        unset($setting->pos_receipt_show_note);
        unset($setting->pos_receipt_show_return_history);
        unset($setting->pos_receipt_show_price_adjustments);
        unset($setting->pos_receipt_show_ppn);

        $this->assertTrue($setting->pos_receipt_show_signature_space);
        $this->assertFalse($setting->pos_receipt_show_note);
        $this->assertFalse($setting->pos_receipt_show_return_history);
        $this->assertFalse($setting->pos_receipt_show_price_adjustments);
        $this->assertFalse($setting->pos_receipt_show_ppn);

        $visibility = $setting->getReceiptVisibility();
        $this->assertTrue($visibility['show_signature_space']);
        $this->assertFalse($visibility['show_note']);
        $this->assertFalse($visibility['show_return_history']);
        $this->assertFalse($visibility['show_price_adjustments']);
        $this->assertFalse($visibility['show_ppn']);
    }
}
