# Terminal POS Phase Agent Prompts (With Test Guardrails)

## Purpose
Use these prompts when assigning implementation of each phase in:
`docs/pos/terminal-pos-clarity-implementation-plan.md`

Each prompt enforces:
- baseline test capture before changes,
- post-change verification,
- failure triage so obsolete assertions are updated (not treated as regressions),
- no phase completion with unresolved relevant regressions.

## Shared Triage Contract
Agent must classify every failing test as one of:
- `REGRESSION`: intended behavior broken by new change. Must fix code.
- `EXPECTED_CHANGE`: test asserts old behavior that was intentionally replaced by approved phase scope. Must update test with replacement coverage.
- `UNRELATED_PRE_EXISTING`: failed in baseline before implementation. Must report with baseline evidence.

`EXPECTED_CHANGE` is allowed only when:
- the old assertion directly conflicts with approved phase requirements,
- replacement assertions validate the new behavior,
- coverage is preserved (no silent test deletion).

---

## Prompt: Phase 1 (Clarity UX and Runtime Visibility)

```text
You are implementing Phase 1 (Clarity UX and Runtime Visibility) from:
docs/pos/terminal-pos-clarity-implementation-plan.md

Scope lock:
- Rename ambiguous policy labels on /pos/terminals.
- Add runtime occupancy signal ("Sesi Berjalan").
- Add drilldown from terminal row to filtered session index.
- Do not implement Phase 2+ concerns in this task.

Quality gate:
This phase is not complete until relevant tests are green or triaged with evidence.

1) Capture baseline BEFORE code changes.
Run:
- php artisan test Modules/Pos/Tests/Feature/POSTerminalRegistryPolicyTest.php
- php artisan test Modules/Pos/Tests/Feature/POSSessionIndexTest.php
- php artisan test Modules/Pos/Tests/Feature/POSPermissionRoleMappingTest.php

2) Implement only Phase 1 scope.

3) Verify AFTER changes.
Re-run all baseline commands, then run:
- php artisan test --testsuite=Pos --filter=Terminal
- php artisan test --testsuite=Pos --filter=SessionIndex

4) Mandatory failure triage.
Classify each failing test:
- REGRESSION
- EXPECTED_CHANGE
- UNRELATED_PRE_EXISTING

Expected EXPECTED_CHANGE candidates for this phase:
- assertions relying on old terminal label text like "Sesi: Aktif/Nonaktif".
- assertions that assume no runtime occupancy column/drilldown element.

Rules:
- Do not remove tests without replacement assertions.
- If assertion text changed, update assertions to new wording and behavior.

5) Final report format:
- Phase implemented
- Files changed
- Test commands and results
- Triage table: test | classification | action | reason
- Final status:
  - "No known broken relevant tests", or
  - "Blocked by unrelated pre-existing failures: <list>"
```

---

## Prompt: Phase 2 (Settings Scope and Location Source Clarity)

```text
You are implementing Phase 2 (Settings Scope and Location Source Clarity) from:
docs/pos/terminal-pos-clarity-implementation-plan.md

Scope lock:
- Keep terminal management in current POS area.
- Enforce strict setting scope behavior.
- Align route/menu/header behavior.
- Add source-location clarity panel/link to sales-location-configurations.
- Replace hard-abort UX for missing sale-location config with actionable flow.
- Do not implement Phase 3+ policy-contract refactor in this task.

Quality gate:
This phase is not complete until relevant tests are green or triaged with evidence.

1) Capture baseline BEFORE code changes.
Run:
- php artisan test Modules/Pos/Tests/Feature/POSRouteFeatureFlagTest.php
- php artisan test Modules/Pos/Tests/Feature/POSPermissionRoleMappingTest.php
- php artisan test Modules/Pos/Tests/Feature/POSNavigationMenuVisibilityTest.php
- php artisan test Modules/Pos/Tests/Feature/POSOpeningFloatCaptureTest.php

2) Implement only Phase 2 scope.

3) Verify AFTER changes.
Re-run all baseline commands, then run:
- php artisan test --testsuite=Pos --filter=FeatureFlag
- php artisan test --testsuite=Pos --filter=Navigation

4) Mandatory failure triage.
Classify each failing test:
- REGRESSION
- EXPECTED_CHANGE
- UNRELATED_PRE_EXISTING

Expected EXPECTED_CHANGE candidates for this phase:
- assertions expecting prior menu/header behavior when pos_enabled changes.
- assertions expecting old hard-abort response instead of actionable redirect/error handling.

Rules:
- Preserve or improve access-control coverage.
- Do not widen access scope across settings.

5) Final report format:
- Phase implemented
- Files changed
- Test commands and results
- Triage table: test | classification | action | reason
- Final status:
  - "No known broken relevant tests", or
  - "Blocked by unrelated pre-existing failures: <list>"
```

---

## Prompt: Phase 3 (Policy Contract Enforcement)

```text
You are implementing Phase 3 (Policy Contract Enforcement) from:
docs/pos/terminal-pos-clarity-implementation-plan.md

Scope lock:
- Remove require_session_open toggle from UI; enforce global mandatory session-open.
- Enforce require_opening_float faithfully by policy.
- Enforce one active session per terminal (setting-scoped).
- Do not perform broad unrelated refactors.

Quality gate:
This phase is not complete until relevant tests are green or triaged with evidence.

1) Capture baseline BEFORE code changes.
Run:
- php artisan test Modules/Pos/Tests/Feature/POSSessionLifecycleTest.php
- php artisan test Modules/Pos/Tests/Feature/POSShellSessionGuardTest.php
- php artisan test Modules/Pos/Tests/Feature/POSOpeningFloatCaptureTest.php
- php artisan test Modules/Pos/Tests/Feature/POSTerminalRegistryPolicyTest.php
- php artisan test Modules/Pos/Tests/Feature/POSSessionIndexTest.php

2) Implement only Phase 3 scope.

3) Verify AFTER changes.
Re-run all baseline commands, then run:
- php artisan test --testsuite=Pos --filter=Session
- php artisan test --testsuite=Pos --filter=OpeningFloat

4) Mandatory failure triage.
Classify each failing test:
- REGRESSION
- EXPECTED_CHANGE
- UNRELATED_PRE_EXISTING

Expected EXPECTED_CHANGE candidates for this phase:
- tests expecting `require_session_open` toggle to remain visible/configurable.
- tests expecting opening float always required regardless of policy value.
- tests assuming previous cashier+terminal concurrency instead of terminal-level concurrency.

Rules:
- If behavior contract changes, update tests to assert new approved contract.
- Keep concurrency protections covered by both service and route/UX behavior tests.

5) Final report format:
- Phase implemented
- Files changed
- Test commands and results
- Triage table: test | classification | action | reason
- Final status:
  - "No known broken relevant tests", or
  - "Blocked by unrelated pre-existing failures: <list>"
```

---

## Prompt: Phase 4 (Debt Cleanup and Regression Hardening)

```text
You are implementing Phase 4 (Debt Cleanup and Regression Hardening) from:
docs/pos/terminal-pos-clarity-implementation-plan.md

Scope lock:
- Clean up legacy terminal-location fallback ambiguity.
- Harden regression coverage for terminal/session/scope/policy behavior.
- Do not introduce new product scope.

Quality gate:
This phase is not complete until relevant tests are green or triaged with evidence.

1) Capture baseline BEFORE code changes.
Run:
- php artisan test --testsuite=Pos

2) Implement only Phase 4 scope.

3) Verify AFTER changes.
Run:
- php artisan test --testsuite=Pos
- php artisan test Modules/Setting/Tests/Feature/SaleLocationConfigurationTest.php

4) Mandatory failure triage.
Classify each failing test:
- REGRESSION
- EXPECTED_CHANGE
- UNRELATED_PRE_EXISTING

Expected EXPECTED_CHANGE candidates for this phase:
- tests asserting obsolete fallback behavior tied to terminal location field/model behavior.

Rules:
- Do not delete legacy tests without replacing them with explicit new contract coverage.
- If sqlite/prod behavior differs, document and add targeted safeguards/tests.

5) Final report format:
- Phase implemented
- Files changed
- Test commands and results
- Triage table: test | classification | action | reason
- Final status:
  - "No known broken relevant tests", or
  - "Blocked by unrelated pre-existing failures: <list>"
```

---

## Prompt: Phase 5 (UAT and Controlled Rollout Readiness)

```text
You are executing Phase 5 (UAT and Controlled Rollout Readiness) from:
docs/pos/terminal-pos-clarity-implementation-plan.md

Scope lock:
- Validate UAT readiness and operational semantics.
- No new implementation scope unless required to fix discovered regressions.

Quality gate:
This phase is not complete until test confidence and UAT evidence are documented.

1) Capture verification baseline.
Run:
- php artisan test --testsuite=Pos

2) Execute UAT checklist with evidence:
- terminal active/inactive semantics understood by operator
- session active semantics understood by operator
- missing sale-location configuration flow is actionable
- setting-scope behavior validated with multi-setting user

3) If code fixes are required from UAT findings:
- make minimal fix
- re-run at least impacted tests plus:
  - php artisan test --testsuite=Pos

4) Mandatory failure triage (if failures occur).
Classify each failing test:
- REGRESSION
- EXPECTED_CHANGE
- UNRELATED_PRE_EXISTING

5) Final report format:
- UAT scenarios executed and result
- Files changed (if any)
- Test commands and results
- Triage table (if failures)
- Rollout recommendation:
  - Ready
  - Ready with known unrelated issues
  - Not ready
```

