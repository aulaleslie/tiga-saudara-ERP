<?php

namespace Tests\Feature\Setting;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Setting\Entities\Tax;
use Tests\TestCase;

class TaxDefaultUniquenessTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_new_default_tax_unsets_previous_default(): void
    {
        $firstDefault = Tax::create([
            'name' => 'VAT 10',
            'value' => 10,
            'is_default' => true,
        ]);

        $secondDefault = Tax::create([
            'name' => 'VAT 11',
            'value' => 11,
            'is_default' => true,
        ]);

        $this->assertSame(1, Tax::query()->where('is_default', true)->count());
        $this->assertFalse((bool) $firstDefault->fresh()->is_default);
        $this->assertTrue((bool) $secondDefault->fresh()->is_default);
    }

    public function test_updating_tax_to_default_unsets_previous_default(): void
    {
        $firstDefault = Tax::create([
            'name' => 'VAT 12',
            'value' => 12,
            'is_default' => true,
        ]);

        $candidate = Tax::create([
            'name' => 'VAT 13',
            'value' => 13,
            'is_default' => false,
        ]);

        $candidate->update(['is_default' => true]);

        $this->assertSame(1, Tax::query()->where('is_default', true)->count());
        $this->assertFalse((bool) $firstDefault->fresh()->is_default);
        $this->assertTrue((bool) $candidate->fresh()->is_default);
    }
}
