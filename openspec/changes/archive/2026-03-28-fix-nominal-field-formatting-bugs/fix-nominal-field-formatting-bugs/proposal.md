## Why

Current product nominal field behavior is still inconsistent across create/edit flows:
- Create blur sometimes keeps raw `50000` instead of formatting it
- Edit blur can corrupt `65000` into `0.6`/`0.65`
- Currency symbol/separator behavior currently depends on runtime setting values, while the desired UX is fixed and deterministic

The desired contract is explicit and stable:
- **formatted**: `RP 50.000,00`
- **raw**: `50000`

To make this reliable, product nominal formatting must stop depending on dynamic DB currency settings and avoid parsing paths that are sensitive to mask lifecycle state.

## What Changes

- Define a fixed product-nominal currency profile: prefix `RP `, thousands `.`, decimal `,`, precision `2`
- Require deterministic parse/format logic for product nominal inputs (no system-locale formatting APIs)
- Require raw-value integrity throughout the focus/blur lifecycle
- Remove product nominal field dependence on `settings()->currency` for display formatting behavior

## Capabilities

### Added Capabilities

- `nominal-field-deterministic-rp-format`: Product nominal inputs always render with fixed `RP` formatting independent from DB/system locale settings
- `nominal-field-raw-value-integrity`: Product nominal raw value is preserved through focus/edit/blur without decimal-shift corruption

### Modified Capabilities

- `nominal-field-component`: Clarify deterministic formatted/raw contract and parsing invariants for create/edit product flows

## Impact

- Affects product create/edit nominal fields and related conversion/quick-add formatting paths
- Scope is formatting and parsing behavior only (no pricing business-rule change)
- Requires regression validation for `50000 -> RP 50.000,00` and edit flow `60000 -> 65000 -> RP 65.000,00`
