## Context

The purchase importer already resolves ownership in multiple places through `resolveTenant()`, `resolveEffectiveOwnerKey()`, grouping, duplicate matching, stock/price updates, and inventory transactions. The current implementation has a reduced tag mapping with only:

- `cv tiga nusa`
- `cv top it`

The requested behavior restores the full mapping that Sales import already uses, but intentionally keeps purchase-specific fallback behavior simple: blank and unmapped tags route to PERDANA rather than product markers.

## Decisions

### Decision 1: Restore full mapped-tag table for purchase import

Purchase import should use this mapping for non-Daizu rows:

```text
cv tiga nusa -> CV TIGA NUSA COMPUTER
cv top it    -> CV TOP IT INTERNUSA
aries        -> TIGA COMPUTER
rahmat       -> WHITE KNIGHT COMPUTER
agus         -> DUNIA COMPUTER
perdana      -> PERDANA
```

Rationale: these tags are known source-system owner signals, and Sales import already treats them as owner-routing tags.

### Decision 2: Keep Daizu as absolute priority

Rows whose product names match the Daizu rule still route to Daizu regardless of tag.

Rationale: Daizu ownership is product-domain-specific and should not be overridden by source tags.

### Decision 3: Use PERDANA fallback for blank and unmapped tags

If a row is not Daizu and its tag is blank or unmapped, purchase import should route to PERDANA.

Rationale: this keeps the current purchase import fallback behavior and avoids reintroducing product-marker fallback unless explicitly requested later.

### Decision 4: Apply the same effective owner everywhere

The restored effective owner should govern:

- invoice grouping
- duplicate checks
- purchase `setting_id`
- ProductPrice owner
- stock owner and location owner
- inventory Transaction owner

Rationale: split ownership bugs happen when the document owner and stock/accounting side effects use different owner rules.

## Risks / Trade-offs

- Existing tests named around ignored tag ownership will need revision.
- Historical imports with `rahmat`, `aries`, or `agus` will route differently going forward.
- The rule intentionally diverges from older product-marker fallback language: blank or unmapped tags stay PERDANA.

## Migration Plan

1. Update purchase import ownership tests to assert restored tag owner routing for `aries`, `rahmat`, `agus`, and `perdana`.
2. Add or update tests proving blank and unmapped tags still route to PERDANA.
3. Update `PurchaseImportService::$tagMapping`.
4. Verify grouping, duplicate detection, document owner, price owner, stock/location owner, and transaction owner use the restored effective owner.
5. Run focused purchase import tests.

Rollback is code-only: restore the reduced tag mapping. No schema or data migration is required.
