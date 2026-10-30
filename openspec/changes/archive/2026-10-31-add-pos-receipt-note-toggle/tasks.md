# Tasks

## 1. Shared POS receipt note control

- [x] 1.1 Add the accessible Tampilkan Catatan Transaksi checkbox beside Cetak Struk in the shared receipt template, with page-local toggling, explicit initialization from the receipt-business default, and identical screen/print visibility; verify both toggle directions and that control markup is inside the nonprinting region.
- [x] 1.2 Separate eligible note rendering from initial visibility while preserving escaped multiline text, placement and empty-note omission; extend `PosReceiptSectionVisibilityTest` for setting ON/OFF, missing configuration, blank/missing notes and HTML characters, updating prior absence assertions to check effective visibility; run `php artisan test --filter=PosReceiptSectionVisibilityTest` using the isolated test database.

## 2. Kas Bon collection receipt coverage

- [x] 2.1 Add the same control and note presentation to Kas Bon collection print/reprint, resolving the linked POS transaction note and collection-business visibility without active-business fallback; extend focused cases in `PosKasBonUiAndReceiptTest` or a dedicated receipt class for default ON/OFF, business isolation, empty/missing transaction notes and exclusion of collection/Sales notes; run the affected test methods with `php artisan test --filter=<affected-test-name>`.

## 3. Focused integration verification

- [x] 3.1 Review checkout, transaction print/reprint, global-payment, explicit historical and draft/loaded paths for shared-template/default coverage; run only relevant existing receipt tests plus any minimal missing path assertions, recording the exact commands and results in this change's `verification.md` and confirming unchanged financial values and audit semantics.
- [x] 3.2 In a browser, exercise one shared receipt and one Kas Bon receipt with a multiline note: toggle both directions, use Cetak Struk and browser print preview, confirm controls never print and hidden notes leave no gap, then reload/reopen to confirm settings defaults return; record results in `verification.md`, explicitly identifying any browser check that could not be performed.
