## Context

Sales and Purchase each render a Livewire cart with an Alpine-controlled inline `Total Baris` editor. The collapsed value is derived from the cart line's canonical `sub_total`; the input is separately bound to component `line_total` state. A correct collapsed total can currently open as a stale or truncated number, such as `4650` for a Rp46.500 line. A manually entered replacement value then behaves correctly, which confines the defect to editor initialization rather than calculation or persistence.

The change spans two cart implementations and both new-document and edit-document hydration paths. It must preserve existing tax inclusion, discount reversal, bundled-row restrictions, Sales manual-pricing authority, and cart persistence behaviour.

## Goals / Non-Goals

**Goals:**

- Ensure each opened standard-line Total Baris editor starts from the full canonical current line total.
- Make the DOM identity and initialization behaviour reliable across Livewire re-renders for both Sales and Purchase.
- Cover a trailing-zero amount (`46500`) plus create and edit cart hydration paths.

**Non-Goals:**

- Changing how final totals, discounts, tax, rounding, or manual pricing are calculated.
- Introducing currency masking, locale parsing, database changes, or changes to bundled-row editability.

## Decisions

### Use canonical cart subtotal as the editor's initialization source

The editor will be initialized from the current cart line's authoritative subtotal whenever it becomes available for editing, while retaining `line_total` as the Livewire transport state for an eventual committed edit. This prevents a stale client input value from becoming the apparent value to the user.

Alternative considered: change the calculation handler to detect and repair a factor-of-ten value. Rejected because it would silently mutate legitimate user input and addresses the symptom after the user has been misled.

### Give dynamic Purchase cart rows and Total Baris controls stable identities

The Purchase cart will use stable Livewire keys equivalent in intent to the existing Sales row identity, with a distinct identity for the line-total editor where necessary. This makes a product selection or edit-cart hydration update replace/reconcile the correct DOM element instead of preserving a prior editor state.

Alternative considered: force a full component refresh on every open. Rejected because it is disruptive to user input and can create avoidable Livewire request timing issues.

### Apply the same observable contract to Sales and Purchase

The implementations can remain locally idiomatic, but both must expose the exact canonical raw value on open and retain a manually entered replacement through blur/commit. Tests will cover each component's create and existing-document hydration path.

Alternative considered: fix Purchase only because it lacks a row key. Rejected because Sales uses the same editor lifecycle and the user-facing Total Baris contract must be consistent.

## Risks / Trade-offs

- [A keyed editor can be recreated during a cart update and discard an in-progress edit] → Limit replacement to identity-changing cart updates; retain normal deferred edit behaviour while the identity is unchanged.
- [A rendered raw input could diverge from post-tax/discount totals] → Initialize only from the cart line's canonical subtotal and continue to update `line_total` whenever cart calculations recalculate it.
- [Server-only Livewire tests may not detect a DOM morph defect] → Add rendered-value assertions and, if the existing test tooling supports it, browser-facing coverage of the click/open interaction.

## Migration Plan

Deploy as an application-only UI/state fix. No migration or backfill is required. Rollback is a code revert; existing document values and cart persistence are unaffected.

## Open Questions

None. The current requirement is limited to faithfully presenting the already-authoritative total.
