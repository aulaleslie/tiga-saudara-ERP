## Context

POS checkout split posting currently creates one generated Sale per `source_setting_id + source_location_id + tax_bucket`. That boundary is still useful because it preserves owner, location, and tax reporting context. The bug is earlier: taxable owner allocations can be classified into `NON_TAX` when the POS line lacks `tax_id`, even when the source setting is PKP or the selected stock allocation comes from `quantity_tax`.

In the observed POS checkout, CV TIGA NUSA COMPUTER (`is_pkp = true`) generated separate TNC Sales documents because the phone allocations resolved to `TAX:1` while the bundled charger allocation resolved to `NON_TAX`. The charger stock existed in TNC's tax quantity bucket, so the non-tax classification was inconsistent with the owner and stock source policy.

## Goals / Non-Goals

**Goals:**

- Ensure every POS allocation owned by a PKP source setting resolves to a taxable split bucket.
- Ensure every POS allocation consuming `quantity_tax` resolves to a taxable split bucket, even if the POS line has no explicit tax id.
- Use a deterministic fallback tax when product, price, stock, or line data does not provide a tax id.
- Keep non-PKP owner allocations non-taxable.
- Preserve existing split posting ownership, source-location, payment, dispatch, bundle revenue, and reconciliation behavior.

**Non-Goals:**

- Do not merge generated Sales documents across different tax buckets as a separate behavior change.
- Do not repair or backfill historical POS checkouts, Sales, dispatches, product stocks, or POS Returns.
- Do not change purchase tax behavior.
- Do not introduce new database columns or migrations.

## Decisions

### Decision 1: Make source owner PKP status authoritative for POS sale taxability

For split planning, `source_setting.is_pkp = true` is sufficient to make an allocation taxable. The planner should no longer require the customer-facing POS line to carry `tax_id` before resolving a taxable bucket for a PKP source owner.

Rationale: POS sales for TNC are taxable by business policy. Missing product or line tax metadata should use fallback tax resolution rather than producing non-tax Sales documents.

Alternative considered: only make bundle components taxable. Rejected because the same missing-line-tax failure can affect ordinary non-serial lines and would leave inconsistent behavior.

### Decision 2: Treat `quantity_tax` allocations as taxable evidence

Allocation snapshots should preserve whether stock was consumed from the tax bucket. When `tax_bucket_used = true`, tax resolution must produce a tax id for taxable planning, using fallback tax if the stock record does not have `tax_id`.

Rationale: Stock bucket choice is an execution identity. If stock was allocated from `quantity_tax`, downstream Sale Detail, Dispatch Detail, stock mutation, and audit trace should remain tax-bearing.

Alternative considered: infer tax only from `product_prices.sale_tax_id`. Rejected because existing stock can be tax-bucketed even when product price tax metadata is missing.

### Decision 3: Centralize fallback order consistently across cart, allocation, and planner code

The fallback order should be:

1. Explicit POS line tax.
2. Product or product-price sale tax.
3. Allocation or stock tax.
4. Default active tax.
5. Latest active tax.

The implementation can be local helper methods in existing POS services if no shared tax resolver already exists, but the same order should be covered by tests so behavior does not diverge.

Rationale: The current flow has multiple tax decision points: cart price resolution, stock allocation snapshots, split planner group keys, and inline posting persistence. A consistent order avoids one service producing `TAX:1` while another persists `tax_id = null`.

Alternative considered: create a new cross-module tax service. Rejected for now because the change is POS-specific and no schema or external dependency is needed.

### Decision 4: Preserve split-key semantics after correcting tax resolution

The split key remains `source_setting_id + source_location_id + tax_bucket`. The expected visible improvement is that PKP allocations without explicit line tax move into `TAX:<fallback_id>` instead of `NON_TAX`, allowing same-owner PKP bundle components and parent lines to combine when they share source location and effective tax.

Rationale: Existing specs, return preview, reconciliation, and tests depend on tax bucket being part of the generated Sales boundary. Correcting the bucket is lower risk than redefining the document boundary.

Alternative considered: group Sales only by source setting and location. Rejected for this change because it is broader than the reported bug and would require revisiting Sale header tax reporting, payment allocation display, and return mapping expectations.

## Risks / Trade-offs

- Fallback tax may be missing in an empty tax table -> Checkout should fail with an actionable validation error instead of silently creating non-tax PKP Sales.
- Existing tests may assert `NON_TAX` for PKP records with missing line tax -> Update fixtures to encode the new business rule and add explicit non-PKP coverage.
- Multiple services currently carry tax logic -> Focus implementation on small helper extraction or identical local fallback behavior, with tests covering each path that can classify an allocation.
- Correcting tax bucket resolution can reduce generated Sales count for future checkouts -> This is expected when the only split was an erroneous PKP `NON_TAX` bucket, but reconciliation totals must remain unchanged.

## Migration Plan

No database migration is required. Deploy as code and test changes only.

Rollback is code-only: revert the POS tax resolution changes and tests. Historical data remains untouched either way.

## Open Questions

- Should the fallback tax query require an active/enabled flag if such a field is later added to `taxes`, or continue using the current default/latest behavior?
