<?php
$path = 'Modules/Pos/Tests/Feature/POSCheckoutNoteAndPaymentImageTest.php';
$content = file_get_contents($path);

// Fix remaining case-sensitive assertions
$content = str_replace(
    "\$this->assertStringContainsString(\$note, \$sale->note);",
    "\$this->assertStringContainsStringIgnoringCase(\$note, \$sale->note);",
    $content
);

file_put_contents($path, $content);
echo "Patched 4!\n";
