# Audit of Sibling Cart Mutators for Stale `options.unit_price`

Audit conducted for Task 1.1 / 1.2 in `app/Livewire/Purchase/ProductCart.php`.

We examined the following mutators:
1. `updatePrice($row_id, $product_id)`
   - Changes effective unit price: **Yes** (sets `$new_price` from user input).
   - Omits fresh `unit_price` from `options` merge: **Yes** (previously only set `entered_unit_price` and `canonical_unit_price`, leaving stale `options['unit_price']`).
   - Action: **FIX (Task 2.1)**. Set `'unit_price' => $canonicalUnitPrice` (or base-unit/canonical unit price matching canonical_unit_price).

2. `updateLineTotal($row_id, $product_id)`
   - Changes effective unit price: **Yes** (derives `$this->unit_price[$row_id]` from line total).
   - Omits fresh `unit_price` from `options` merge: **No**. Lines 1227-1228 already explicitly set `'unit_price' => $this->unit_price[$row_id]`.
   - Action: No fix needed.

3. `updateUnit(string $rowId, string $unitKey)`
   - Changes effective unit price: **Yes** (scales unit price to new conversion factor).
   - Omits fresh `unit_price` from `options` merge: **No**. Lines 1573-1574 already explicitly set `'unit_price' => $newUnitPriceExact`.
   - Action: No fix needed.

4. `updateQuantity($row_id, $product_id, $newQty = null)`
   - Changes effective unit price: **No**. Unit price is explicitly preserved (derived from `canonical_unit_price` * factor or preserved from `manual_line_total`).
   - Does it change canonical price? No.
   - Action: No fix needed.

5. `setProductDiscount($row_id, $product_id)`
   - Changes effective unit price: **No**. Resolves `$exactUnitPrice = $this->resolveExactUnitPrice($cart_item)` and keeps base unit price intact.
   - Action: No fix needed.

6. `updateTax($row_id, $product_id, $selectedTaxId = null)`
   - Changes effective unit price: **No**. Resolves `$exactUnitPrice = $this->resolveExactUnitPrice($cart_item)` and keeps base unit price intact.
   - Action: No fix needed.

### Conclusion
Only `updatePrice()` matches both criteria (changes effective unit price AND omitted fresh `unit_price` in its `options` merge array).
`updateLineTotal()` and `updateUnit()` already explicitly include `'unit_price' => ...` in their `options` array merge.
