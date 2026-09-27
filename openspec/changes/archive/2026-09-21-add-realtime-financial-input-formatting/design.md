# Design

## Context

The repository currently has two overlapping monetary input implementations. The reusable `<x-nominal-field>` component owns an inline formatter and hidden canonical input for product prices, while `public/js/payment-amount-input.js` owns state and canonical accessors for five Sales, Purchase, and POS payment forms. Both implementations currently reveal raw text on focus and localize on blur. Existing dynamic rendering includes Livewire component updates and DataTables-managed allocation rows.

## Goals / Non-Goals

**Goals:**

- Use one input-state model for real-time grouping, decimal entry, canonical reads/writes, and silent rejection.
- Preserve the operator's logical caret position across inserted or removed thousands separators.
- Keep existing form-specific totals, previews, maximum checks, hidden inputs, and server submissions based on canonical values.
- Make initialization idempotent for static, Livewire-rendered, modal, and DataTables-managed fields.

**Non-Goals:**

- Changing monetary validation, balance limits, payment allocation, persistence precision, or authorization.
- Applying this behavior to quantity, percentage, conversion-factor, read-only, or hidden-only inputs.
- Introducing a new JavaScript framework or dependency.
- Migrating every legacy `jquery-mask-money.js` consumer in this change.
- Running the full automated test suite.

## Decisions

### Use a canonical editing model and derive the visible value after every accepted operation

The formatter will model the editable value as ASCII digits plus an optional single `.` and fractional digits. The DOM displays the integer digits grouped with `.` and maps the canonical decimal point to `,`. For example, canonical `120000.23` renders as `120.000,23`.

The canonical string, including fractional trailing zeros, remains authoritative. Calculations and form integrations obtain it through the shared API or synchronized hidden input rather than reparsing localized text with `parseFloat`. A trailing decimal point is retained as an editing state; canonical submission removes that trailing point and submits the equivalent whole number.

Formatting a numeric value through `Intl.NumberFormat`, or manipulating a JavaScript `Number` as the source of truth, was rejected because either can introduce locale dependence, discard trailing zeros, or lose decimal precision.

### Validate the proposed edit rather than cleaning it after insertion

The formatter will evaluate input intent before committing it where `beforeinput` provides enough information, then verify the resulting DOM value on `input` for paste, autofill, and browser compatibility. An accepted candidate matches digits with at most one canonical decimal point. A rejected insertion restores or retains the previous accepted display, canonical string, selection, and caret without error styling or messages.

`keydown` alone was rejected because it does not cover paste, drag/drop, autofill, mobile input, or accessibility editing paths. Stripping invalid characters after accepting an edit was rejected because it can partially accept a paste and visibly move the value or caret.

### Track caret position by logical numeric tokens

Before rerendering, the formatter records the selection in terms of integer digits, decimal boundary, and fractional digits rather than raw DOM offsets. After grouping, it maps those logical positions back to display offsets. This keeps insertion, deletion, and selection replacement stable even when a grouping period appears or disappears.

The formatter will preserve browser-native navigation and shortcut behavior; it only gates text-producing operations. This avoids brittle key-code allowlists.

### Generalize the existing payment helper and adapt the nominal component

The shared browser helper will expose idempotent enhancement plus canonical get/set/normalize operations for any marked financial input. Existing `data-payment-amount` integration remains supported during migration so the five payment views do not require a flag-day change. The nominal component will delegate visible-input behavior to the same helper while retaining its hidden input for its current Livewire and form-binding contract.

Centralizing the formatter was chosen over adding a second real-time script to `<x-nominal-field>` because separate parsers would recreate the drift this change is intended to remove. The helper may be renamed to a financial-input name, but compatibility aliases must keep existing payment consumers operational during the change.

### Keep field integration explicit

Only explicitly marked editable monetary inputs receive the behavior. The helper will not infer financial fields from names or apply itself to every numeric input. Read-only currency displays, quantities, percentages, conversion factors, and unrelated text inputs remain unchanged.

Initialization remains idempotent and supports initial canonical values, server validation round-trips, Livewire rerenders, and DataTables row lifecycle. Form-specific code continues to perform business validation and totals, but reads the canonical helper value.

### Use focused verification

Pure JavaScript tests will exercise the state machine and DOM behavior: progressive grouping, fractional entry, trailing zeros, incomplete decimal entry, silent rejection, paste, deletion, selection replacement, caret mapping, programmatic set/get, and repeated initialization. Focused Laravel view/feature tests will verify nominal and payment hooks plus canonical submission wiring. Only these relevant test files and filters are planned; no full-suite command is included.

## Risks / Trade-offs

- [Risk] Display `.` has a different meaning from the operator-entered decimal `.`. → Treat keyboard/input intent as canonical before rendering, and never infer a new canonical edit by applying `parseFloat` to the localized display.
- [Risk] Browser and mobile input events differ. → Use `beforeinput` when possible, retain an `input` reconciliation fallback, and cover paste and mobile-oriented `inputmode="decimal"` behavior in focused tests.
- [Risk] Reformatting can cause caret jumps. → Map selections through logical numeric-token positions and restore them after every accepted render.
- [Risk] Existing scripts may read `.value` directly and see localized text. → Audit affected integrations and replace calculations, comparisons, previews, and submit normalization with canonical helper access.
- [Risk] Livewire or DataTables may replace initialized nodes. → Make enhancement idempotent and invoke it from existing render/draw integration points.
- [Trade-off] Pasting already-localized text such as `120.000,23` is rejected because the editing grammar deliberately permits only digits and one `.` decimal key. This keeps paste behavior consistent with the requested character gate and avoids separator ambiguity.

## Migration Plan

1. Extend or generalize the tested payment helper with the real-time state machine while retaining compatibility accessors and markers.
2. Adapt `<x-nominal-field>` to consume the shared helper and keep its hidden canonical synchronization contract.
3. Update the five scoped payment integrations so every calculation, preview, maximum comparison, and submission reads canonical values.
4. Run only the focused JavaScript and Laravel tests identified in the tasks.
5. Roll back by restoring the previous helper/component versions; no database or persisted-data migration is required.
