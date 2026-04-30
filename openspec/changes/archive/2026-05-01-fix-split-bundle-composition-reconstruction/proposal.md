## Why

Completed split bundle checkouts can render incomplete bundle composition in POS transaction detail and receipt views. In multi-owner bundle scenarios, only a subset of components may be shown under a bundled parent line, which misrepresents what the customer receives.

## What Changes

- Update bundle composition reconstruction for completed POS transactions so component rows are aggregated correctly across split Sales groups for the same bundled parent line.
- Ensure multi-owner split groups with `parent_qty = 0` (component-only ownership groups) are still included in composition mapping for the correct bundled parent line.
- Preserve existing protections so non-bundled lines do not inherit bundle components.
- Add regression tests for 2-owner and 3-owner split bundle scenarios covering transaction detail and receipt rendering.
- Add regression coverage for mixed cart lines (bundled and non-bundled same parent product) to prevent component leakage or duplication.

## Capabilities

### New Capabilities
- None.

### Modified Capabilities
- `pos-transaction-detail-bundle-display`: Clarify and enforce complete bundle component reconstruction across split Sales documents for each bundled POS line.
- `pos-professional-receipt`: Clarify and enforce complete bundle component reconstruction across split Sales documents for each bundled receipt line.

## Impact

- Affected code:
  - `Modules/Pos/Services/PosReceiptService.php` (bundle composition reconstruction logic)
  - Transaction detail and receipt consumers that render `bundle_composition`
  - POS split-bundle feature tests
- No public API contract changes.
- No database schema changes expected.
- Test suite impact in POS split-bundle and receipt/detail rendering paths.
