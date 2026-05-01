<?php

namespace Tests\Feature\Livewire\PosReturn;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use App\Livewire\PosReturn\PosReturnCreateForm;

class PosReturnCreateFormTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_renders_lookup_input()
    {
        Livewire::test(PosReturnCreateForm::class)
            ->assertSeeHtml('name="identifier"');
    }
}
