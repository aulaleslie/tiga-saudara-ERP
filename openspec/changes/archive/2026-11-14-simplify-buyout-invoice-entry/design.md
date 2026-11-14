# Design

## Context

See proposal.md for motivation. `buyouts/create.blade.php` already has the required three-tab structure, but tab 3 renders read-only receipt/sold-source rows with only basic invoice inputs. The lifecycle already supports `reviseDraft()` for DRAFT/REJECTED through PUT, but there is no GET edit route or wizard hydration. Approval's existing full revision hash includes financial intent; keep it.

`ConsignmentBuyoutPricingService` adapts receipt-backed slices to `ConsignmentBillingPricingCalculator`. Finalization currently groups Purchase details by commercial values, so rounding or different source terms can produce multiple details for a product. Existing lineage supports many source slices pointing to one detail. Purchase monetary editing already handles protected row identity and payment reconciliation, but source guards and model events block generated Purchases.

## Goals / Non-Goals

**Goals:** Add financial entry to the existing wizard; make the commercial row a product while keeping operational allocations distinct; expose the existing monetary editor through a narrowly authorized exception. Keep the implementation proportional and reuse established calculations and UI meanings.

**Non-Goals:** A separate finalization form, financial editing after approval, a new approval fingerprint, merging historical Purchase rows, changing normal consignment conversion, new cost/audit ledgers, cost recalculation, supplier-return adapters or changing reversal/deletion/cancellation eligibility.

## Decisions

### 1. One financial row per product, operational detail underneath

Tab 2 keeps its existing receipt/product/sold-unsold selection tree. Tab 3 builds a separate product projection from exactly those selected source rows. Render:

```text
Product | Combined qty | Unit price | Discount | Tax | Final line total
  Expand: receipt / sold-or-unsold / qty / serial / location / owner
```

There is one price control, not an apply-to-all action or per-receipt override. Read-only source details preserve original receipt prices and commercial/tax evidence. Sum quantities in canonical precision. Recompute the projection when tab-2 selection changes; retain explicit financial inputs for retained products and discard removed products' charges.

If all contributing source prices agree, initialize the product's display price using normal tax-inclusion semantics. If they differ, require an explicit price or final total; do not average, choose the latest receipt or use a global last-purchase price. Preserve an explicitly entered zero. Default invoice tax from shared valid source tax evidence; where evidence disagrees, require an explicit common tax choice rather than splitting the commercial row. Non-PKP always yields zero financial tax. This defaulting detail follows the confirmed single-tax rule without changing physical tax buckets.

Alternative rejected: preserving independent financial inputs for each source. It defeats the confirmed one-product/one-price rule.

### 2. Calculate the product amount once and allocate to source slices

Use a versioned product-level section within existing `pricing_intent` JSON, keyed by product identity, for newly created/revised drafts. Keep source line identities in the existing operational lines. The server rebuilds selected products and quantities from those lines, rejects unknown/missing/duplicate product inputs and conflicting source-level overrides, and calculates each aggregate row with the existing billing calculator.

Allocate accepted net, discount, tax and payable amounts deterministically back to source slices by canonical quantity, with stable ordering and residual-cent assignment. Retain one common commercial unit price, tax choice and discount control on the product row; rounding residuals belong to accepted slice amounts, not different invoice prices. Product and document totals are authoritative and must equal summed lineage amounts exactly. Existing global-discount and unsold-cost calculations consume those accepted slices; sold slices still have no inventory cost. Do not rewrite receipt evidence or change the ownership-posting formula.

Use the normal consignment override interaction: editing final total derives the display unit price; editing unit price, row discount or tax clears the override. Preview and draft/approval/finalization validation use the same server calculation, with current-input response ordering so older asynchronous previews cannot overwrite new input state.

For product-intent documents, finalization groups Purchase details strictly by product ID, taking financial fields from the approved aggregate row and linking all source slices to that detail. Per-slice tax-rounding differences must not split the group. Preserve the legacy intent reader and posting behavior for already-approved documents; do not rewrite their hashes or accepted amounts. Historical generated Purchases remain untouched.

### 3. Complete tab 3 and reuse it for draft revision

Reuse normal consignment's nominal formatting (a copy of its parse/format helpers in a shared partial, so that screen is unchanged), price/discount/tax/total controls and metadata validation where practical without changing that conversion screen. Add supplier invoice number, invoice date (Purchase transaction date), due date/payment term, tax reference, notes, attachments and document discount. The term derives due date until the user manually overrides it. There is no reporting-date input: the Purchase reporting date starts at the invoice date and later changes use the existing Purchase date-adjustment feature (permissions, reason, audit). Legacy approved documents keep their recorded reporting date.

Add a scoped GET edit route/action that hydrates the existing wizard from DRAFT/REJECTED selections, destinations and metadata, opening tab 3 initially. Use the current PUT/revision lifecycle, including permission, active-business and custody checks. Keep supplier/business identity subject to the existing revision rules. Handle unavailable historical selections explicitly instead of dropping them silently or substituting goods. Rejected saves return to DRAFT as today.

Store draft invoice attachments with the existing media infrastructure, retaining stable attachment references in the draft invoice metadata so approval freezes them with other terms. Expose them read-only after approval and carry them to the generated Purchase through finalization, using existing supported file validation and cleanup patterns. Upload/file-copy failure must leave no partial draft or Purchase and no orphan newly staged files. No new attachment tables or general attachment-management workflow is needed.

Save remains Save Draft. Submission and approval are separate existing actions. Finalization posts saved approved terms directly; no additional financial form or edited financial payload is introduced there.

### 4. Permit only the standard monetary workflow after Purchase creation

Resolve generated Purchase edit mode using existing standard permissions/status eligibility. On normal and applicable global monetary screens, hydrate the current Purchase details, preserving every ID and quantity. Old documents may have multiple details for a product; keep those rows and amounts unless deliberately edited. Do not retroactively enforce the new buyout grouping on them.

Keep `PurchaseSourceGuard` prohibitions for full correction, supplier returns, receiving and deletion, along with existing reversal behavior. Add only a narrow monetary-write exception to source header/detail model guards, entered by the authorized transactional monetary service after standard protected-field and payment validation. Allow its supported monetary fields, including tax inclusion and paid/due summaries, while preserving supplier/business/source identity, products, quantities and row membership. Do not replace the model guards with unrestricted writes or make commercial/return/deletion guard methods no-ops.

Use existing monetary permissions (`purchases.update` and `purchases.received.monetary.edit`, plus normal global-access authority when applicable), validation, formatting and balances. Do not add a custom reason requirement, audit table or permission bundle beyond the standard monetary workflow. Keep existing administrative attachment/date/reference actions as they are. Source evidence remains immutable; viewing a source or retrying a completed conversion must not overwrite a legitimately changed Purchase. Adjust a retry check only if it incorrectly requires current monetary values to equal original evidence; do not redesign handoff or source lifecycle.

## Risks / Trade-offs

- [Per-slice rounding accidentally creates multiple product details] → Calculate the product amount first and explicitly test mixed receipts, tax and fractional quantities through finalization.
- [Wizard navigation loses financial intent or reintroduces removed charges] → Hydrate by stable product identity and verify back-navigation, failed saves and reopening rejected drafts.
- [A new UI bypasses source or approval protections] → Use the existing revision/status gates and authoritative source mapping; test forged product inputs and approved-edit denial.
- [Relaxing a model guard enables unintended operations] → Keep a narrow monetary exception and regression-test source correction/return/receiving/reversal restrictions.
- [Legacy data or discarded-work migrations exist locally] → Do not rerun, reverse or rewrite historical business data as setup. Use the current committed schema and isolated test fixtures; no new schema is expected for this design.

## Migration Plan

Use existing JSON intent/metadata, lineage and media storage. No data backfill, historical row merge, new approval hash or cost-ledger migration is planned. New/revised drafts use product-level intent; existing approved/finalized documents retain legacy compatibility. Deployment enables monetary editing through normal eligibility without changing existing amounts. Rollback should preserve product-level drafts and attachments; retain a compatible reader or export/revise those drafts before removing that reader, rather than deleting business data.

## Focused Verification

Use focused Consignment preparation/pricing/finalization tests and normal/global Purchase monetary tests. Include one mixed product spanning unequal receipt prices, sold and unsold sources, fractional quantity and a row-total override. Verify exact aggregate-to-lineage totals, one generated detail, unchanged stock/serial/cost rules, and legacy approved/finalized compatibility. Browser-check the actual three-tab creation/edit wizard, price/total interaction, current-preview ordering, due-date override, PKP/non-PKP controls, validation recovery and the ordinary monetary editor. Do not introduce full lifecycle or broad cost replay/race verification for excluded features.
