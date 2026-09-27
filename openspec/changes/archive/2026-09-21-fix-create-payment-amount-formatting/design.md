# Design

## Context

The single Sales and Purchase payment views contain nearly identical inline formatters, but they render with `en-US` while stripping separators configured by the active currency. The global Purchase form has a third formatter with separate display and hidden inputs, while global Sales and POS use native number inputs and parse their visible values directly. Commit `542fa25769387559320407a2747879199e6db0fc` established a safer consignment pattern: keep a canonical value, expose it raw on focus, localize only the blurred display, and normalize before submission.

## Goals / Non-Goals

**Goals:**

- Give all scoped payment amount inputs one predictable interaction and parsing contract.
- Preserve arbitrary canonical decimal precision without confusing Indonesian display separators with canonical decimal syntax.
- Keep global totals, POS previews, DataTables rows, maximum checks, and submitted payloads aligned with the same canonical values.
- Reuse a small shared browser-side implementation rather than maintaining five divergent inline variants.

**Non-Goals:**

- Changing server-side payment eligibility, authorization, balance calculation, allocation priority, or transaction semantics.
- Changing read-only currency displays elsewhere in Sales, Purchase, or POS.
- Reformatting POS checkout tender, cash pickup, product pricing, discounts, or customer-credit fields.
- Introducing a new JavaScript framework or changing database schemas.
- Running the full automated test suite.

## Decisions

### Use one shared amount-input helper with an explicit canonical value

Create a reusable browser-side helper for the scoped payment forms. Each enhanced text input keeps its accepted canonical decimal string independently from its localized visible text. The canonical value retains every fractional digit entered; the blurred value is only a rounded two-decimal presentation. Consumers read the canonical value for calculations and payloads, and form submission replaces or mirrors the display value with the canonical value expected by Laravel numeric validation.

This follows the proven consignment interaction but narrows precision to payment currency. Copying the existing inline scripts was rejected because their parsing and formatting rules have already drifted.

### Use text inputs with decimal input mode

Formatted fields use text inputs with `inputmode="decimal"`. Native number inputs cannot hold localized values such as `1.250.000,50`, so retaining `type="number"` would make blur formatting invalid browser state.

### Format on initialization and blur, reveal raw value on focus

Initial/default and old-input values are normalized into canonical form and rendered as blurred display values. Focus reveals digits plus an optional dot decimal separator with insignificant trailing fractional zeros removed. Blur validates the edit, retains its full canonical precision, and renders periods for thousands; whole values omit decimals, while fractional values are rounded to exactly two displayed decimal digits with a comma separator.

Live formatting on every keystroke was rejected because it moves the caret and conflicts with the requested raw focused editing experience.

### Reject ambiguous or invalid edits visibly

The parser accepts non-negative canonical focused input with any number of fractional digits and the localized value produced by the helper. A non-empty syntactically invalid value remains visible and invalid; it is not coerced to zero. Submit is blocked until corrected. Empty optional/global zero allocation fields remain representable as zero according to their existing form behavior.

### Preserve form-specific integration behavior

- Single Sales and Purchase forms submit the canonical `amount`.
- Global Sales calculates totals and submits allocations from canonical values.
- Global Purchase retains DataTables all-page traversal and hidden-field submission, but synchronizes those hidden fields from canonical values.
- Global POS uses canonical allocations for displayed totals, preview requests, validation, and final submission.
- Existing maximum-due enforcement remains in place and compares canonical decimal amounts.

### Verify only the focused surface

Add or extend focused tests that render each affected view and assert the formatting hook/field contract, plus targeted submission tests demonstrating canonical decimal values are accepted unchanged. Run only the relevant Sales, Purchase, and POS payment test files or filters; a full-suite run is explicitly outside this change plan.

## Risks / Trade-offs

- [Risk] Existing values or validation returns may contain either canonical or localized text. → Normalize both supported representations at initialization and cover old-input restoration.
- [Risk] Floating-point arithmetic could produce display artifacts in totals. → Preserve canonical decimal strings at field boundaries and round only the presentation to two currency decimals; server-side payment validation remains authoritative for persisted numeric precision.
- [Risk] DataTables can detach non-visible Purchase rows. → Continue iterating through the DataTables row collection and synchronize canonical values into form-owned hidden inputs before submission.
- [Risk] A shared helper could accidentally affect unrelated monetary fields. → Activate it only through an explicit payment-amount marker on the five scoped views.

## Migration Plan

Deploy the view/helper changes without data migration or backfill. Rollback consists of reverting the shared helper integration and restoring the prior view scripts; persisted payment records are unaffected because the server continues receiving canonical numeric amounts.
