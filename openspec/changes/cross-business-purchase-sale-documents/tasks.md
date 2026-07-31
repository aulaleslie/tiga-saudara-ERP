## 1. Authorization and business-context foundation

- [ ] 1.1 Add the `documents.business.override` permission to the established permission configuration and seed/sync path without granting it by default.
- [ ] 1.2 Implement a shared effective-document-business resolver that normalizes target setting IDs, resolves user-accessible settings (including Super Admin), checks override permission, and exposes target PKP state.
- [ ] 1.3 Add focused tests for authorized, unprivileged, Super Admin, and forged/inaccessible target-business requests.

## 2. Searchable business selector and reactive context

- [ ] 2.1 Add a reusable searchable single-business selector following the existing CoreUI/Select2 conventions, including required/error behavior and accessible-business options.
- [ ] 2.2 Add selected-business state, initialization, validation, and selector rendering to Purchase CreateForm and EditForm; preserve active-business-only behavior for unprivileged users.
- [ ] 2.3 Add selected-business state, initialization, validation, and selector rendering to Sale CreateForm and EditForm; preserve active-business-only behavior for unprivileged users.
- [ ] 2.4 Pass selected business context through Purchase/Sale product search, product cart, tax, and location controls, using refreshed keys/props where required.
- [ ] 2.5 Implement target-business change handling that preserves non-tax cart values and rehydrates/removes only tax-related state and UI.

## 3. Purchase persistence and draft reassignment

- [ ] 3.1 Use the resolved effective business for Purchase create PKP lookup, scoped uniqueness validation, normalization, reference generation, and persisted `setting_id`.
- [ ] 3.2 Enforce draft-only Purchase business reassignment in the server-side update path and atomically generate a new target-business purchase reference when the setting changes.
- [ ] 3.3 Preserve existing Purchase non-draft lifecycle restrictions and update cross-business success feedback to name the target business and reference.

## 4. Sale persistence and draft reassignment

- [ ] 4.1 Use the resolved effective business for Sale create PKP lookup, cart validation/normalization, reference generation, and persisted `setting_id`.
- [ ] 4.2 Enforce draft-only Sale business reassignment in the service/update path and atomically generate a new target-business sale reference when the setting changes.
- [ ] 4.3 Preserve existing Sale non-draft lifecycle restrictions and update cross-business success feedback to name the target business and reference.

## 5. Verification

- [ ] 5.1 Add Purchase Livewire/feature coverage for selector visibility and requirement, business authorization, selected-business persistence, and unchanged active session/list redirect.
- [ ] 5.2 Add Sale Livewire/feature coverage for selector visibility and requirement, business authorization, selected-business persistence, and unchanged active session/list redirect.
- [ ] 5.3 Add PKP-to-non-PKP and non-PKP-to-PKP cart rehydration tests proving prices, quantities, discounts, and shipping are retained while tax state is correctly removed or required.
- [ ] 5.4 Add draft move and rejected-then-drafted move tests for Purchase and Sale, including target-prefix renumbering, atomic failure behavior, and blocked non-draft moves.
- [ ] 5.5 Run the focused Purchase/Sale test suites, then `composer test:fresh-sqlite` or an equivalent full verification pass and resolve regressions.
