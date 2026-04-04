## 1. Define supported POS bundles and permission map

- [x] 1.1 Audit the current POS permission inventory and classify each permission as active, missing-but-required, grouped exception, or deprecated.
- [x] 1.2 Document the default permission bundle for `manager`, `cashier`, and `floor staff`, while keeping `owner` mapped to Super Admin bypass.
- [x] 1.3 Formalize which POS screens and actions belong to core shell access, draft handoff, checkout, oversight, administration, and exception clusters.

## 2. Align centralized permission registry and role-management surface

- [x] 2.1 Update `app/Config/Permissions.php` so all runtime-used POS permissions are represented in the centralized registry.
- [x] 2.2 Add or formalize manager-grade transaction override authority used by runtime code and role composition.
- [x] 2.3 Mark dormant or unsupported POS permissions as deprecated or remove them from the supported role-assignment surface with migration notes.
- [x] 2.4 Update permission sync/seeding paths and any role-management helpers so supported POS bundles and grouped capability guidance are visible to Super Admin.

## 3. Align runtime authorization with supported role bundles

- [x] 3.1 Update POS capability helpers, route middleware, request authorization, and service checks so shell access, draft handoff, checkout authority, and session oversight match the new role matrix.
- [x] 3.2 Ensure `floor staff` can access POS shell and save/load handoff flows but cannot begin or finalize checkout.
- [x] 3.3 Ensure `cashier` can perform handoff flows and complete checkout without inheriting manager-only oversight screens.
- [x] 3.4 Ensure `manager` can exercise explicit oversight and administrative session controls without relying on owner-style bypass.
- [x] 3.5 Align POS menu and in-shell navigation visibility with the same permission model enforced by runtime guards.

## 4. Migrate live roles and verify behavior

- [x] 4.1 Prepare a migration matrix that maps current live POS roles to `manager`, `cashier`, `floor staff`, or documented custom-exception roles.
- [x] 4.2 Update automated tests for POS role matrix, save/load handoff, staged payment, checkout finalization, session close, and menu visibility behavior.
- [x] 4.3 Add regression coverage for deprecated or mismatched permissions so registry/runtime drift is detected early.
- [x] 4.4 Validate the final role bundles with representative owner, manager, cashier, and floor-staff test fixtures before rollout.

## 5. Refine terminal-dependent checkout behavior for cashier versus manager

- [x] 5.1 Update `Modules/Pos/Services/PosRolePolicyService.php` so checkout capability flags distinguish plain `pos.checkout.payment` authority from effective payment availability based on active session terminal context and manager-grade role detection.
- [x] 5.2 Update `Modules/Pos/Http/Controllers/PosSellController.php` checkout guard paths (`ensureCheckoutPermission()`, `paymentMethodSearch()`, `stagePayment()`, `getPaymentChain()`, `resetPaymentChain()`, and `checkoutFinalize()`) so cashier is rejected when the active session has no terminal assigned, while manager and Super Admin remain allowed.
- [x] 5.3 Update `Modules/Pos/Http/Requests/StorePosCheckoutFinalizeRequest.php` and any checkout-adjacent request authorization that currently treats `pos.checkout.payment` alone as sufficient, so server-side validation matches the refined runtime gate.
- [x] 5.4 Update `Modules/Pos/Resources/views/sell.blade.php` payment CTA state and client-side cart gating so `Pilih Pembayaran` stays disabled for floor staff and for cashier sessions without a terminal, while remaining available to manager sessions without a terminal.
- [x] 5.5 Update `public/js/pos-staged-payment.js` and any inline sell-page payment-method search or staged-payment recovery logic so cashier users without a terminal never enter or recover payment flow, and the UI message explains terminal assignment is required.
- [x] 5.6 Update `Modules/Pos/Http/Controllers/PosSessionController.php`, `Modules/Pos/Resources/views/session/open.blade.php`, and the terminal picker component (`Modules/Pos/Livewire/PosTerminalSearchDropdown.php` plus its Blade view) so floor staff can open terminal-less sessions for handoff work, terminal selection is hidden or disabled for floor staff, and the open-session form copy reflects the cashier-versus-manager checkout rule.
- [x] 5.7 Review `Modules/Pos/Routes/web.php` checkout route middleware and any shared POS middleware so route-level gating does not imply that every `pos.checkout.payment` user can pay from every active session context.
- [x] 5.8 Add focused regression tests covering: cashier with terminal can pay, cashier without terminal cannot search payment methods or finalize, manager without terminal can use staged payment and finalize, floor staff can still save/load drafts without terminal, and session-open UI/flow hides terminal selection for floor staff.
