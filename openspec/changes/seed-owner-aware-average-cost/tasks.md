## 1. Owner-Aware HPP Resolution

- [ ] 1.1 For every stock-managed product, refactor `SeedAverageCostFromSalesHppCommand` to resolve the latest eligible HPP separately for Perdana, then Top IT, then Tiga Nusa, using the existing deterministic ordering.
- [ ] 1.2 Select the first available result from that explicit source order as the shared baseline and classify products without a baseline as unresolved.
- [ ] 1.3 Add special-company overlay resolution so Top IT and Tiga Nusa receive only their own latest HPP after shared-baseline selection.

## 2. Product-Price Seeding Behavior

- [ ] 2.1 Update per-setting processing to fill only missing, null, or zero `average_purchase_price` values from the resolved baseline while preserving positive non-source values.
- [ ] 2.2 Create missing `product_prices` rows when an HPP baseline exists, copying same-product selling/tier/tax metadata when available and using safe defaults otherwise.
- [ ] 2.3 Ensure special-company overlays update only the matching setting row and that all command writes remain limited to `average_purchase_price` plus required row creation.
- [ ] 2.4 Extend dry-run and write-mode reporting to distinguish created, baseline-filled, special-overlay-updated, unchanged, and unresolved outcomes without changing purchase-import or `last_purchase_price` behavior.

## 3. Verification

- [ ] 3.1 Update command feature tests for baseline priority: Perdana, then Top IT, then Tiga Nusa.
- [ ] 3.2 Add feature tests that missing and zero target rows across all settings are seeded, while positive non-source averages remain unchanged.
- [ ] 3.3 Add feature tests that Top IT and Tiga Nusa each retain their own latest HPP without overwriting another business.
- [ ] 3.4 Add feature tests for missing-row metadata/defaults, no-baseline unresolved handling, dry-run non-mutation, and repeated write idempotence.
- [ ] 3.5 Run the focused `SeedAverageCostFromSalesHppCommandTest` suite and relevant formatting/static checks.
