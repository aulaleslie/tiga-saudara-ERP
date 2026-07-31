## Context

Purchase and Sale creation currently saves `session('setting_id')`, and their Livewire forms and nested product-cart, product-search, and tax controls independently read that same active setting. PKP status is already derived from the active setting: non-PKP forms suppress tax UI and remove tax data, while PKP forms expose tax UI and reject cart lines without tax selections.

The active setting must remain unchanged while a user with explicit authority prepares a Purchase or Sale for another accessible business. Existing document lists remain active-setting scoped after save. A business move is permitted only while the document is drafted; rejected documents that have returned to the drafted state follow the same rule.

## Goals / Non-Goals

**Goals:**

- Provide a permission-gated, required, searchable single-business selector on Purchase and Sale create/edit forms.
- Make the selected business the authoritative document and tax context without changing the session active business.
- Preserve all non-tax cart intent when the selected business changes and rehydrate/remove only tax context according to target PKP status.
- Allow draft-only business reassignment with a newly generated target-business document reference.
- Enforce authorization and lifecycle rules in server-side persistence paths as well as in the UI.

**Non-Goals:**

- Changing the user's active business, widening normal document lists, or adding global document lists.
- Repricing, changing quantity, discount, shipping, customer/supplier, payment term, stock, or location automatically when business changes.
- Moving approved, received, dispatched, or any other non-draft document between businesses.
- Extending the workflow to returns, POS, payments, or other document types.

## Decisions

### D1: One explicit permission governs the override

Add `documents.business.override` to the established permission configuration. Only a user with this permission receives the selector and can submit a target business different from the session business. The server resolves selectable settings from the authenticated user's assigned settings; Super Admin resolves all settings. A submitted ID outside that set, or any selected-business submission without the permission, fails authorization.

This separates permission to override document ownership from ordinary access to the active business. Trusting a browser-hidden field or the session setting alone is rejected because either can be inconsistent with the requested target.

### D2: Keep selected business as form state, never mutate the session

Each of the four Livewire forms maintains an authoritative `selectedSettingId`. On mount it starts at the active business for continuity. Authorized forms render a searchable single-select selector and validate it as required; non-authorized forms retain the active setting as the only valid effective value.

The selected ID is passed into/reloaded by product search, cart, and tax child controls. Persistence, PKP lookup, document uniqueness validation, and reference generation use the resolved effective setting. This avoids unrelated navigation or Livewire components observing a temporary session change.

### D3: Rehydrate taxation only on business change

The selected business's `is_pkp` determines the existing tax behavior. On a change:

- Target non-PKP removes cart-line tax assignments and tax-derived values, hides tax controls, and saves no tax reference/tax values.
- Target PKP reloads that setting's available taxes, retains entered non-tax line values, and requires each relevant cart line to have a valid target-setting tax assignment before submit.

Products, quantities, entered unit prices, discounts, shipping, and other non-tax intent remain unchanged. Existing normalizers remain responsible for deriving header and line tax totals from the rehydrated cart rather than preserving stale tax amounts.

An alternative of clearing/repricing the cart was rejected because the agreed workflow preserves an operator's negotiated prices and product intent.

### D4: Business reassignment is draft-only and renumbers atomically

Create operations persist the resolved target setting. Update operations may change `setting_id` only when the current document status is exactly the existing drafted status. On an actual setting change, the save transaction obtains a new unique reference using the target business's Purchase or Sale prefix and persists it with the new `setting_id`. The old reference is replaced and is retained only in normal audit/log history if such history exists; no alias lookup is introduced by this change.

The existing non-draft lifecycle restrictions remain in force. A rejected record is eligible only after the existing lifecycle has returned it to drafted. The current page redirect remains unchanged; the success message explicitly includes the target business when it differs from the active business.

### D5: Centralize effective-setting resolution and test boundaries

Use a small shared support/service concern (or equivalent existing pattern) to normalize the selected ID, resolve accessible settings, determine PKP state, and enforce draft-only reassignment. The form and service layer must both call it so direct Livewire requests cannot bypass UI gating. Reuse existing Select2/CoreUI searchable-select conventions for the field.

## Risks / Trade-offs

- [A target change leaves stale tax state in the cart] → Clear/reload tax-specific state on change based on target PKP status; validate selected tax IDs exist globally.
- [Nested controls keep their original active-setting data] → Include selected-setting identity in child-component keys/props and refresh their data when it changes.
- [A privileged user forges a setting ID] → Intersect submitted IDs with accessible settings and check the override permission in server-side save logic.
- [Renumbering conflicts under concurrent saves] → Generate and validate the new target-business reference inside the document transaction using the existing numbering/uniqueness mechanism.
- [User cannot find the saved cross-business document in the current list] → Preserve current redirect and provide an explicit success notification naming the target business and new reference.
- [Business move affects accounting or stock lifecycle] → Permit it only for drafted records and retain all existing non-draft blocks.

## Migration Plan

1. Deploy the permission definition with no existing role grants; behavior remains unchanged until administrators assign it.
2. Deploy form, child-context, persistence, reference, and validation changes together; no schema changes required.
3. Roll back application code if needed. Documents already moved retain their target setting and newly assigned reference.

## Open Questions

None. The agreed scope limits selection to businesses accessible to the user and preserves the active-business redirect.
