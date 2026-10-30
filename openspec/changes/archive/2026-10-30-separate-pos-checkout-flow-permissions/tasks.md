## 1. Permission registry and policy

- [x] 1.1 Register `pos.checkout.debt.request` through existing permission synchronization and POS assignment grouping; add no automatic user, role, or bundle grants.
- [x] 1.2 Add independent Lunas, direct Kasbon, request Kasbon, and any-checkout capabilities in `PosRolePolicyService`; retain terminal rules, manager exceptions, and Super Admin conventions.
- [x] 1.3 Audit broad checkout capability consumers, including session terminal selection, so Kasbon-only users can satisfy existing checkout prerequisites.

## 2. Server authorization

- [x] 2.1 Update checkout FormRequest, controller guards, and relevant shared payment endpoints to distinguish any-checkout access from selected-flow access; include payment method/evidence endpoints where applicable.
- [x] 2.2 Resolve validated request and staged-chain flow consistently, including legacy indicators; authorize effective merged flow before finalization mutation/posting and deny contradictory-state bypasses.
- [x] 2.3 Require direct or request debt permission at debt approval request creation and execution/token consumption. Preserve existing supervisor/token checks and unrelated approval actions; permit request-only preparation before approval.
- [x] 2.4 Block continuation/completion of inaccessible staged flows with a clear authorization outcome while preserving their data.

## 3. POS interface

- [x] 3.1 Render Bayar Lunas using payment permission and Kasbon using either debt permission; display the approval-required label only for request-only users.
- [x] 3.2 Feed explicit flow capabilities into client initialization, payment action enabling, chain restoration, and staged modal handling.
- [x] 3.3 Show the flow switch only for access to both flows and preserve existing dirty-state confirmation behavior.

## 4. Focused verification and administrator guidance

- [x] 4.1 Add/update focused permission and role tests for payment-only, direct-debt-only, request-debt-only, combined, neither, and Super Admin access, retaining operational validation.
- [x] 4.2 Add/update focused debt approval tests for request creation, valid/missing approval, direct precedence, neither-permission denial despite a token, and revoked permission before completion.
- [x] 4.3 Add/update focused staged checkout tests for shared endpoint access, unauthorized flow mutations, request/session mismatch or omission, legacy flow resolution, and preserved data after access loss.
- [x] 4.4 Update/run affected client state-machine checks and targeted UI checks for labels, switching, initialization, and resume behavior.
- [x] 4.5 Run only affected PHPUnit filters/files and relevant JavaScript fixture checks; record results. No full-suite test run is required.
- [x] 4.6 Document explicit administrator assignment of the new permission and the removal of payment-only implicit Kasbon approval access.
