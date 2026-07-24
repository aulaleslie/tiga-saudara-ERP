<?php

$path = 'Modules/Pos/Tests/Feature/POSCheckoutNoteAndPaymentImageTest.php';
$content = file_get_contents($path);

// Fix 1: Route pos.sell.cart.line.store -> pos.sell.cart.lines.store
$content = str_replace("route('pos.sell.cart.line.store')", "route('pos.sell.cart.lines.store')", $content);

// Fix 2: cart_token for payment-chain routes
$content = str_replace("getJson(route('pos.sell.checkout.payment-chain'))", "getJson(route('pos.sell.checkout.payment-chain', ['cart_token' => \$cartToken]))", $content);
$content = str_replace("deleteJson(route('pos.sell.checkout.payment-chain.reset'))", "deleteJson(route('pos.sell.checkout.payment-chain.reset', ['cart_token' => \$cartToken]))", $content);

// Fix 3: pos_is_enabled -> pos_enabled
$content = str_replace("'pos_is_enabled' => true", "'pos_enabled' => true", $content);

// Fix 4: Add pos.transactions.save to createCheckoutContext
$content = str_replace("'pos.transactions.load',\n        ]);", "'pos.transactions.load',\n            'pos.transactions.save',\n        ]);", $content);

// Fix 5: Change assertOk to assertStatus(201) for stage-payment
$content = preg_replace("/('payment_image_token' => \\\$token,\s*\])->assertOk\(\);/", "$1->assertStatus(201);", $content);

// Fix 6: Change 422 to 404 for payment-image.delete
$content = str_replace("->assertStatus(422); // or 403/404", "->assertStatus(404);", $content);

// Fix 7: Fix pos.sell.cart.clear in test_draft_save_reload_preserves_note
$content = str_replace("postJson(route('pos.sell.cart.clear'))", "deleteJson(route('pos.sell.cart.clear'))", $content);

file_put_contents($path, $content);
echo "Patched!\n";
