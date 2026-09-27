## Context

`Modules/Pos/Resources/views/sell.blade.php` currently combines the POS sell screen layout, large inline CSS, modal markup, and a large inline JavaScript closure in one 5,000+ line Blade file. The file is difficult to review and maintain, but it is also a critical cashier workflow where regressions are expensive.

A previous attempt to split the view broke the rendered POS screen and had to be cancelled. This design therefore treats preservation as the primary constraint: each extraction must keep the browser-facing output and behavior stable before any further slice is attempted.

The current test baseline is not fully clean for `Modules/Pos/Tests/Feature/POSSellShellScanUiTest.php`: the test expects `vendor/zxing/index.min.js`, while the current view loads `vendor/html5-qrcode/html5-qrcode.min.js`. That mismatch must be handled as a known baseline issue and must not be confused with regressions from this refactor.

## Goals / Non-Goals

**Goals:**
- Reduce the size of `sell.blade.php` by extracting static Blade/CSS blocks into partials.
- Preserve DOM IDs, classes, modal attributes, script order, style order, Blade variable usage, route output, permission checks, and JavaScript behavior.
- Make extraction incremental, with verification after each small slice.
- Prefer Blade includes that render in the same location over asset pipeline or JavaScript module changes.
- Leave the POS sell screen easier to navigate without changing cashier workflows.

**Non-Goals:**
- Do not rewrite the POS UI, cart flow, checkout flow, customer flow, scanner flow, or payment flow.
- Do not move the large inline JavaScript closure into a public `.js` file.
- Do not introduce new frontend dependencies, bundling changes, or Vite changes.
- Do not rename DOM IDs/classes or adjust selectors.
- Do not use this change to fix unrelated baseline test failures unless required to establish the verification baseline.

## Decisions

### Decision: Start With CSS as a Blade Partial

Move the existing inline `<style>...</style>` block into `Modules/Pos/Resources/views/sell/css/styles.blade.php` and include it from the same `@push('page_css')` location.

Rationale: this keeps CSS inline, keeps stack order stable, avoids public asset path changes, and removes the largest low-risk block from the root view.

Alternative considered: move the CSS to a public CSS asset. This was rejected for the first slice because it changes asset loading, caching, and ordering.

### Decision: Extract Static Markup Before JavaScript

After CSS is verified, extract modal and shell markup into Blade partials while keeping the includes at the same relative positions in the root view.

Rationale: Blade partials inherit the view context and can preserve output with minimal risk. The inline JavaScript relies on many DOM IDs and shared state, so moving it early would increase risk.

Alternative considered: extract JavaScript utilities or handlers first. This was rejected because the closure contains shared state, route values, permission flags, and global scanner callbacks.

### Decision: Preserve the Rendered Contract

Each extraction slice must preserve the POS sell page's browser-facing contract:
- same important DOM IDs and classes;
- same modal IDs, attributes, and placement order;
- same external script includes and order;
- same inline script content and execution order;
- same permission-gated buttons and links;
- same route/url output for JavaScript endpoints.

Rationale: existing JavaScript and feature tests depend on selectors and server-rendered values. The safest refactor is source decomposition, not output redesign.

Alternative considered: accept small DOM cleanup while splitting. This was rejected because cleanup increases the chance of repeating the previous breakage.

### Decision: Verify Each Slice Before Continuing

Implementation should capture a rendered baseline before each extraction slice, apply one small extraction, clear compiled views, and compare the rendered output for meaningful equivalence.

Rationale: a small render comparison catches broken includes, missing variables, misplaced stacks, and accidental markup loss before multiple changes accumulate.

Alternative considered: rely only on existing POS tests. This was rejected because the current POS shell test file has known baseline failures unrelated to this refactor.

## Risks / Trade-offs

- Existing test baseline is partially failing -> Record the failure before implementation and distinguish baseline failures from new regressions.
- Blade include path typo can break the whole POS page -> Add each partial one at a time and run view rendering immediately after each extraction.
- Whitespace can change after extraction -> Treat insignificant whitespace changes as acceptable only if DOM/script/style content is preserved.
- Permission-gated markup can silently change if variables are not in scope -> Use Blade partials, not components with isolated props, for the first pass.
- Modal order can affect JavaScript selectors and Bootstrap behavior -> Keep includes in the same order as the original markup.
- Large inline JavaScript remains in the root view after the first slices -> Accept this trade-off to avoid high-risk behavior changes.

## Migration Plan

1. Establish and document the current verification baseline, including known failing POS shell assertions.
2. Extract CSS into `sell/css/styles.blade.php` and include it from the existing `@push('page_css')`.
3. Clear compiled views and verify the POS sell page renders.
4. Compare rendered output against the pre-extraction baseline, allowing only insignificant whitespace changes.
5. Run the targeted POS sell shell test and confirm no new failures beyond known baseline issues.
6. Extract one static modal partial at a time, repeating render and test checks after each slice.
7. Extract static shell/card partials only after modal extraction is stable.
8. Stop before JavaScript extraction.

Rollback is straightforward: inline the partial content back into `sell.blade.php` or revert the specific extraction slice.

## Open Questions

- Should the existing scanner-library test expectation be updated from ZXing to `html5-qrcode`, or should the view be restored to load ZXing before this refactor begins?
- Should a dedicated render-equivalence test be committed as part of this change, or used as an implementation-time guard only?
