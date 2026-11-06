# Spec Delta

## ADDED Requirements

### Requirement: Draft valuation uses the persisted resolution intent
POS draft create and edit SHALL use the same normalized line resolutions, quantities, bundle scope, and serial identities for valuation and persistence. A supported header default applied to an omitted resolution MUST also govern valuation. Explicit line-level none MUST remain authoritative and contribute no return value or execution effects.

#### Scenario: Omitted line resolution uses cash default
- **WHEN** create or edit submits a supported cash-return header default and omits a positive line's resolution
- **THEN** the normalized cash-return choice governs line valuation, expected amount, and header total consistently
- **AND** the line does not receive zero value merely because its original payload omitted the resolution

#### Scenario: Explicit none survives header default
- **WHEN** a line explicitly selects none while another line remains actionable under a cash-return header
- **THEN** the none line stays unselected with zero expected refund and no execution effects

#### Scenario: Valuation evidence cannot be established
- **WHEN** normalized intent has no reliable source valuation
- **THEN** save or approval reports an actionable error rather than silently using an undefined or inconsistent fallback amount

#### Scenario: Draft edit preserves established POS rules
- **WHEN** an authorized user changes a valid draft quantity
- **THEN** totals follow the existing cumulative rounding, residual caps, and whole-bundle or parent-only rules
- **AND** the edit makes no stock, dispatch, payment, or completed-return mutation
