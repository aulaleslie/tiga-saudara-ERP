## Context

`AdjustmentProductTable` currently binds Enter and the scan button directly to `processScan()`. The method reads a shared `scanInput` property, clears it only after the response succeeds, and updates nested `products.*.good_count` or `products.*.bad_count` state. Rapid hardware scans can therefore overlap against one stale Livewire snapshot or concatenate in the visible input. In the installed Livewire 3.0.5 runtime, a server-updated nested model can also be correct while a dirty number input continues displaying an older DOM value.

The component additionally accepts public location state without the active-setting and non-consignment checks already established by the breakage editor. Persistence performs later validation, but scan-time baseline capture is itself a data-access boundary and must not trust client state.

## Goals / Non-Goals

**Goals:**

- Preserve every rapid scanner submission in FIFO order and apply it exactly once during ordinary successful operation.
- Prevent barcode concatenation, response-driven input erasure, and lost increments.
- Keep visible Good/Bad counts consistent with Livewire state and the submitted count draft.
- Make ambiguous request outcomes visible without risking double application.
- Enforce owned, standard-location context before baseline capture or display.
- Preserve keyboard focus and manual count editing.

**Non-Goals:**

- Changing product, conversion, ambiguity, or serial-resolution semantics.
- Restricting stock-opname serial observations to the selected location; cross-location/status observation is intentional reconciliation evidence.
- Adding request idempotency, changing approval reconciliation, or changing the count-draft schema.
- Rebuilding the shared resolver or running the repository's full test suite.

## Decisions

### Use one stable FIFO scan controller per Livewire component

Capture the scanner value synchronously on Enter/click, clear the current DOM input immediately, and enqueue the captured string. Drain one `component.call('processScan', code)` at a time, awaiting the complete Livewire round trip before dispatching the next entry. Resolve the current component and current DOM elements at use time so Livewire morphs do not leave handlers or feedback attached to stale nodes.

Use a guarded, delegated browser listener or an equivalently stable component-external controller rather than attaching queue-critical state only to a morphable input. A guard MUST prevent duplicate listener registration.

Alternative considered: disabling the input while a request is active. This drops physical scanner keystrokes rather than preserving them. Direct `wire:keydown` is retained nowhere on the scan input because it permits overlapping requests outside the queue.

### Pass the captured code explicitly

Change `processScan()` to accept an optional explicit code, using `scanInput` only as a compatibility fallback for existing direct calls. Queue entries therefore remain independent of Livewire model synchronization and input clearing.

### Do not retry an already-dispatched mutation

Component lookup may be retried with a short bounded delay because no request has been sent. Once `component.call()` is invoked, a rejected promise has an ambiguous result: the server may have processed the scan and only lost the response. Remove that entry without resending it and show an always-visible, accessible status-unknown message containing the scanned code and recovery instruction.

Automatic retry after dispatch would require an idempotency token and server-side deduplication, which is outside this change.

### Reconcile visible nested inputs after successful morphs

Give product rows stable product-ID keys and render authoritative values for editable Good/Bad inputs. After a successful scan call completes, schedule a post-morph synchronization that re-queries the current component's count inputs and forces their DOM values from current `products.*.good_count` and `products.*.bad_count` state. Do not cache row input nodes and do not include changing quantities in row keys.

Manual edits remain `lazy`/change-driven. Synchronization occurs only after a successful scan mutation, preventing unrelated failure paths from overwriting operator input.

### Treat location as server-authorized context

Lock public selected and pending location identifiers against direct Livewire mutation. Route every PHP-side assignment through one method that resolves the location against `session('setting_id')` and `is_consignment = false`. Repeat the ownership check at baseline capture/display boundaries as defense in depth, reset the dropdown when a requested location is invalid, and disclose no foreign-location stock information.

This does not change serial reconciliation rules: an owned selected location defines the count document, while a scanned existing serial may retain evidence of a different recorded source location.

### Verify focused contracts and leave browser timing to humans

Automated tests cover explicit-code processing, sequential accumulation, location tampering, disclosure prevention, and rendered scanner/count hooks. A Bahasa Indonesia manual checklist covers rapid physical scans, Good/Bad switching, conversion scans, request failures, focus, displayed count consistency, and manual edits. No full-suite test task is included.

## Risks / Trade-offs

- **A dispatched request can have an unknown outcome** → Never retry it automatically; identify the code in an accessible warning and instruct the operator to verify the displayed count.
- **Post-morph synchronization relies on Livewire/Alpine DOM internals** → Re-query current nodes, prefer the runtime's force-model-update hook when present, and retain a plain value fallback plus explicit server-rendered values.
- **A rapid queue can continue after an ambiguity dialog appears** → Pause draining while operator selection is required, or define the scan method response/event contract so later entries cannot bypass unresolved ambiguity.
- **Location hardening may expose legacy tests or callers that mutate public state directly** → Preserve supported server event entry points and update focused tests to use them; reject only untrusted direct mutations.
- **Browser behavior cannot be fully represented by PHP Livewire tests** → Require human verification before rollout.

