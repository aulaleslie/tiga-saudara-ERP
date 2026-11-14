# Tasks

## 1. Product-level financial entry in tab 3

- [x] 1.1 Build a product projection from selected source lines and versioned product-level pricing intent in existing JSON; verify focused tests combine receipts and sold/unsold quantities into one product, retain source identities and reject unknown/missing/conflicting financial inputs.
- [x] 1.2 Calculate each product row with the existing billing calculator and allocate exact accepted amounts to source slices; verify mixed original prices require explicit entry, explicit zero works, fractional quantities and row-total overrides reconcile to the cent, and PKP/non-PKP and global discounts preserve existing cost meanings.
- [x] 1.3 Replace tab-3 source-level financial rows with one editable product row and expandable read-only source details; verify product price/discount/tax/total controls, total-override clearing, current-input preview ordering and back-navigation preserve the agreed single-price rule.
- [x] 1.4 Complete tab-3 invoice fields and draft attachment persistence using existing Purchase/media patterns; verify term-derived and manually overridden due dates, invoice/reporting dates, validation-input recovery, supported file validation and failure cleanup, without creating a Purchase during draft save.

## 2. Draft revision and Purchase generation

- [x] 2.1 Add the DRAFT/REJECTED Edit action and route, hydrating the same wizard and using the existing revision PUT lifecycle; verify retained selections/financial inputs, unavailable-source blockers, rejected-to-draft transition, stale revision rejection and approved/foreign/unauthorized edit denial.
- [x] 2.2 Carry product financial terms and attachments through existing submission, full-hash approval and finalization; verify approved edits are rejected and finalization uses the saved approved values without a second financial page or acquisition-only hash.
- [x] 2.3 Generate one Purchase detail per product for product-intent documents with lossless source lineage; verify multiple receipts, destinations, owners and sold/unsold slices produce one combined detail with exact totals while existing ownership/cost posting and rollback/idempotency behavior remain intact.
- [x] 2.4 Preserve legacy readers and historical rows; verify an existing approved buyout finalizes without rewritten hashes/totals, a reopened draft with conflicting source prices requires explicit product pricing, and historical finalized Purchase details are not regrouped. Document the new tab-3/edit flow and legacy behavior briefly.

## 3. Standard monetary editing for generated Purchases

- [x] 3.1 Enable normal and applicable global monetary edit eligibility for both consignment Purchase types and add a narrow authorized monetary-write exception to source/model guards; verify permitted price/discount/tax/charge changes retain detail IDs and reject altered product, quantity, supplier, source, business and row membership.
- [x] 3.2 Reuse the existing monetary calculation and active-payment safeguards for existing and future documents; verify paid/due reconciliation, rejection below paid amount, normal/global authorization boundaries and historical multiple-row preservation, with no source, stock, serial, acquisition-cost, ProductPrice or HPP mutation.
- [x] 3.3 Preserve other source restrictions and conversion retry compatibility; verify monetary editing does not enable full correction, returns, receiving, deletion or altered reversal eligibility, and a completed conversion/finalization retry after an edit cannot overwrite the Purchase or create another bill. Update interface copy to distinguish monetary editing from protected source/execution facts.

## 4. Focused integration verification

- [x] 4.1 Run focused Consignment preparation/pricing/finalization and Purchase monetary tests on isolated SQLite fixtures, including one tab-3 draft-to-approval-to-Purchase-to-monetary-edit scenario; record actual commands/results, exact product/detail and lineage totals, and preserved source/cost evidence. Validate this OpenSpec change; do not require unrelated suite cleanup.
- [x] 4.2 Browser-check the real three-tab creation/edit wizard with PKP and non-PKP fixtures: one row per product, supporting-detail expansion, differing-source-price prompt, editable total/price interaction, stale-preview handling, due-date override, validation recovery, draft reopening, and final Purchase/monetary-editor navigation. Record screenshots/outcomes and any limitations before marking complete.

## Verification notes

- 4.1 (2026-11-14): `php artisan test Modules/Consignment/Tests Modules/Purchase/Tests` on SQLite → 563 passed (3078 assertions). New: `ConsignmentBuyoutProductPricingTest`, `ConsignmentBuyoutInvoiceEntryTest`, `ConsignmentGeneratedPurchaseMonetaryEditTest`. Flow test: 2 sold (receipt @100) + 3 unsold (receipt @120) at product price 110, discount 10 → one detail qty 5 / 110 / 550, Purchase 540, two lineage rows summing 540; monetary edit to 100 → 500 with same detail id, cost events and lineage unchanged. Override 1000.01 over 4.5 units allocates 100001 cents exactly. `openspec validate simplify-buyout-invoice-entry` → valid.
- 1.3 / 4.2: server preview, hydration and rendering are covered by tests; client-side interaction (override clearing, stale-preview ordering, back-navigation, due-date override) has not yet been checked in a real browser.


### Chrome verification follow-up (2026-11-14)

Real-browser verification used Playwright Core with local Google Chrome, isolated SQLite fixtures at `/tmp/handoff-browser/narrow.sqlite`, and a localhost-only PHP server. Existing frontend assets were built under `/tmp/handoff-browser/build` without replacing the workspace manifest/hot file. Development business data and .env were not modified.

- `node /tmp/handoff-browser/narrow-interactions.mjs`: confirmed untaxed override 1000 / qty 5 followed by discount 10 gives unit 200 and total 950; confirmed PKP 11%-exclusive override 1110 followed immediately by discount 10 gives unit 200 and total 1054.50. Deliberately delayed stale preview responses were ignored.
- `node /tmp/handoff-browser/narrow-full.mjs`: 17 checks passed across actual creation tab 1 → goods selection → tab 3 → server validation failure/recovery → draft save → reopen edit → submit → approve → existing finalization → generated Purchase → standard monetary editor navigation. Verified one product row for two receipts (2 sold + 3 unsold), expandable sources, explicit-price prompt, back-navigation quantity changes retaining price, due-date derivation/manual override, invoice attachment reselection/retention, and one combined Purchase row. Full-page screenshots of tab 3 and the monetary editor were visually inspected.
- `node /tmp/handoff-browser/narrow-follow-receipt.mjs`: found a remaining [P2] pricing defect. On a PKP tax-exclusive row, choose explicit 11% tax and wait for preview; switch to "Ikuti penerimaan" for receipts with no tax, enter total 1000 and then discount 10 immediately before the next preview. Result is unit 180.18018 and total 850.90; expected unit 200 and total 950. `taxRate()` falls back to the previous accepted invoice rate when `default_tax_id` is null. Use the receipt's actual default rate (zero for untaxed evidence), not the prior explicit invoice choice. Tasks 1.3 and 4.2 remain open pending this fix and Chrome recheck.

Session-local evidence: `/tmp/handoff-browser/narrow-interaction-result.json`, `narrow-full-result.json`, `narrow-full-tab3.png`, `narrow-full-monetary.png`, and `narrow-after.png`. The temporary harness/fixtures are not permanent CI artifacts. Login used the native form API to bypass the existing shadowed `login.submit()` handler. Shared `$`/`$wire` console warnings were recorded on home/maintenance screens; the run verifies monetary-editor navigation, not a full browser monetary-save workflow. No application code was changed during this verification.


### Chrome final recheck (2026-11-14)

After the receipt-rate fix, reran `node /tmp/handoff-browser/narrow-follow-receipt.mjs` in real local Chrome against the same isolated SQLite PKP fixture. All four targeted outcomes passed:

- Explicit no-tax: manual total 1000 over five units, then per-unit discount 10 → derived unit price 200, final total 950.
- Explicit 11% tax with tax excluded: manual total 1110, then immediate per-unit discount 10 before preview completion → derived unit price 200, final total 1054.50.
- Switch from explicit 11% to "Ikuti penerimaan" on untaxed source receipts, then immediately enter total 1000 and discount 10 → derived unit price 200, final total 950. The previous invoice tax no longer contaminates the receipt default.
- A deliberately delayed old preview response released during newer-input debounce is ignored.

The earlier 17-check real-browser creation/edit/validation/approval/finalization/Purchase-navigation pass remains the broader wizard evidence; this recheck closes its remaining reported pricing blocker. Tasks 1.3 and 4.2 are now complete. Session-local evidence: `/tmp/handoff-browser/narrow-interaction-result.json` and `/tmp/handoff-browser/narrow-after.png`. No application code or development database was changed during this recheck. Existing recorded shared-page console warnings and the distinction between monetary-editor navigation and full browser monetary-save coverage still apply.


### Nominal formatter Chrome recheck (2026-11-14)

Ran `node /tmp/handoff-browser/nominal-check.mjs` using real local Chrome and the isolated PKP draft fixture. Nine checks passed: no reporting-date field in edit; grouped Indonesian clipboard paste `1.250.000,50` commits canonical `1250000.500000` and returns grouped display; focus shows raw canonical value; `45000.333333` preserves six decimals while showing `45.000,33`; unchanged six-decimal focus/blur preserves precision; invalid text remains visible/flagged and blocks submission; percentage discount remains a percentage; unchanged price blur preserves a manual total.

One [P1] browser blocker remains: enter unit price `1,234`, blur (canonical `1.234000`), focus (raw text `1.234`), then blur without changing anything. Canonical price becomes `1234.000000`. The parser treats the unchanged raw dot-decimal string as grouped Indonesian input before checking whether any edit happened; `blurField()` compares values only after parsing. This can silently multiply a price by 1000 and clear a total override. Preserve untouched raw/canonical input identity before reparsing, or otherwise distinguish raw editing format from pasted grouped input. Keep tasks 1.3 and 4.2 open pending fix and Chrome recheck.

Session-local evidence: `/tmp/handoff-browser/nominal-result.json`, `/tmp/handoff-browser/nominal-check.png`. The harness clicks/focuses, waits for the raw-value render, selects existing text before entry, and blurs through a real click; the final failure is not a focus/render timing artifact. No application code changed. Reporting-date creation/finalization backend tests were not independently rerun in this browser pass.


### Nominal formatter final Chrome recheck (2026-11-14)

Ran `node /tmp/handoff-browser/nominal-final-check.mjs` against the isolated SQLite PKP fixture in real local Chrome: **13 checks passed, zero failures**. The previously reported untouched-three-decimal blocker is resolved: entering `1,234` stores `1.234000`; refocusing shows `1,234`, and unchanged blur retains `1.234000` exactly. Repeated that case while a manual total of `1000.00` was present; the total override remained unchanged.

Additional checks passed: grouped Indonesian clipboard paste `1.250.000,50` → canonical `1250000.500000`; ungrouped comma-decimal editing text; six-decimal canonical `45000.333333` preserved through focus/blur while idle display is `45.000,33`; invalid text stays visible and flagged and prevents a draft-save request; percentage discount remains `12.50` percent; unchanged ordinary price blur preserves manual total; freshly entered `1.234` retains Indonesian grouped meaning `1234.000000`; canonical dot-decimal entry `1234.5` remains accepted as `1234.500000`; reporting-date input remains absent from draft editing.

Together with the earlier 17-check creation/edit/finalization pass and four pricing/stale-response checks, this closes the scoped browser verification. Tasks 1.3 and 4.2 are complete. The normal billing conversion screen was neither modified nor certified by this run. No broad PHP or MySQL suites were rerun for this client-side follow-up; existing server test evidence remains as previously recorded.

Session-local evidence: `/tmp/handoff-browser/nominal-result.json`, `/tmp/handoff-browser/nominal-check.png`, and `/tmp/handoff-browser/nominal-final-check.mjs`. Temporary server stopped after verification. No application code or development database was changed by the verification.
