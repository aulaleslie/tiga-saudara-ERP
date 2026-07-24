<?php
$path = 'Modules/Pos/Tests/Feature/POSCheckoutNoteAndPaymentImageTest.php';
$content = file_get_contents($path);

$insertCartLineCode = <<<CODE
        \$this->actingAs(\$context['cashier'])
            ->withSession(['setting_id' => \$context['setting']->id])
            ->postJson(route('pos.sell.cart.lines.store'), [
                'type' => 'product',
                'id' => \Modules\Catalog\Entities\Product::factory()->create(['setting_id' => \$context['setting']->id])->id,
                'qty' => 1,
            ])->assertOk();
CODE;

// For test_draft_save_reload_preserves_note
$find1 = "\$this->actingAs(\$context['cashier'])\n            ->withSession(['setting_id' => \$context['setting']->id])\n            ->patchJson(route('pos.sell.cart.note.update'), ['note' => 'Important draft note'])\n            ->assertOk();";

$replace1 = $insertCartLineCode . "\n\n        " . ltrim($find1);

$content = str_replace($find1, $replace1, $content);

// For test_existing_draft_with_null_note_compatible
$find2 = "\$response = \$this->actingAs(\$context['cashier'])\n            ->withSession(['setting_id' => \$context['setting']->id])\n            ->postJson(route('pos.sell.transactions.save-and-new'), ['reference' => 'DRAFT-NULL'])\n            ->assertStatus(201);";

// Only replace the FIRST occurrence after the method name (which is fine, str_replace replaces all but there's only 1)
$replace2 = $insertCartLineCode . "\n\n        " . ltrim($find2);
$content = str_replace($find2, $replace2, $content);

file_put_contents($path, $content);
echo "Patched again!\n";
