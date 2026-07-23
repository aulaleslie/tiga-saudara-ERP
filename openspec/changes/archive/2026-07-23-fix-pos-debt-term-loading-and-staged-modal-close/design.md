## Context

The debt-checkout selector is populated lazily by `GET /pos/sell/payment-terms/search`. The controller currently queries `Modules\Setting\Entities\PaymentTerm`, which Composer cannot resolve; the canonical shared model is `Modules\Purchase\Entities\PaymentTerm`. The browser receives a non-success response, but `loadPaymentTerms()` only handles the success branch and therefore leaves the selector at its placeholder without telling the cashier why debt checkout cannot continue.

The staged-payment modal exposes a header × control and a footer **Batal** control. **Batal** uses Bootstrap's declarative dismissal and works immediately. The × control instead awaits deletion of the session payment chain and then invokes `.modal('hide')` on a native DOM node, causing a JavaScript error. This can erase recoverable staged-payment state while leaving the modal open. Processing-state code also targets only the first `[data-dismiss="modal"]` element, so it does not consistently lock every exit control.

The change spans the POS controller, Blade modal contract, staged-payment JavaScript, and regression tests. It must preserve the established session-backed reload recovery and existing checkout authorization boundaries.

## Goals / Non-Goals

**Goals:**

- Return all existing payment terms to authorized POS checkout users through the current endpoint.
- Present a usable debt term selector and an actionable visible error when term loading fails.
- Make × and **Batal** consistent, non-destructive modal-dismiss actions.
- Preserve in-progress payment-chain state across ordinary dismissal and recovery.
- Provide an explicit, confirmed action when the cashier intentionally abandons and clears the payment chain.
- Lock all dismiss/reset controls while a payment request or finalization is processing.

**Non-Goals:**

- Changing payment-term schema, ownership, seeding, or regular Sales/Purchase term behavior.
- Adding POS-specific payment-term filtering or setting scoping; debt checkout continues to use all shared terms.
- Changing staged-payment posting, debt authorization, approval, allocation, or finalization rules.
- Replacing Bootstrap modal infrastructure or redesigning the broader checkout UI.

## Decisions

### 1. Use the canonical PaymentTerm model directly

The POS controller will import and query `Modules\Purchase\Entities\PaymentTerm`. This matches existing Sales, Purchase, People, and POS service usage and avoids introducing an alias or duplicate model under `Modules\Setting`.

An alias was considered because payment terms are administered from the Setting module UI, but the model's established namespace is part of the brownfield contract. Adding another model would obscure ownership and allow behavior to drift.

### 2. Keep the existing endpoint and make its client handling fail visibly

The route and JSON shape remain stable: `{ terms: [{ id, name, longevity }] }`. `loadPaymentTerms()` will explicitly treat non-2xx responses, invalid payloads, and network failures as load failures. On failure it will keep the selector unavailable, disable debt continuation through the existing validation path, and show a cashier-facing message in the staged-payment error surface. A subsequent deliberate retry of the debt toggle or modal flow may request terms again; failures will not be cached as an empty successful result.

Embedding payment terms into the initial POS page was considered, but lazy loading preserves the existing authorization boundary and avoids coupling the base sell-page payload to an option that many checkouts never use.

### 3. Separate modal dismissal from payment-chain destruction

Both × and **Batal** will use one non-destructive dismissal path backed by Bootstrap-compatible modal invocation. Neither control will call the DELETE payment-chain endpoint. Dismissing and reopening the modal—or reloading the page—will recover the same committed stages and debt selection from the session chain.

Intentional abandonment will use a separately labelled destructive control such as **Batalkan seluruh pembayaran**, with confirmation before calling the existing DELETE endpoint. After a successful reset, the client will clear its matching local staged/debt context and close the modal. A reset failure will leave the modal and local state intact and display an error.

Keeping the old “× means reset” behavior was rejected because it conflicts with standard close affordances, differs from **Batal**, and silently destroys state that the persistence capability promises to recover.

### 4. Treat modal exit controls as a group during processing

Dismiss and destructive-reset controls will share a stable selector or explicit references. Processing-state transitions will disable or hide every member of that group, and restore them when processing ends. This prevents a close/reset race while stage submission or checkout finalization is in flight.

Targeting a single `[data-dismiss="modal"]` element was rejected because it depends on DOM order and misses controls implemented through JavaScript.

### 5. Add coverage at the failure boundaries

Focused feature coverage will request the authenticated POS payment-term endpoint with persisted terms and assert its contract. Frontend-oriented coverage will verify success population, visible non-success handling, consistent dismiss behavior, processing locks, explicit reset confirmation, and session-chain preservation/reset outcomes. Existing debt checkout and reload-recovery suites remain the regression baseline.

## Risks / Trade-offs

- **[An old workflow relied on × silently clearing staged payments]** → Replace it with an explicit destructive action so the capability remains available without accidental activation.
- **[Term endpoint fails after the debt toggle is enabled]** → Fail closed: keep checkout continuation disabled, show an actionable error, and allow a later retry.
- **[Client and session state diverge after reset]** → Clear local state only after a successful DELETE response; retain state and surface an error otherwise.
- **[Modal dismissal during an active request creates duplicate or orphaned work]** → Lock all dismiss/reset controls through the existing processing state.
- **[Frontend test infrastructure may not execute Bootstrap behavior directly]** → Test the delegated handlers/state transitions where possible and retain focused browser/manual verification for actual modal dismissal.

## Migration Plan

1. Deploy the endpoint model correction and staged-modal client/markup changes together.
2. Run focused POS payment-term, debt-checkout, staged-payment, and reload-recovery tests.
3. In UAT, verify term loading against production-like data, dismiss/reopen recovery before and after a committed stage, explicit reset confirmation, and processing locks.
4. No data migration is required. Rollback restores the prior code; existing `payment_terms`, carts, and completed sales are unchanged, although the prior close/reset defects would return.

## Open Questions

None. The change adopts non-destructive ordinary dismissal and a separate confirmed reset action.
