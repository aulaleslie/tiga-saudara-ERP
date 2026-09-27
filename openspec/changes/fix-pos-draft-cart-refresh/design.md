# Design

## Context

The transaction service already persists the draft and replaces the session cart with an empty cart inside the save operation. The save endpoint currently returns only transaction metadata, so the browser performs a second cart GET before rendering. Cart rendering derives the correct control state, but the save handler's cleanup then unconditionally enables the draft-save button. Debounced note updates can also complete across the transaction boundary unless their client generation is invalidated.

## Goals / Non-Goals

**Goals:**

- Use one successful save response as the authoritative draft-save and cart-transition result.
- Ensure all visible cart fields and controls agree with the empty snapshot.
- Prevent previous-transaction note responses from repainting the new transaction context.
- Preserve existing failure behavior and server-side draft persistence semantics.

**Non-Goals:**

- Changing draft persistence, permissions, transaction numbering, modal actions, or receipt printing.
- Changing general cart mutation concurrency or checkout finalization.
- Adding browser automation or requiring the full automated test suite.

## Decisions

### Return the empty snapshot with the save response

After the transaction service clears the session cart, the controller will obtain the resulting cart snapshot and include it as `cart_snapshot` beside the existing transaction metadata. The client will render this snapshot directly.

This keeps the response consistent with other POS cart mutation endpoints and removes the extra GET, its failure path, and its potential to observe or serve a different state. Retaining the follow-up GET was rejected because it splits one logical transition across two network requests.

### Let cart rendering own post-request control state

The save handler may restore the button label during cleanup, but it will not unconditionally enable the button. Enabled/disabled state will remain derived from the current rendered snapshot through the existing cart renderer.

Duplicating the validation expression in the cleanup block was rejected because it could drift from the renderer's cart-validation rules.

### Invalidate pending note UI work at the transaction boundary

On confirmed save success, the client will cancel a scheduled note debounce and advance the note request generation before rendering the returned snapshot. Existing generation checks will then prevent an older note response from rendering over the new context.

Adding server cancellation was rejected because the concern is client response ordering; server-side cart locking and persistence behavior remain unchanged.

### Use focused verification only

Automated verification will cover the save response contract and rendered save-handler structure/state ownership. A human will verify the browser interaction. The change will not include a full-suite test task.

### Normalize the existing capability document before archive

The current main `pos-sell-save-new` spec incorrectly uses a delta header and lacks the main-spec `Purpose` and `Requirements` structure. Implementation will normalize those headings without altering requirement text or scenarios, allowing this change's delta to archive into the existing capability. Creating a duplicate capability was rejected because the behavior already belongs to `pos-sell-save-new`.

## Risks / Trade-offs

- [Building the snapshot after save could fail after the draft is already committed] → Reuse the established snapshot service and keep snapshot construction immediately adjacent to the successful save response; focused tests will cover the response.
- [A different pending mutation type could cross the same boundary] → Scope this fix to the identified note flow and authoritative response; broader request-generation coordination is outside this focused change.
- [Existing consumers may ignore the new response field] → The field is additive, so existing transaction metadata consumers remain compatible.

## Migration Plan

Deploy the additive response and client update together. No data migration or configuration change is required. Rollback consists of reverting the controller, client, and focused tests; persisted drafts remain compatible.
