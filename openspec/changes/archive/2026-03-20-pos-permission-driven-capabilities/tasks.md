## 1. Permission Registry & Role Mapping

- [x] 1.1 Add `pos.checkout.payment`, `pos.sessions.require-terminal`, and any missing runtime-used cart permissions to `app/Config/Permissions.php`
- [x] 1.2 Update the permission seeding/sync path so new POS permissions are created in existing installs
- [x] 1.3 Define and verify the intended live-role permission bundles for helper, cashier, and manager POS roles

## 2. Permission-Driven Capability Helpers

- [x] 2.1 Refactor `PosRolePolicyService` so terminal requirement and checkout authority derive only from explicit permissions
- [x] 2.2 Remove role-name-based authorization branches from `PosCartActionAuthorizationService`
- [x] 2.3 Update capability-flag payloads consumed by POS controllers and Blade views to remain permission-driven

## 3. Session Opening Flow

- [x] 3.1 Update session-open request/service logic so terminal selection is required only for users with `pos.sessions.require-terminal`
- [x] 3.2 Update the session-open UI to show and require terminal/opening-float fields from the terminal-required capability instead of `pos.sell`
- [x] 3.3 Verify non-terminal session reuse and active-session conflict handling still behave correctly after the permission refactor

## 4. POS Shell, Draft Save, and Checkout Gating

- [x] 4.1 Keep POS shell/cart access behind `pos.sell` while moving payment-only endpoints behind `pos.checkout.payment`
- [x] 4.2 Update staged-payment, payment-chain, and checkout-finalize controller paths to reject missing checkout permission consistently
- [x] 4.3 Ensure `Simpan dan Buka Baru` remains available to users with `pos.sell` + `pos.transactions.save` even when `pos.checkout.payment` is absent

## 5. POS UI Feedback

- [x] 5.1 Update POS sell button states and status messaging so payment actions are hidden or disabled without `pos.checkout.payment`
- [x] 5.2 Align payment-method search/modal entry points with the new backend permission gates
- [x] 5.3 Review post-session-open redirects and navigation so permission bundles land users in the intended POS flow

## 6. Regression Coverage & Verification

- [x] 6.1 Rewrite POS role-matrix and session-opening tests around permission combinations instead of role names
- [x] 6.2 Add feature coverage for the helper bundle: open without terminal, enter POS shell, save draft, cannot stage/finalize payment
- [x] 6.3 Add feature coverage for the cashier bundle: terminal required, staged payment allowed, finalize allowed
- [x] 6.4 Add coverage that explicit `pos.overrides.price` bypasses approval regardless of role name
- [x] 6.5 Verify the deployment/backfill checklist against a live-style role assignment before rollout
