## Context

Purchase and Sales use the same Livewire product quick-add modal and delegate persistence to `ProductCreator`. `ProductCreator` already seeds identical initial `ProductPrice` rows for all settings. The defect is earlier in the Sales submission path: the modal forces `is_sold`, but the shared product-creation rules allow `sale_price` to be null, so an invalid Sales quick-add can reach persistence with a zero sale price.

Normal product editing intentionally writes prices only for the active setting. Imports and background jobs are no longer part of this change.

## Goals / Non-Goals

**Goals:**

- Reject Sales-context quick-add unless `sale_price` is numeric and greater than zero.
- Preserve the shared all-business creation behavior for both Purchase and Sales quick-add.
- Add direct regression assertions for all-business price creation through each quick-add context.
- Verify only touched quick-add behavior and newly added or updated tests.

**Non-Goals:**

- Changing normal product edit from current-business scope.
- Changing the cross-business price-management page.
- Modifying bundle pricing, imports, background jobs, database schema, or existing product data.
- Running or planning the complete application test suite.

## Decisions

### Apply the stricter rule at the Sales quick-add boundary

The modal SHALL add context-specific validation for `sale_price` when `context === 'sale'`, while continuing to reuse the shared validation rules for common product fields. This keeps Sales workflow policy at the boundary that knows the calling context and avoids making sale price mandatory for purchase-only product creation.

Alternative considered: globally change `ProductCreateValidation::rules()` to require a sale price whenever `is_sold` is true. That would affect normal product creation and other callers beyond this narrowly confirmed defect, so it is not selected for this change.

### Keep `ProductCreator` as the single persistence path

Both quick-add contexts SHALL continue calling `ProductCreator`, whose `seedForSettings` behavior creates equal initial prices for every existing setting. Tests will exercise the Livewire entry points and inspect every resulting `ProductPrice` row rather than duplicating persistence logic in the modal.

Alternative considered: explicitly replicate prices in each quick-add component. This would duplicate transaction and pricing behavior and could diverge from normal product creation.

### Use focused feature tests as the verification boundary

Update the existing Sales quick-add regression to cover missing and non-positive sale prices, and add direct multi-setting persistence assertions for Sales and Purchase quick-add. Run only the related quick-add test files and any newly added focused test file.

## Risks / Trade-offs

- [A context-specific rule could diverge from future shared sellable-product rules] → Keep the override small and document that it enforces Sales cart-entry policy only.
- [Tests could prove service behavior without proving modal wiring] → Drive creation through Livewire quick-add components and assert persisted rows.
- [Tier defaults could differ from the base price] → Assert the intended base-to-tier defaulting for every business in the Sales quick-add test.

## Migration Plan

No data migration is required. Deploy the validation and tests together. Rollback consists of reverting the context-specific validation change; persisted products are not rewritten.

## Open Questions

None.
