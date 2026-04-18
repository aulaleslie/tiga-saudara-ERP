## 1. Schema and Model Contract Preparation

- [x] 1.1 Add migration(s) to support optional `sale_bundle_items.sale_detail_id` and standalone self-context fields required by the specs.
- [x] 1.2 Update `SaleBundleItem` model casts/fillable/relations to support both linked and standalone bundle rows.
- [x] 1.3 Add validation/guard rules so rows with null `sale_detail_id` must carry the minimum standalone context.

## 2. Sales Read Path Fallback Logic

- [x] 2.1 Update Sales dispatch aggregation key resolution to use parent tax context first and standalone bundle-row context as fallback.
- [x] 2.2 Update dispatch stock display and server-side validation flows so both consume the same resolved tax context.
- [x] 2.3 Update Sales return eligibility/context mapping to tolerate standalone bundle rows without parent-detail assumptions.

## 3. Sales Detail and Document Projection

- [x] 3.1 Update Sales detail rendering contract to show linked bundle components under parent rows and standalone components in a separate section.
- [x] 3.2 Preserve standard Sales create/update linked persistence behavior in this phase (no standalone writes from standard UI).
- [x] 3.3 Define and apply invoice/document rendering behavior for standalone bundle components according to finalized policy.

## 4. Regression and Readiness Verification

- [x] 4.1 Add/adjust feature tests for dispatch tax-resolution fallback (linked and standalone bundle rows).
- [x] 4.2 Add/adjust feature tests for Sales detail/document rendering of linked versus standalone bundle components.
- [x] 4.3 Add/adjust regression tests to confirm standard Sales create/update remains linked parent+bundle persistence.
- [x] 4.4 Run targeted Sales/dispatch/return test suites and document any residual risks for the POS follow-up change.
