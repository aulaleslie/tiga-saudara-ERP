# Strengthen Sales Line-Total Tax Tests

## Why

The Sales line-total calculation matrix tests in
`tests/Feature/Livewire/SaleProductCartLineTotalCalculationMatrixTest.php`
assert tax-inclusive arithmetic on cart lines that carry no tax at all.

`ProductCart::updateTax($row_id, $id, $selectedTaxId = null)` reads the tax
from its **third argument**. The existing tests set the `product_tax` Livewire
property and then call `updateTax($rowId, $id)` with no third argument, so the
resolved tax id is `null` and nothing is persisted onto the cart line.

The tests still pass because in tax-inclusive mode
`unit_price = line_total / qty` regardless of whether tax applies. The
assertions are therefore true but vacuous: they would not catch a regression
that dropped tax handling entirely.

## What Changes

Primarily a test-hardening change — but the strengthened tests immediately
exposed a real non-PKP Total Baris reverse-calculation defect, so this change
also carries the one-guard production fix for it (see "Defect exposed and
fixed" below). No migrations, models, services, or Blade templates are
modified.

- Pass the tax id explicitly as the third `updateTax` argument.
- Set the intended tax-inclusion mode explicitly in each affected test.
- Add assertions proving tax was really applied: the tax id is stored on the
  cart line, and `product_tax_amount`, `sub_total_before_tax`, and `sub_total`
  are all correct and mutually consistent.
- Retain existing `unit_price`, requested Total Baris, and
  `pricing_source = manual_line_total` assertions.
- Rename or remove tests whose names claim tax involvement but that cannot
  carry tax.

## Constraint discovered

`ProductCart::calculateSubtotalAndTax()` force-nulls the tax for the `sale`
cart whenever the business is **not** PKP:

```php
$tax_id = ($this->cart_instance === 'sale' && ! $this->isPkp) ? null : $tax_id;
```

A non-PKP Sales line can therefore never carry tax. Tests named as non-PKP
"with tax" / "tax included" cases were asserting a scenario the system does
not permit, and are renamed to describe the real behavior they cover.

## Defect exposed and fixed

Once `test_line_total_non_pkp_ignores_tax_and_reverses_correctly` genuinely
assigned an 11% tax, it failed: entering a Total Baris of 2500 produced a
`sub_total` of **2252.26** — exactly 2500 ÷ 1.11.

The two code paths disagreed about whether a non-PKP Sales line is taxed:

- `calculateSubtotalAndTax()` force-nulls the tax id for non-PKP sale carts,
  so no tax is ever added onto the line.
- `updateLineTotal()` did **not** apply that rule. In tax-exclusive mode it
  divided the entered total by `(1 + taxRate)` to strip a tax that was then
  never added back.

### User impact

A non-PKP sale carrying a stale or leftover tax selection on a line — for
example a line whose tax was set while the document belonged to a PKP business,
or before a business switch — silently saved a line total reduced by the tax
rate. Entering a Total Baris of 2500 with an 11% tax still attached persisted
2252.26. The figure the user typed was not the figure that was stored, with no
warning, and the shortfall flowed into the document total and the posted sale.

### Fix

Mirror the existing rule in `updateLineTotal()` so both paths agree, nulling
the tax id for non-PKP sale carts before the rate is read:

```php
if ($this->cart_instance === 'sale' && ! $this->isPkp) {
    $tax_id = null;
}
```

With the guard in place the entered Total Baris is preserved exactly, and PKP
reverse calculation is unaffected.

## Impact

- Affected tests: `SaleProductCartLineTotalCalculationMatrixTest`
- Affected production code: `app/Livewire/Sale/ProductCart.php` —
  `updateLineTotal()` non-PKP tax guard only
- Regression check: the full `Sale|Purchase` suite went from 133 failed / 1200
  passed to 132 failed / 1201 passed. Exactly one test flipped to passing and
  nothing regressed; the remaining failures are pre-existing and unrelated.
