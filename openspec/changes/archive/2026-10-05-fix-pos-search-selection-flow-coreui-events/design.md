# Design

## Context

The POS layout loads jQuery 3.7 and `@coreui/coreui` 3.4.0 (via `resources/js/app.js`). Bootstrap's own modal JS is not loaded. CoreUI provides `$(el).modal('show'|'hide')`, but it fires lifecycle events as `show.coreui.modal`, `shown.coreui.modal`, `hide.coreui.modal` and `hidden.coreui.modal`. These go out both as jQuery events and as native events. Nothing fires `*.bs.modal`.

In `Modules/Pos/Resources/views/sell.blade.php`:
- `searchResultSelectionFlowPending` is set on a unit/bundle card click. It is cleared only in a `show.bs.modal` handler on `#pos-search-results-modal`, which never fires (confirmed with console probes: `[card] true 5095` on re-click).
- Cari Produk `shown.bs.modal` (keyboard setup, keyword focus) and `hidden.bs.modal` (scanner refocus) are dead too.
- Unit and bundle modal `hidden.bs.modal` handlers call `cancelActiveSelection()` and reset `isModalTransitioning`. They are dead as well, so dismissing those dialogs leaves `activeSelectionOperation` active.

## Goals / Non-Goals

**Goals:**
- No click on a Cari Produk result card is silently dropped after a unit or bundle flow ends.
- The search, unit, and bundle modal lifecycle handlers in the sell view run under CoreUI.

**Non-Goals:**
- Auditing every other `*.bs.modal` listener in POS (serial, reduce-qty, checkout, pickup, override modals). They can be noted for a follow-up but are not changed here.
- Replacing CoreUI or loading Bootstrap's JS.
- A browser-automation test. A person verifies in the browser.

## Decisions

1. **Clear the pending flag from POS-owned signals, not from modal events.**
   - Clear it in the Cari Produk button click handler, before `modal('show')`.
   - Clear it on success, error, no-response, or cancellation only when that operation has `fromSearchSelectionFlow`; simple-product and scanner operations must leave a queued unit/bundle selection pending.
   - Keep the guard that ignores card clicks while a unit/bundle flow is in progress.
   - *Alternative:* only add `show.coreui.modal` to the existing binding. Rejected as the sole fix, because it keeps correctness tied to a library's event naming, which is exactly how this broke.
   - The Cari Produk button click owns the reopen release; no modal `show` release listener is needed.

2. **Bind both event names using one jQuery binding per handler** for the search `shown`/`hidden`, unit `hidden`, and bundle `hidden` listeners.
   - Use jQuery so that a single binding receives the CoreUI trigger.
   - Native `addEventListener` fallbacks also register the `.coreui.` name.
   - *Alternative:* a shared helper that rewrites every listener in POS. Deferred because it is outside this scope.

3. **Make sure dismissing the unit/bundle dialog cancels its pending selection.** Once the `hidden` handlers run under CoreUI, the existing `isModalTransitioning` and `phase` checks already tell a dismissal apart from a choice-driven hide. No new logic is needed beyond the binding.

4. **Refocus the scanner only after Cari Produk closes without an active selection.** The search `hidden` handler skips focus while a selection operation is active, its flag is pending, or a unit/bundle dialog is visible.

## Risks / Trade-offs

- [Restored `hidden` handlers start running and may behave in ways nobody has seen in practice; for example, `cancelActiveSelection()` focuses the scanner input after a dismissal] → This is the intended, previously specified behaviour. A person checks it in the browser.
- [`isModalTransitioning` was never reset, because the hidden handler never ran, so it could currently be stuck at `true`] → Restoring the handler resets it. The person's browser checklist covers dismissing a dialog after a completed choice.
- [Both a `.bs` and a `.coreui` event could fire if Bootstrap JS were added later] → All handlers are idempotent (clear the flag, focus an element, cancel only when an active op exists).
