# Tasks

## 1. Unified permission assignment

- [x] 1.1 Register `pos.sessions.viewExpectedCash` / `Lihat Ekspektasi Kas` in the POS group and explicit-exception matrix cluster; extend permission helper/matrix tests to verify unified-form availability and absence from manager, cashier, and floor-staff default bundles.
- [x] 1.2 Verify permission synchronization in isolated tests: Admin receives the new permission, ordinary roles retain their assignments without automatic grants, Super Admin bypass works, and revocation changes subsequent disclosure. Document explicit assignment and existing Admin sync behavior in POS documentation.

## 2. Server-enforced visibility

- [x] 2.1 Add a shared permission-aware response/view projection retaining complete internal accounting; cover expected/cached/remaining balances, variance, revealing cash components, threshold disclosures, nested session serialization, and event metadata. Verify focused projection/endpoint tests with and without permission.
- [x] 2.2 Apply projection and conditional rendering to sell/nav, session lists/details, summary HTML/JSON, close/finalize responses, and POS report/reconciliation output including any supported exports; extend summary/reconciliation tests to assert protected values are absent from actual payloads and markup while authorized viewers retain signed values.
- [x] 2.3 Verify session ownership, supervisor authority, and existing report access do not imply visibility, and visibility alone cannot bypass business/session authorization; add focused access-denial and disclosure tests and document the protected POS surfaces.

## 3. Signed pickup balances

- [x] 3.1 Remove expected-cash maximum checks in pickup controller and safe-drop service while retaining positive-amount, denomination, session, business, transaction-locking, and approval guards; extend OTP pickup and safe-drop workflow tests for Rp950000 minus Rp1000000 yielding -Rp50000 and pickups from zero/already-negative balances, for actors with and without visibility.
- [x] 3.2 Verify ledger/cache signed persistence and recalculation after pickup without a zero floor, with one outflow per successful request and no pickup outflow on invalid amount/OTP/session; keep existing change and Kas Bon ledger regressions passing in focused tests.
- [x] 3.3 Update pickup JavaScript/modal: authorized live fetch and retry remain, hidden balance is not a fetch dependency, positive amount enables continuation without a balance cap, and success responses/toasts obey visibility. Verify both permission branches in a browser smoke check, including negative authorized balance formatting and no new overdraft confirmation. Document that expected cash is informational rather than a pickup limit.

## 4. Blind counting and finalization

- [x] 4.0 Repair the pre-existing structural headers in `openspec/specs/pos-supervisor-cash-finalization/spec.md` (currently a main spec begins with a delta-only MODIFIED header), preserving its requirement and scenarios; verify OpenSpec can parse the main spec and no archive-preflight structural warning remains.

- [x] 4.1 Update count/close/finalization presentation and scripts so otherwise authorized users can enter actual cash without expected cash or numeric variance; extend focused finalization tests for within-threshold success and approval-required responses without disclosure, retaining full internal audit metadata.
- [x] 4.2 Preserve existing variance approval authority, finalization state guards, and supervisor OTP semantics; verify invalid/missing approvals still reject, an approver's permission does not reveal balances to the requester, and authorized viewers retain the detailed finalization display. Document blind counting behavior and browser-smoke the approval transition.

## 5. Focused integration verification

- [x] 5.1 Run the relevant permission helper/matrix, OTP pickup, safe-drop, summary, reconciliation, and finalization test selection with the configured SQLite test environment; record exact commands and results, broadening only if failures reveal additional affected dependencies.
- [x] 5.2 Verify the integrated example end to end: expected Rp950000, approved pickup Rp1000000, persisted/recalculated -Rp50000; authorized browser sees the signed balance, unauthorized browser sees no protected values in HTML/network responses, and both retain permitted operations. Record browser-check results or any tooling limitation.
