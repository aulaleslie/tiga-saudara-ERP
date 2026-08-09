## 1. Authoritative row-total calculation

- [x] 1.1 Refactor the Purchase cart manual `Total Baris` update path so its two-decimal committed total, rather than rounded unit-price multiplication, is written as the row subtotal.
- [x] 1.2 Refactor the Sales cart manual `Total Baris` update path with the same authoritative-total behavior while retaining the `manual_line_total` pricing source.
- [x] 1.3 In both cart paths, calculate two-decimal DPP and tax as a reconciled split of the committed final total for applicable PKP tax-included and tax-exclusive rows; retain zero tax for non-PKP rows.
- [x] 1.4 Confirm existing validation, standard-row-only behavior, discount reversal, manual-unit-price behavior, and later intentional recalculation flows remain unchanged.

## 2. Purchase regression coverage

- [x] 2.1 Add a Purchase Livewire regression test for the reported non-PKP case: unit price Rp1.216,67, quantity 1.200, committed Total Baris Rp1.460.000, and exact persisted cart subtotal.
- [x] 2.2 Add Purchase Livewire regression tests for non-divisible PKP tax-included and tax-exclusive committed totals, asserting exact final total and exact DPP-plus-tax reconciliation.
- [x] 2.3 Run the focused Purchase cart line-total test suite and resolve any regression.

## 3. Sales regression coverage

- [x] 3.1 Add a Sales Livewire regression test for the reported non-PKP case, asserting the exact committed subtotal, zero tax, correct DPP, and `manual_line_total` source.
- [x] 3.2 Add Sales Livewire regression tests for non-divisible PKP tax-included and tax-exclusive committed totals, asserting exact final total and exact DPP-plus-tax reconciliation.
- [x] 3.3 Verify manual line-total persistence/hydration tests still preserve the authoritative total through Sales create/edit paths.
- [x] 3.4 Run the focused Sales line-total and pricing-source test suites and resolve any regression.

## 4. Integrated verification

- [x] 4.1 Add Purchase create/edit integration coverage that edits an existing stored document's quantity-1.200 line total, saves it, and reopens it; cover non-PKP plus PKP tax-included and tax-exclusive documents.
- [x] 4.2 Add Sales create/edit integration coverage with the same stored-document edit/save/reopen flows, including retention of `manual_line_total` after reload.
- [x] 4.3 In the new stored-document coverage, assert that Total Baris is the final tax-inclusive amount in both PKP modes: DPP plus tax equals the entered amount and tax-exclusive mode does not add tax on top.
- [x] 4.4 Run all focused Purchase and Sales tests added or affected by this change.
- [x] 4.5 Run the project’s recommended fresh-SQLite test command, or document an environment-specific reason and run the closest viable equivalent.
