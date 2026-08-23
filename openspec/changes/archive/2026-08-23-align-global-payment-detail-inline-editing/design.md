## Context

Global purchase detail already renders `purchase::show` with `globalMode`, but that mode substitutes static text for the supplier purchase number, tax invoice number, and note. Global sales detail instead renders a separate reduced template, `sale::global-payments.show`, which has drifted from `sale::show` and also substitutes static tax/note content.

The existing Livewire inline editors enforce the active session `setting_id`. That is correct on normal detail routes but prevents an authorized global-payment user from editing a transaction owned by another setting. Some purchase uniqueness rules also derive their scope from the session rather than the loaded transaction. Existing global-detail specifications otherwise intentionally suppress lifecycle and attachment mutations, and payment creation must continue through the cross-business multi-transaction flows.

## Goals / Non-Goals

**Goals:**

- Present global purchase and sales details through their canonical detail templates so displayed information remains aligned.
- Enable only the inline metadata editors already present on normal detail: purchase supplier purchase number, purchase tax invoice number, purchase note, sales tax invoice number, and sales note.
- Preserve domain edit permissions, archived-record restrictions, setting-correct uniqueness validation, and server-side authorization on every Livewire action.
- Preserve global back navigation and multi-transaction payment creation based on canonical live due.

**Non-Goals:**

- Enabling full transaction editing, approval, receiving, dispatch, deletion, archive, duplication, attachment management, or payment-record mutation from global detail.
- Changing payment allocation, balance reconciliation, lifecycle eligibility, database schema, or normal setting-scoped route behavior.
- Adding a new cross-business edit permission or a generic global transaction administration workflow.

## Decisions

### 1. Reuse canonical detail templates with an explicit global context

Purchase keeps using `purchase::show`; sales is moved from its duplicate global template to `sale::show`. Both receive explicit global context and the same view data needed by the canonical template, including the transaction's actual setting and payment DataTable context.

Global branches are limited to navigation, company presentation, allowed inline-editor context, suppressed mutation controls, and the payment destination. This prevents future display drift while retaining the distinct authorization boundary.

Alternative considered: copy newly missing sections into the global sales template. Rejected because two full detail templates would continue to diverge.

### 2. Treat global inline editing as a server-authorized context

Each affected Livewire editor accepts a global context established by the rendered global route, but does not trust a client-provided boolean as sufficient authorization. On mount and every state-changing action, it reloads the transaction and authorizes one of two paths:

- normal context: existing domain edit permission plus active-setting ownership;
- global context: relevant global-payment access permission plus the existing domain edit permission.

Archived transactions remain non-editable in either context. Record lookup remains explicit enough to include eligible cross-setting records without weakening normal component behavior.

Alternative considered: temporarily switch the session setting while rendering global detail. Rejected because session mutation can leak into other tabs/requests and makes the global workspace dependent on hidden state.

### 3. Derive validation scope from the loaded transaction

Purchase supplier purchase number and tax invoice number uniqueness rules use the loaded purchase's `setting_id`. The component must not use `session('setting_id')` in global context. Save authorization and validation operate on the same reloaded transaction to avoid cross-business or tampering mismatches.

Alternative considered: make these identifiers globally unique. Rejected because existing business-scoped semantics must remain unchanged.

### 4. Keep global detail selectively editable

The canonical templates continue to suppress full edit, lifecycle, attachment-management, archive, delete, and duplication controls in global mode. Only the five named inline components become interactive when their existing domain permission is present. Users with global access but without the domain edit permission see the same values read-only.

Payment creation remains conditional on its existing create permission and positive canonical live outstanding balance. Purchase routes to the supplier allocation page with the purchase selected; sales routes to the customer allocation page with the sale selected.

Alternative considered: expose every action shown by normal detail. Rejected because those routes retain active-setting ownership assumptions and the request only covers existing inline metadata editors.

## Risks / Trade-offs

- [A forged Livewire payload could claim global context for another record] → Reauthorize the global access permission, domain edit permission, record eligibility/context, and archive state during every component action.
- [Canonical sales detail contains mutation controls not safe across settings] → Gate every non-inline control behind non-global context and add focused rendered-view assertions.
- [Business-scoped uniqueness could be checked against the wrong setting] → Build validation from the reloaded transaction's `setting_id` and test same-value behavior across different settings plus collisions within one setting.
- [Sharing the sales template could trigger missing relationships or DataTable data] → Align global controller eager loading and DataTable parameters with `SaleController::show`, then cover global rendering with focused feature tests.

## Migration Plan

No data migration is required. Deploy the view/controller/component changes together. Rollback is code-only: restore the dedicated sales global template and static global fields; persisted metadata remains valid.

## Open Questions

None. Scope is limited to the five existing inline editors, and all other global mutations remain suppressed.
