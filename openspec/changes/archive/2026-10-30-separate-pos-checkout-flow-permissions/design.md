## Context

`PosRolePolicyService::hasCheckoutAuthority()` currently checks only `pos.checkout.payment`. This propagates into capability flags, terminal selection, client initialization, and shared API access. `payment.blade.php` additionally requires debt permission for Kasbon. `PosSellController::ensureCheckoutPermission()` and `StorePosCheckoutFinalizeRequest` independently require payment permission. The staged modal switch checks only debt permission. Finalization merges request and session payment state before invoking the debt action authorization service, which currently falls back to approval whenever direct debt permission is absent.

## Goals / Non-Goals

Goals: independent Lunas/Kasbon access; explicit approval-request access; consistent UI and server checks; preservation of staged data and operational validation.

Non-goals: rewriting payments or approval tokens, changing supervisor eligibility, granting permissions automatically, redesigning role bundles, changing unrelated POS actions, or requiring full-suite verification.

## Decisions

### Separate shared authority from flow authority

Use one policy source for Lunas access, direct Kasbon access, request-only Kasbon access, and any checkout access. Shared checkout authority is the OR of all three permissions. Effective Kasbon access is direct OR request; direct wins when both are present. Preserve the existing Super Admin bypass through established authorization conventions.

Expose explicit flow capabilities to Blade and JavaScript. Audit consumers of broad checkout flags, including terminal selection, payment-method lookup, payment evidence handling, chain loading, reconciliation, reset, staging, preflight, and finalization. Broader shared access must never imply authorization for both flows. Preserve terminal requirements and existing manager exception criteria.

### Enforce effective flow at mutation and completion boundaries

Resolve flow consistently from validated request and authoritative staged-chain state, retaining legacy `is_debt` compatibility. Authorize the effective flow after session payload merging and before business mutation or posting. Contradictory request/session state must not allow bypass; return the established validation/authorization outcome rather than choosing a more permissive interpretation.

Lunas requires payment permission with no approval alternative. Kasbon requires direct or request permission. Shared operations without a selected flow may use any checkout access, but operations that create or change flow state must check that flow. Preserve staged data if its flow becomes inaccessible; block unauthorized continuation/completion and explain the missing permission. Existing authorized recovery mechanisms remain responsible for resolution; do not add a new cross-user recovery system.

### Gate approval requests as well as token-backed completion

For debt actions only, require direct or request permission before creating an approval request and before using an approval token. Direct permission self-authorizes; request-only access requires a valid token at completion. No access must fail even with a previously valid approval token. Keep existing supervisor and token validation rules and leave other approval actions unchanged. Request-only users may prepare customer, term, and down payments before approval, subject to existing validation.

### Render accessible actions and switching

Lunas button follows payment permission. Kasbon is labeled “Kasbon” for direct access or “Kasbon · Perlu Persetujuan” for request-only access. Render the switch only when Lunas and Kasbon are both accessible, and enforce destination access on the server. Apply capabilities during client initialization and chain restoration as well as button rendering.

### Register without granting

Add the permission to the centralized registry and appropriate POS assignment grouping, using established permission synchronization. Do not add it to existing users, roles, or default bundles automatically. Administrators choose assignments. Document that payment-only users lose the old implicit approval fallback.

## Risks / Trade-offs

- Partial replacement of the payment guard leaves Kasbon-only users blocked: audit policy, FormRequest, controller, endpoint and JavaScript consumers together.
- Broad OR checks could permit unauthorized flow switching: enforce flow-specific access at mutation and final execution.
- Approval tokens could outlive request permission: recheck current cashier permission before token consumption/completion.
- Request/session flow mismatch could bypass checks: use consistent resolution and validate effective final payload.
- Existing tests and documented implicit approval behavior need explicit request permission fixtures after this intentional change.

## Rollout

Synchronize the new permission using the existing mechanism. Administrators assign request access explicitly. Deploy policy, server, and client changes together. Preserve stored payment chains. Rollback requires no checkout data migration; permission assignments remain administrator-managed.

## Verification

Run only focused permission/role policy, debt checkout and approval, staged-chain recovery, and client state-machine checks affected by this change. Cover direct API attempts as well as rendered actions. Use the repository SQLite test setup where appropriate. Do not plan or require the full test suite.
