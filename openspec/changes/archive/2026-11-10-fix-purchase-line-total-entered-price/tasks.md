# Tasks

## 1. Cart fix

- [x] 1.1 In `app/Livewire/Purchase/ProductCart.php` `updateLineTotal`, write `options.entered_unit_price` from the derived base unit price (the same value written to `unit_price`); verify with a Livewire test in `tests/Feature/Livewire/PurchaseProductCartLineTotalTest.php` that after committing a total, the row's `options.entered_unit_price` equals the derived price (Rp1.200.000 × 2 → total Rp2.157.350 → Rp1.078.675)
- [x] 1.2 Add a cart test for a fixed discount (quantity 2, discount Rp50.000, total Rp2.000.000 → entered Rp1.050.000) and for a total that doesn't divide evenly (Rp1.000.000 / 3 → Rp333.333,33 with `sub_total` still Rp1.000.000); verify both pass

## 2. Persistence (create and edit)

- [x] 2.1 Add a feature test that creates a Purchase whose row total is overridden and asserts the saved `PurchaseDetail` has `entered_unit_price` = `unit_price` = 1078675 and `sub_total` = 2157350, and that the purchase show page displays Rp1.078.675; verify it fails before 1.1 and passes after
- [x] 2.2 Add a feature test that edits an existing Purchase (saved unit price Rp1.200.000, quantity 2), overrides the row total, saves, and asserts the saved `entered_unit_price` and `unit_price` equal the new derived price; verify it passes
- [x] 2.3 Run the existing Purchase line-total and pricing suites (`php artisan test --filter=PurchaseProductCart` and `--filter=Purchase`) and verify there are no regressions; confirm the sales cart tests (`--filter=SaleProductCartLineTotal`) still pass unchanged

## Workflow follow-up

- Archive the change after review.
