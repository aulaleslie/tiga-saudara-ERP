## Context

The cash pickup amount input (`#pos-pickup-amount`) is a plain native `<input type="number">` with no formatting. The staged multi-payment "Jumlah Pembayaran (Rp)" input (`#staged-amount-input`) already implements the desired UX in `public/js/pos-staged-payment.js` via `setupAmountInputFormatter()`: a `type="text"` input, digit-only stripping on every keystroke, `Intl.NumberFormat('id-ID')` thousand-separator display, and the raw digit string cached on `input.dataset.rawValue` for logic/submission to read.

Both `<x-nominal-field>` (the app's general reusable currency Blade component) and the staged-payment formatter were considered as the source pattern; the user explicitly wants parity with the staged-payment field specifically, not `<x-nominal-field>` (different visual format: decimals + "RP" prefix vs. staged-payment's whole-number, unprefixed display).

The cash pickup amount currently flows through: live expected-cash fetch (`fetchLiveExpectedCash()`) → input listener enabling/disabling "Lanjut" → step-1-to-step-2 transition re-check → final confirm/submit re-check → server-side re-validation in `PosSessionController::pickup()`. All of these except the display formatting itself are out of scope.

## Goals / Non-Goals

**Goals:**
- Cash pickup amount input visually and behaviorally matches staged-payment's formatted amount input (digit grouping, whole rupiah only, no native spinner).
- All three amount-parsing call sites and both reset sites are updated consistently so no site silently misreads a formatted string as a number.
- Zero behavior change to the staged-payment flow, `<x-nominal-field>`, or server-side validation.

**Non-Goals:**
- Extracting a shared/reusable formatter module. Logic is intentionally duplicated per user direction, to avoid any coupling risk with the working staged-payment code.
- Adding decimal support to cash pickup (it has never supported decimals).
- Changing the live expected-cash fetch, min/max validation rules, or OTP/supervisor approval flow — those requirements are already covered by `pos-cash-pickup-live-expected` and are unchanged.

## Decisions

**Duplicate the formatter rather than extract a shared helper.** The user explicitly chose this to avoid any risk of regressing the staged-payment modal, which is working correctly today. The formatter is ~15 lines (strip non-digits, `Intl.NumberFormat`, dataset caching) — small enough that duplication cost is low and isolation benefit is high.

**Switch `type="number"` → `type="text" inputmode="numeric"`.** A formatted display string like `"150.000"` is not a valid HTML `number` input value, so the input type must change to `text` to hold it. `inputmode="numeric"` preserves the numeric keyboard on mobile/tablet POS terminals, matching staged-payment's input.

**Read raw amount from `dataset.rawValue` at all three parse sites**, instead of `Number(input.value)`. This is the critical correctness fix: `Number("150.000")` parses as `150` (JS treats `.` as a decimal point), which would silently under-report the pickup amount by 1000x if left unchanged. All three sites (input listener, "Lanjut" click, confirm/submit click) must switch together — leaving any one on `Number(input.value)` reintroduces the bug for that code path.

**Drop native `min`/`step` attributes.** They no longer apply to a `text` input and were never the actual source of truth for validation (JS logic already compares `Number(...)` against `currentSessionData.expected_cash`, and the server independently re-validates against the DB-derived expected cash). Removing them avoids dead/misleading markup.

## Risks / Trade-offs

- **[Risk] Missing a parse site during the edit** → could silently break amount submission or the "Lanjut" enable/disable logic. **Mitigation**: exact line-level checklist in tasks.md covering all 3 parse sites + 2 reset sites, verified by manual click-through after the change (enter formatted amount, confirm displayed confirmation amount matches, confirm submitted amount matches raw digits, confirm modal reset clears both `.value` and `dataset.rawValue` on reopen).
- **[Risk] Duplicated formatter logic drifts from staged-payment's over time** → accepted trade-off per explicit user direction; not a defect to fix now.
- **[Risk] Removing `min`/`step` attributes changes native browser validation-bubble behavior** → acceptable since JS-level and server-level validation already fully cover the min/max checks; no functional gap.

## Migration Plan

Single-PR change, no data migration, no feature flag needed — this is a pure front-end formatting/plumbing change confined to two Blade files. Rollback is a straightforward revert of the diff.

## Open Questions

None outstanding — scope and approach were confirmed directly with the user (duplicate, not extract; match staged-payment exactly; no decimals).
