## Context

Purchase and purchase-return authorization currently drifts across `app/Config/Permissions.php`, route middleware, controller gate checks, Livewire checks, and Blade `@can` usage. The existing state includes undefined permissions being checked in code, duplicate semantics for similar actions, and action visibility gates that do not consistently match backend enforcement.

This change is cross-cutting across User, Purchase, and PurchasesReturn modules and impacts role assignment data in Spatie permission tables.

## Goals / Non-Goals

**Goals:**
- Establish one canonical permission taxonomy for purchase and purchase-return actions.
- Ensure every gated route/action uses a defined permission key.
- Normalize role UI grouping so related purchase permissions are discoverable and consistent.
- Remove dead/unused permission keys and unreachable permission-guarded UI/actions.
- Provide a migration-safe path for existing roles.

**Non-Goals:**
- Redesign purchase or purchase-return business workflows.
- Change non-purchase domains (POS, sales, inventory beyond permission touchpoints).
- Introduce a new authorization framework beyond current Gate/Spatie patterns.

## Decisions

1. Canonical action vocabulary for purchase domains
- Decision: Use a normalized action set per domain: `create`, `update`, `delete`, `archive`, `print`, `receive`, `approval`, plus `access`.
- Rationale: Reduces semantic drift (`edit` vs `update`, implicit print access, etc.) and gives predictable role design.
- Alternative considered: Keep mixed legacy keys and document mappings. Rejected because drift remains and future changes stay error-prone.

2. Single source of truth remains `app/Config/Permissions.php`
- Decision: All permission keys must exist in config before any gate usage is allowed.
- Rationale: Seeder sync already enforces config truth and prunes orphans.
- Alternative considered: Allow dynamic permission creation from code usage. Rejected because it undermines governance and auditability.

3. Enforce authorization parity across layers
- Decision: Align route middleware, controllers, Livewire, and Blade `@can` to the same key per action capability.
- Rationale: Prevents UI/backend mismatch and 403 surprises.
- Alternative considered: Rely on controller-only checks and keep UI permissive. Rejected because it weakens role UX and causes confusion.

4. Backward compatibility handled by explicit migration step, not permanent aliases
- Decision: Provide transitional mapping in migration/seeding process to remap legacy role permissions to canonical keys, then retire legacy keys.
- Rationale: Keeps long-term model clean while protecting existing roles.
- Alternative considered: Keep legacy aliases forever. Rejected because it preserves conceptual duplication and maintenance burden.

5. Scope boundary for purchase-return settlements
- Decision: Keep settlement-specific permissions (`purchaseReturnSettlements.*`) as a distinct capability and only normalize overlaps with purchase-return lifecycle permissions.
- Rationale: Settlement is a separate operational phase with finer-grained controls.
- Alternative considered: Collapse settlement permissions into generic purchase-return verbs. Rejected due to loss of operational granularity.

## Risks / Trade-offs

- [Existing roles lose access after key cleanup] → Mitigation: perform explicit role-permission remap migration before orphan cleanup.
- [Missed gate usage outside audited files] → Mitigation: repository-wide key usage audit and regression tests for purchase/purchase-return workflows.
- [Over-normalization reduces useful granularity] → Mitigation: keep settlement and payment capability namespaces separate.
- [Temporary confusion during transition] → Mitigation: publish mapping table in proposal/tasks and update role UI labels concurrently.

## Migration Plan

1. Introduce canonical permission keys in config and temporary mapping table from legacy keys.
2. Update enforcement sites (routes/controllers/Livewire/Blade) to canonical keys.
3. Execute role remap migration to copy legacy assignments to canonical keys.
4. Remove deprecated/unused permission keys from config so seeder prunes them.
5. Run permission sync and regression tests for purchase and purchase-return authorization paths.

Rollback strategy:
- Restore previous permission config and gate checks from git revert.
- Re-run permission sync with previous config.
- Re-apply legacy assignment snapshot if remap migration introduced access gaps.

## Open Questions

- Should `purchases.view` and `purchases.show` be merged into one canonical key, or remain separate list/detail semantics?
- Should print permissions be strictly separate from show permissions for both purchases and purchase returns?
- Should `purchaseReturns.receive` be introduced as a lifecycle alias, or remain exclusively under `purchaseReturnSettlements.receive`?
