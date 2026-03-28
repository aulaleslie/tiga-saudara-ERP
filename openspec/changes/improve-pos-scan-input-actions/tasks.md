## 1. Scan Area Layout and Controls

- [ ] 1.1 Refactor POS scan section markup in `Modules/Pos/Resources/views/sell.blade.php` to use an input + action-rail structure.
- [ ] 1.2 Add explicit helper scan action control with primary visual priority and preserve existing "Cari Produk" action as secondary.
- [ ] 1.3 Reserve a stable future camera-action slot in the action rail (placeholder/disabled presentation only, no camera behavior).
- [ ] 1.4 Update scan-area CSS for tidy professional spacing, hierarchy, and supported tablet landscape responsiveness.

## 2. Scan Trigger Behavior Parity

- [ ] 2.1 Extract or reuse one shared scan resolver function so Enter and helper button call the same execution path.
- [ ] 2.2 Wire helper action click to the shared resolver and preserve current Enter-trigger behavior for scanner workflows.
- [ ] 2.3 Enforce empty-input guard parity so both triggers block resolver call and show the same guidance message.
- [ ] 2.4 Verify status-feedback parity for success, not-found, and error states across Enter and helper-button triggers.

## 3. Validation and Regression Coverage

- [ ] 3.1 Add or update POS shell behavior tests to cover helper-button trigger and Enter/helper parity semantics.
- [ ] 3.2 Add or update UI assertions for action-rail visibility, control priority presence, and reserved camera-slot placement contract.
- [ ] 3.3 Manually validate supported tablet landscape flow: trigger helper scan, trigger Enter scan, and confirm status visibility/readability.
- [ ] 3.4 Confirm no backend API contract changes and no regression in existing `/pos/sell/search/resolve` usage.
