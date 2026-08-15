## 1. Product Bundle Price Snapshots

- [x] 1.1 Add focused Product Bundle tests for per-setting replicated component prices, active-setting fallback, missing-price atomic failure, and tampered informational-price requests.
- [x] 1.2 Centralize server-side component `ProductPrice.sale_price` resolution for a target setting with the active-setting fallback.
- [x] 1.3 Update replicated bundle creation to persist independently resolved component price snapshots for every setting copy inside the existing transaction.
- [x] 1.4 Update setting-scoped bundle edit/save to refresh all component snapshots for only that copy and preserve other setting copies.
- [x] 1.5 Make bundle create/edit informational-price controls read-only presentation and remove client values as persistence authority.
- [x] 1.6 Add regression coverage proving saved bundle snapshots remain unchanged after product price changes until the relevant bundle copy is saved.

## 2. Normal Sales Parent Pricing and Discounts

- [x] 2.1 Extend Sales cart tests for parent row price overrides with fixed component snapshots across quantity, customer, tax, and reconciliation changes.
- [x] 2.2 Verify and harden Sale normalization/persistence so bundle components remain zero-priced non-billable rows after parent price overrides.
- [x] 2.3 Add row-discount tests proving a bundled row discount reduces only the commercial parent row.
- [x] 2.4 Add global-discount tests proving proration targets commercial Sale rows only and applies a bundled row's share only to its parent.
- [x] 2.5 Adjust Sales discount calculation or aggregation only where the new regression tests demonstrate component rows affect proration or totals.

## 3. POS Captured Pricing and Quantity

- [x] 3.1 Add POS cart/snapshot tests proving parent bundle prices remain editable while captured component allocations remain fixed.
- [x] 3.2 Remove transaction-time live `ProductPrice` fallback from bundle component allocation and preserve saved zero snapshots.
- [x] 3.3 Verify POS cart, draft, preflight, and finalize carry the POS owner's captured component snapshots without source-owner repricing.
- [x] 3.4 Add quantity tests proving component quantity equals parent outgoing base-unit quantity times quantity per bundle without double expansion.
- [x] 3.5 Add actionable preflight/finalize validation for a captured parent row amount below total fixed component allocations.
- [x] 3.6 Verify unsupported POS line/global discount input remains ignored or rejected and is not confused with a permitted parent row price override.

## 4. POS Split Allocation and Tax

- [x] 4.1 Add the canonical three-owner Laptop A, Mouse, and Mousepad split fixture with `5,550,000` captured revenue and exact owner-document reconciliation.
- [x] 4.2 Update split planning so component revenue always uses the POS owner's captured snapshots while actual stock ownership continues selecting Sales and dispatch owners.
- [x] 4.3 Carry explicit POS-owner identity through bundle split planning separately from each source-owner identity.
- [x] 4.4 Apply taxable treatment only to the PKP POS-owner bundle allocation and force other source-owner bundle allocations to non-tax without changing non-bundle tax behavior.
- [x] 4.5 Reconcile receipt/customer tax to the POS-owner taxable allocation while retaining the full captured bundle total and zero/free component display.
- [x] 4.6 Add manual parent-price override coverage showing fixed component allocations and only the parent residual change across owner groups.
- [x] 4.7 Add multi-quantity and rounding-sensitive tests proving parent residual, component allocations, owner totals, tax, and payments reconcile in minor units (deterministic remainder distribution proven at planner unit level; multi-source planned owner amounts, tax, payment, receipt, and inventory reconciliation proven at feature level).

## 5. Customer and Internal Presentation

- [x] 5.1 Add cart, transaction-detail, and receipt tests proving customers see the full captured parent price and zero/free component prices.
- [x] 5.2 Verify internal source-owner Sales documents persist their allocated commercial totals without adding those allocations again to customer totals.
- [x] 5.3 Add regression coverage that the canonical internal documents post `5,475,000`, `50,000`, and `25,000`, with tax only on `5,475,000`.

## 6. Verification and Deferred Boundaries

- [x] 6.1 Run focused Product Bundle, Sales pricing/discount, POS cart, split posting, tax, and receipt test suites.
- [ ] 6.2 Run `composer test:fresh-sqlite` or the broadest practical Laravel test suite and resolve regressions within this change's scope. *(Intentionally omitted to preserve sqlite test isolation and execute focused suite per review instructions)*
- [x] 6.3 Confirm no schema migration, historical rewrite, component HPP snapshot, POS discount feature, return change, or report rewrite was introduced.
- [x] 6.4 Record any discovered component-HPP or report double-counting evidence for Sequences 9 and 11 without expanding this implementation.
