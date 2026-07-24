<?php
// Draft save -> reload preserves the note
public function test_draft_save_reload_preserves_note(): void
{
    $context = $this->createCheckoutContext('DRAFT-NOTE');
    
    // Set a note
    $this->actingAs($context['cashier'])
        ->withSession(['setting_id' => $context['setting']->id])
        ->patchJson(route('pos.sell.cart.note.update'), ['note' => 'Important draft note'])
        ->assertOk();

    // Save as draft
    $response = $this->actingAs($context['cashier'])
        ->withSession(['setting_id' => $context['setting']->id])
        ->postJson(route('pos.sell.draft.store'), ['reference' => 'DRAFT1'])
        ->assertOk();
    $draftId = $response->json('draft_id');

    // Clear cart
    $this->actingAs($context['cashier'])
        ->withSession(['setting_id' => $context['setting']->id])
        ->postJson(route('pos.sell.cart.clear'))
        ->assertOk();

    // Reload draft
    $this->actingAs($context['cashier'])
        ->withSession(['setting_id' => $context['setting']->id])
        ->postJson(route('pos.sell.draft.load', $draftId))
        ->assertOk();

    // Verify note is restored
    $snapshot = $this->getCartSnapshot($context['cashier'], $context['setting']);
    $this->assertEquals('Important draft note', $snapshot['note']);
}
