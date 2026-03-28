## Context

The product nominal field flow currently combines:
- `x-nominal-field` (main product prices)
- conversion table visible/hidden inputs
- optional product quick-add modal formatter

Observed failures indicate lifecycle-sensitive parsing:
- after mask teardown on focus, raw text like `65000` is sometimes interpreted as `0.65` during blur
- formatted output may stay unformatted after blur in some create flows

The target behavior is deterministic:
- formatted output must always be `RP X.XXX,YY`
- raw output must remain unformatted numeric (`50000`, `1234.56`)
- behavior must not vary with DB currency configuration or browser/system locale.

## Goals / Non-Goals

**Goals**
- Enforce a fixed product nominal formatting profile: `RP ` + `.` thousands + `,` decimal + precision `2`
- Preserve raw numeric value integrity across load/focus/input/blur
- Remove dependence on system-locale APIs for product nominal formatting
- Keep submission payloads numeric (raw) and display payloads formatted

**Non-Goals**
- Changing global currency behavior for unrelated modules
- Changing business pricing calculations
- Refactoring unrelated UI components

## Decisions

### Decision 1: Fixed Product Nominal Currency Profile

Product nominal fields use a hardcoded display profile:
- prefix: `RP `
- thousands separator: `.`
- decimal separator: `,`
- precision: `2`

This profile applies to create/edit product nominal behavior regardless of DB currency setting.

### Decision 2: Deterministic Parser/Formatter Contract

Product nominal formatting logic must follow this invariant:
- `parse(display) -> raw`
- `format(raw) -> display`

Where:
- `raw` is canonical numeric text for submission/calculation
- `display` is canonical `RP` text for user view

No `toLocaleString`/`Intl.NumberFormat` dependence is allowed for this path.

### Decision 3: Raw Integrity During Focus/Blur Lifecycle

When input is focused, user edits raw text.
On blur, system must parse the current raw text directly and re-render deterministic formatted text.

Parsing on blur must not depend on stale mask state that can reinterpret plain digits as decimal fractions.

### Decision 4: Product-Scope Configuration Isolation

For product nominal fields, display configuration is fixed locally to avoid drift.
Other modules may keep their own currency behavior unless explicitly included in this change.

## Risks / Trade-offs

- Divergence from installation-level currency setting in product forms is intentional and should be clearly documented.
- Existing code paths that assumed configurable symbol/separators will need explicit migration in scope.
- A mixed strategy (fixed in product, configurable elsewhere) can create expectation mismatch; release notes should call this out.

## Validation Focus

Primary regression checks:
- Create: type `50000` -> blur -> `RP 50.000,00`, hidden raw `50000`
- Edit: load `RP 60.000,00` -> focus `60000` -> edit `65000` -> blur `RP 65.000,00`
- Decimal input: `1234.56` -> blur `RP 1.234,56`, raw `1234.56`
