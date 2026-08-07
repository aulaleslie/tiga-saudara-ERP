## 1. Owner-Aware HPP Resolution

- [x] 1.1 For every stock-managed product, refactor `SeedAverageCostFromSalesHppCommand` to resolve the latest eligible HPP separately for Perdana, then Top IT, then Tiga Nusa, using the existing deterministic ordering.
- [x] 1.2 Select the first available result from that explicit source order as the shared baseline and classify products without a baseline as unresolved.
- [x] 1.3 Add special-company overlay resolution so Top IT and Tiga Nusa receive only their own latest HPP after shared-baseline selection.

## 2. Product-Price Seeding Behavior

- [x] 2.1 Update per-setting processing to fill only missing, null, or zero `average_purchase_price` values from the resolved baseline while preserving positive non-source values.
- [x] 2.2 Create missing `product_prices` rows when an HPP baseline exists, copying same-product selling/tier/tax metadata when available and using safe defaults otherwise.
- [x] 2.3 Ensure special-company overlays update only the matching setting row and that all command writes remain limited to `average_purchase_price` plus required row creation.
- [x] 2.4 Extend dry-run and write-mode reporting to distinguish created, baseline-filled, special-overlay-updated, unchanged, and unresolved outcomes without changing purchase-import or `last_purchase_price` behavior.

## 3. Verification

- [x] 3.1 Update command feature tests for baseline priority: Perdana, then Top IT, then Tiga Nusa.
- [x] 3.2 Add feature tests that missing and zero target rows across all settings are seeded, while positive non-source averages remain unchanged.
- [x] 3.3 Add feature tests that Top IT and Tiga Nusa each retain their own latest HPP without overwriting another business.
- [x] 3.4 Add feature tests for missing-row metadata/defaults, no-baseline unresolved handling, dry-run non-mutation, and repeated write idempotence.
- [x] 3.5 Run the focused `SeedAverageCostFromSalesHppCommandTest` suite and relevant formatting/static checks.

## 4. Remove Dead Purchase Reconciliation Code

- [x] 4.1 Remove unused Purchase/PurchaseDetail/ReceivedNote/ReceivedNoteDetail imports from command.
- [x] 4.2 Delete `getLiteralPurchaseCandidates` method and all its call sites.
- [x] 4.3 Delete `getLatestApprovedAtsForPartialDetails` method.
- [x] 4.4 Delete `calculateLiteralPurchaseUnitPrice` method.
- [x] 4.5 Delete `sortAndGroupPurchaseCandidates` method.
- [x] 4.6 Delete `resolvePurchaseCandidate` method.
- [x] 4.7 Confirm no `last_purchase_price` writes remain in command.
- [x] 4.8 Verify all 23 focused tests still pass with dead code removed.

## 5. Update OpenSpec Artifacts

- [x] 5.1 Update proposal.md to state that command-level `last_purchase_price` reconciliation is removed because purchase-import owns this responsibility.
- [x] 5.2 Update design.md to document the removal and add explicit non-goal: "Reconciling `last_purchase_price` at the command level; purchase-import owns that responsibility exclusively."
- [x] 5.3 Add REMOVED Requirements section to spec.md with two entries: "Seeding reconciles last purchase price from literal purchase history" and "Perdana supplies missing default last purchase prices", each with Reason and Migration.

## 6. Update User-Facing Documentation

- [x] 6.1 Search codebase for references claiming this command seeds or reconciles `last_purchase_price`.
- [x] 6.2 Updated README.md to clarify command only seeds `average_purchase_price`.
- [x] 6.3 Updated SEED_AVERAGE_COST_FROM_SALES_HPP.md with owner-aware baseline logic and added note that `last_purchase_price` is owned by purchase-import.

## 7. Final Validation

- [x] 7.1 Run focused `SeedAverageCostFromSalesHppCommandTest` suite and verify all 23 tests pass.
- [x] 7.2 No obsolete command tests exist that only exercise last-purchase reconciliation; all purchase-related tests removed.
- [x] 7.3 Test `test_preserves_existing_price_metadata_when_filling_zero_average` passes, proving command preserves existing `last_purchase_price` unchanged.
- [x] 7.4 Run `openspec validate seed-owner-aware-average-cost --strict` - validation passes.
- [x] 7.5 PHP syntax check passes for both command and test files.
