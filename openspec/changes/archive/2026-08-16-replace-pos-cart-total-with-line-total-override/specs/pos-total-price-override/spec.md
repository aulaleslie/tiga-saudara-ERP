## REMOVED Requirements

### Requirement: POS SHALL support exact cart-total overrides
**Reason**: Editing the entire cart total is not a supported POS operation because it silently redistributes one decision across unrelated rows.

**Migration**: Use the row-scoped “Ubah Total” action for each row that requires correction. Existing historical override records remain readable.

### Requirement: Approved target total SHALL reconcile exactly while preserving visible rows
**Reason**: Proportional allocation across all cart rows is being retired in favor of an authoritative total on one explicitly selected row.

**Migration**: Apply an exact row total through `pos-line-total-override`; split-owner allocation occurs only within that selected row during checkout.

### Requirement: Total override SHALL update row pricing without breaking normal checkout
**Reason**: Cart-wide allocation is no longer allowed to mutate every row's pricing source.

**Migration**: Checkout consumes each row's own authoritative total, including any approved row-total override.

### Requirement: Total-price override SHALL be fully auditable and bound to the approved cart
**Reason**: New cart-wide requests are retired; audit and stale-state protection move to the selected line.

**Migration**: Preserve historical `TOTAL_PRICE_OVERRIDE` audit records as read-only history and use line-bound row-total audit records for new actions.

