<?php

namespace Modules\Sale\Tests\Unit;

use Tests\TestCase;
use App\Models\User;
use Modules\Setting\Entities\Setting;
use Modules\Sale\Entities\PosDraft;
use Modules\Sale\Services\PosCodeAllocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Carbon\Carbon;

class PosCodeAllocatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_generates_first_code_correctly()
    {
        Carbon::setTestNow('2024-02-15 10:00:00');
        
        $setting = Setting::factory()->create([
            'pos_document_prefix' => 'POSA'
        ]);

        $code = (new PosCodeAllocator())->allocate($setting);

        $this->assertEquals('POSA-2024-02-00001', $code);
    }

    public function test_increments_code_sequentially_within_month()
    {
        Carbon::setTestNow('2024-02-15 10:00:00');
        
        $setting = Setting::factory()->create([
            'pos_document_prefix' => 'POSA'
        ]);

        // Create initial draft manually to simulate existing record
        PosDraft::factory()->create([
            'setting_id' => $setting->id,
            'document_number' => 'POSA-2024-02-00001',
            'created_at' => now(),
        ]);

        $code = (new PosCodeAllocator())->allocate($setting);

        $this->assertEquals('POSA-2024-02-00002', $code);
    }

    public function test_resets_sequence_on_new_month()
    {
        // Existing draft in Feb
        Carbon::setTestNow('2024-02-15 10:00:00');
        $setting = Setting::factory()->create(['pos_document_prefix' => 'POSA']);
        PosDraft::factory()->create([
            'setting_id' => $setting->id,
            'document_number' => 'POSA-2024-02-00055',
            'created_at' => now(),
        ]);

        // Move to March
        Carbon::setTestNow('2024-03-01 10:00:00');
        $code = (new PosCodeAllocator())->allocate($setting);

        $this->assertEquals('POSA-2024-03-00001', $code);
    }

    public function test_handles_independent_sequences_for_different_settings()
    {
        Carbon::setTestNow('2024-02-15 10:00:00');
        
        $settingA = Setting::factory()->create(['pos_document_prefix' => 'POSA']);
        $settingB = Setting::factory()->create(['pos_document_prefix' => 'POSB']);

        PosDraft::factory()->create([
            'setting_id' => $settingA->id,
            'document_number' => 'POSA-2024-02-00001',
        ]);

        $codeB = (new PosCodeAllocator())->allocate($settingB);

        $this->assertEquals('POSB-2024-02-00001', $codeB);
    }

    public function test_uses_default_prefix_if_setting_missing()
    {
        Carbon::setTestNow('2024-02-15 10:00:00');
        $setting = Setting::factory()->create(['pos_document_prefix' => null]);

        $code = (new PosCodeAllocator())->allocate($setting);
        // Assuming default fallback is 'POS'
        $this->assertEquals('POS-2024-02-00001', $code);
    }
}
