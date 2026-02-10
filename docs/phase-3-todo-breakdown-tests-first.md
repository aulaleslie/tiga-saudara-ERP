# Phase 3 — TODO Breakdown (Tests-First)

Date: 2026-02-08  
Based on: `docs/phase-2-final-requirements-frozen.md`

Execution policy:
1. Every TODO must start with tests.
2. Do not implement production code before related tests are written.
3. Run targeted tests after each TODO, then run broader regression before completion.

## Milestone 1 — Validation Contract And Receive Entry Rules

### TODO 1 — Serial Validate Endpoint: Returned-Reuse + Return-Process Blocking
**Goal:**  
Define and enforce validation contract for `POST /serial-numbers/validate` so returned serials are reusable, return-process serials are blocked, and checks are scoped by product.

**Related requirements:**  
FR-001, FR-002, FR-003, FR-005, FR-009

**Impacted paths:**  
`Modules/Product/Http/Controllers/SerialNumberController.php`  
`Modules/Product/Routes/web.php`  
`tests/Feature/PurchaseSerialValidateReturnedReuseTest.php`

**Test cases (Given / When / Then):**
- Given a serial with status `RETURNED` for the same product, when `/serial-numbers/validate` is called, then response is `valid=true` and contains optional `info_message`.
- Given a serial with status `RETURN_IN_PROCESS` or `is_in_return_process=true`, when validate is called, then response is `valid=false` with explicit return-process message.
- Given an `ACTIVE` serial for the same product, when validate is called, then response is `valid=false` with duplicate/not-available message.
- Given same serial text on a different product, when validate is called for current product, then response is `valid=true`.
- Given serial existing only in another pending receiving, when validate is called, then response does not fail due to pending-receiving message.

**Unit test plan:**
- Test file name: `tests/Feature/PurchaseSerialValidateReturnedReuseTest.php`
- What to mock: none (use DB fixtures + authenticated test user/session)
- Assertions: HTTP 200 payload contract, `valid` flag, explicit message text, optional `info_message` presence for reusable returned serial
- Edge cases: lowercase/mixed-case status values, null `purchase_return_id`, null `tax_id`

**Integration / E2E tests (if applicable):**
- Validate endpoint behavior through the purchase receive UI scan flow by asserting response handling contract.

**Implementation outline (NO CODE):**
- Write endpoint feature tests first for reusable, blocked, and scoped scenarios.
- Update status evaluation logic in validation endpoint to match frozen rules.
- Remove cross-pending receiving duplicate rejection from endpoint path.
- Re-run tests and confirm contract stability.

**Definition of Done:**
- All tests in `PurchaseSerialValidateReturnedReuseTest` pass.
- Endpoint no longer emits pending-receiving duplicate message.
- Returned serials are accepted with non-error informational contract.

### TODO 2 — Store Receive Validation: Product-Scoped Duplicate Rules
**Goal:**  
Align `POST /purchases/{purchase}/receive` duplicate policy with product-scoped uniqueness and no cross-pending validation.

**Related requirements:**  
FR-003, FR-004, FR-005

**Impacted paths:**  
`Modules/Purchase/Http/Controllers/PurchaseController.php`  
`Modules/Purchase/Routes/web.php`  
`tests/Feature/PurchaseStoreReceiveSerialPolicyTest.php`

**Test cases (Given / When / Then):**
- Given duplicate serial in the same receive submission for the same product, when store receive is submitted, then request is rejected with duplicate error.
- Given returned serial already existing for same product, when store receive is submitted, then request succeeds and creates pending receiving document.
- Given same serial text existing on different product, when store receive for current product is submitted, then request succeeds.
- Given serial appears in another pending receiving document, when store receive is submitted, then request is not rejected for that reason.

**Unit test plan:**
- Test file name: `tests/Feature/PurchaseStoreReceiveSerialPolicyTest.php`
- What to mock: none
- Assertions: redirect/session errors for invalid duplicates; pending receive row creation for valid cases
- Edge cases: multiple product rows in one request, serialized and non-serialized product mix

**Integration / E2E tests (if applicable):**
- Submit receive form end-to-end with mixed rows and verify user-facing validation results.

**Implementation outline (NO CODE):**
- Write store-receive feature tests first.
- Refactor duplicate extraction/checking to evaluate `(product_id, serial_number)` pairs.
- Remove cross-document pending duplicate blocking logic.
- Verify no regression for same-form duplicate detection.

**Definition of Done:**
- Product-scoped duplicate behavior is enforced.
- Cross-pending duplicate error is fully removed.
- Existing receive submission flow remains functional for non-serial items.

### TODO 3 — Receive UI Feedback For Reused Returned Serials
**Goal:**  
Display informational feedback (not error) when user scans a returned serial that is accepted for reuse.

**Related requirements:**  
FR-009

**Impacted paths:**  
`Modules/Purchase/Resources/views/receive.blade.php`  
`tests/Feature/PurchaseReceiveSerialInfoMessageTest.php`

**Test cases (Given / When / Then):**
- Given validate endpoint returns `valid=true` with `info_message`, when serial is added, then UI shows info feedback and serial pill is added.
- Given validate endpoint returns `valid=false`, when serial is added, then UI shows error and does not add pill.
- Given validate endpoint returns `valid=true` without `info_message`, when serial is added, then serial is added without info banner.

**Unit test plan:**
- Test file name: `tests/Feature/PurchaseReceiveSerialInfoMessageTest.php`
- What to mock: endpoint response payload contract where needed
- Assertions: view/JS contract presence for rendering info state and preserving error behavior
- Edge cases: repeated scans, clearing old messages between scans

**Integration / E2E tests (if applicable):**
- Browser-level scenario: scan returned serial, observe info notification, submit receive successfully.

**Implementation outline (NO CODE):**
- Add tests for response contract consumption first.
- Update receive page JS message handling to support non-error info message path.
- Keep existing error handling unchanged.

**Definition of Done:**
- Reused returned serial shows informational feedback.
- Error path remains unchanged and reliable.

## Milestone 2 — Approval Reactivation And Concurrency Safety

### TODO 4 — Approve Receiving: Reactivate Existing Returned Serial Row
**Goal:**  
On receiving approval, reuse existing returned serial row instead of inserting duplicate, and apply required field updates + history record.

**Related requirements:**  
FR-006, FR-007, FR-008, FR-012

**Impacted paths:**  
`Modules/Purchase/Http/Controllers/PurchaseController.php`  
`app/Services/SerialNumberHistoryService.php`  
`tests/Feature/PurchaseApproveReactivatesReturnedSerialTest.php`

**Test cases (Given / When / Then):**
- Given pending receiving includes serial currently `RETURNED`, when approval runs, then existing row is updated to active state with required fields and row count does not increase.
- Given reactivation success, when approval completes, then one new `RECEIVED` history row is appended.
- Given existing serial is already non-reusable at approval time (first-winner already consumed), when approval runs, then approval fails without partial stock/serial mutation.
- Given reactivated serial, when checking fields, then `status`, `is_in_return_process`, `purchase_return_id`, `received_note_detail_id`, `location_id`, and `tax_id` match frozen requirements.

**Unit test plan:**
- Test file name: `tests/Feature/PurchaseApproveReactivatesReturnedSerialTest.php`
- What to mock: none
- Assertions: serial row identity unchanged, field mutation correctness, history event insertion, failure rollback guarantees
- Edge cases: null tax line, location change during reactivation

**Integration / E2E tests (if applicable):**
- Approver flow from pending receiving list to approval and post-approval serial state validation.

**Implementation outline (NO CODE):**
- Write approval feature tests first.
- Introduce approval-time serial decision branch: update existing reusable row vs create new row.
- Ensure transactional rollback on non-reusable conflicts.
- Verify history event behavior for reactivated serial path.

**Definition of Done:**
- Returned serial approval path updates existing row only.
- Required fields and history are correct.
- Conflict path is deterministic and rollback-safe.

### TODO 5 — Concurrency Guard For Approving Same Purchase
**Goal:**  
Prevent concurrent/double approval on same purchase and return immediate HTTP 409 for conflicting attempt.

**Related requirements:**  
FR-010, FR-011, FR-012

**Impacted paths:**  
`Modules/Purchase/Http/Controllers/PurchaseController.php`  
`tests/Feature/PurchaseApproveConcurrencyConflictTest.php`

**Test cases (Given / When / Then):**
- Given one approval process holds purchase-level approval lock, when another approval request for same purchase arrives, then second request returns HTTP 409 immediately.
- Given lock conflict, when response is returned, then conflict reason is explicit and user-actionable.
- Given conflict occurs, when DB is inspected, then no partial mutation is applied by losing request.

**Unit test plan:**
- Test file name: `tests/Feature/PurchaseApproveConcurrencyConflictTest.php`
- What to mock: lock acquisition failure path (if lock provider abstraction is used)
- Assertions: HTTP status 409, stable conflict payload/message, no side effects
- Edge cases: AJAX request vs standard web submit flow behavior

**Integration / E2E tests (if applicable):**
- Two-session approval attempt against same purchase showing immediate conflict for loser request.

**Implementation outline (NO CODE):**
- Write conflict tests first.
- Add purchase-scoped concurrency guard around approval critical section.
- Normalize conflict response to HTTP 409 for concurrent/processing conflict scenarios.

**Definition of Done:**
- Concurrent second approval cannot proceed.
- Conflict is immediate, explicit, and side-effect free.

### TODO 6 — First-Approved-Wins For Same Serial Across Multiple Pending Docs
**Goal:**  
Guarantee deterministic winner/loser behavior when same reusable serial is queued in multiple pending receiving docs.

**Related requirements:**  
FR-012

**Impacted paths:**  
`Modules/Purchase/Http/Controllers/PurchaseController.php`  
`tests/Feature/PurchaseApproveFirstWinsSerialConflictTest.php`

**Test cases (Given / When / Then):**
- Given serial is included in two pending receivings, when first approval succeeds, then second approval fails with deterministic conflict.
- Given second approval fails, when checking data, then first approval changes remain intact and second applies no partial stock/serial updates.
- Given failure, when user retries without correction, then it continues to fail consistently.

**Unit test plan:**
- Test file name: `tests/Feature/PurchaseApproveFirstWinsSerialConflictTest.php`
- What to mock: none
- Assertions: winner succeeds once, loser fails, idempotent failure behavior, no double-count stock effects
- Edge cases: same purchase vs different purchases under same setting

**Integration / E2E tests (if applicable):**
- Manual two-document approval runbook proving first-winner semantics.

**Implementation outline (NO CODE):**
- Add tests for multi-pending same-serial scenario first.
- Add approval-time recheck that validates serial still reusable just before mutation.
- Ensure loser path produces clean conflict and rollback.

**Definition of Done:**
- First-approved-wins behavior is deterministic.
- No race-dependent double success remains.

## Milestone 3 — Purchase Show History-First Returned Visibility

### TODO 7 — History-First Returned Mapping In Purchase Show
**Goal:**  
Render returned/red serial state for old purchase from serial history, independent of current direct foreign-key ownership.

**Related requirements:**  
FR-013, FR-014, FR-015

**Impacted paths:**  
`Modules/Purchase/Http/Controllers/PurchaseController.php`  
`Modules/Product/Entities/SerialNumberHistory.php`  
`tests/Feature/PurchaseShowHistoryFirstReturnedSerialTest.php`

**Test cases (Given / When / Then):**
- Given serial received in purchase A, returned, then reused in purchase B, when viewing purchase A, then serial still appears as returned (red) by history mapping.
- Given serial currently active from new purchase B, when viewing purchase B, then serial appears as normal active in B while A remains red.
- Given no return event after received event, when viewing purchase, then serial is not marked returned.

**Unit test plan:**
- Test file name: `tests/Feature/PurchaseShowHistoryFirstReturnedSerialTest.php`
- What to mock: none
- Assertions: controller-provided dataset includes returned serial map based on history event ordering
- Edge cases: multiple return cycles for same serial, multiple received events across purchases

**Integration / E2E tests (if applicable):**
- Purchase detail page verification for old/new purchase serial color states after reuse lifecycle.

**Implementation outline (NO CODE):**
- Write show-page lifecycle tests first.
- Shift returned-serial determination to history-first logic keyed by purchase receive detail references.
- Keep direct relation data as compatibility fallback only where needed.

**Definition of Done:**
- Old purchase keeps returned/red visibility after serial reuse.
- Purchase show behavior is isolated to in-scope page and remains performant.

### TODO 8 — Receiving Details UI Rendering: Stable Red/Active Visual States
**Goal:**  
Ensure receiving details partial shows consistent red vs active badges with no duplication after history-first mapping.

**Related requirements:**  
FR-014, FR-015

**Impacted paths:**  
`Modules/Purchase/Resources/views/receivings/receiving-details.blade.php`  
`Modules/Purchase/Resources/views/show.blade.php`  
`tests/Feature/PurchaseShowReturnedSerialVisibilityTest.php`  
`tests/Feature/PurchaseShowReusedSerialColorStateTest.php`

**Test cases (Given / When / Then):**
- Given returned serial for old purchase detail, when rendering receiving-details partial, then badge is red.
- Given active serial for current purchase detail, when rendering partial, then badge is active/info color.
- Given history-first + direct relation overlap, when rendering partial, then serial is not duplicated visually.

**Unit test plan:**
- Test file name: `tests/Feature/PurchaseShowReusedSerialColorStateTest.php`
- What to mock: none
- Assertions: response contains expected badge classes and serial text in correct context
- Edge cases: mixed active and returned serials in same detail block

**Integration / E2E tests (if applicable):**
- Expand/collapse receiving detail rows and confirm stable badge rendering after lifecycle transitions.

**Implementation outline (NO CODE):**
- Add rendering assertions first.
- Update view data composition and partial rendering conditions to avoid duplicate visual entries.
- Keep red indicator rule tied to history-first returned status.

**Definition of Done:**
- Red/active rendering matches lifecycle truth.
- No duplicate badge artifacts in purchase show details.

## Milestone 4 — Regression Closure

### TODO 9 — Regression Execution Plan And Exit Criteria
**Goal:**  
Run and pass targeted regression for the affected receiving/approval/show areas before release.

**Related requirements:**  
FR-001 through FR-015

**Impacted paths:**  
`tests/Feature/*` (new and updated tests in milestones above)

**Test cases (Given / When / Then):**
- Given all new serial-receive lifecycle tests, when executed, then all pass.
- Given existing related regression tests, when executed, then no behavioral regression is introduced.
- Given concurrency and history-first scenarios, when executed repeatedly, then outcomes are deterministic.

**Unit test plan:**
- Test file name: aggregated run of:
- `tests/Feature/PurchaseSerialValidateReturnedReuseTest.php`
- `tests/Feature/PurchaseStoreReceiveSerialPolicyTest.php`
- `tests/Feature/PurchaseReceiveSerialInfoMessageTest.php`
- `tests/Feature/PurchaseApproveReactivatesReturnedSerialTest.php`
- `tests/Feature/PurchaseApproveConcurrencyConflictTest.php`
- `tests/Feature/PurchaseApproveFirstWinsSerialConflictTest.php`
- `tests/Feature/PurchaseShowHistoryFirstReturnedSerialTest.php`
- `tests/Feature/PurchaseShowReusedSerialColorStateTest.php`
- What to mock: none unless lock abstraction requires deterministic failure simulation
- Assertions: end-to-end requirement coverage and no regression in existing purchase serial visibility tests
- Edge cases: mixed status casing, null tax, repeated approvals/retries

**Integration / E2E tests (if applicable):**
- Manual runbook:
- create returned serial scenario
- re-receive via purchase UI
- approve twice from concurrent contexts
- verify old purchase red visibility and new purchase active visibility

**Implementation outline (NO CODE):**
- Finalize all tests-first TODOs.
- Run targeted suite and then broader related suite.
- Record pass/fail matrix mapped to FR IDs.

**Definition of Done:**
- All targeted tests pass reliably.
- Acceptance criteria from frozen requirements are fully satisfied.
- No out-of-scope behavior was changed.

