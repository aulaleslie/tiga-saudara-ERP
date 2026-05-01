# Implementation Plan: POS Return by Transaction Number

**Branch**: `20260501-224617-pos-return-by-trx-number` | **Date**: 2026-05-02 | **Spec**: [spec.md](./spec.md)
**Input**: Feature specification from `/specs/20260501-224617-pos-return-by-trx-number/spec.md`

## Summary

Add a POS-specific return workflow that starts from a completed POS transaction or receipt number, produces one POS Return header for the transaction, and executes the actual owner/sale-aligned reversal through linked Sales Return records/details. The implementation will live primarily in `Modules/Pos` with additive links into `Modules/SalesReturn`, reusing existing Sales Return approval, receiving, payment return settlement, replacement dispatch, serial, stock, and audit behavior while adding POS lookup, immutable source snapshots, POS-specific permissions, bundle expansion, and split-owner mapping.

## Technical Context

**Language/Version**: PHP 8.x in Laravel 10 application
**Primary Dependencies**: Laravel 10, Livewire 3, Blade, Eloquent ORM, nwidart modules, Spatie permissions, Bootstrap/CoreUI conventions
**Storage**: MySQL/MariaDB production schema via Laravel migrations; SQLite compatibility for focused automated tests
**Testing**: `php artisan test` with focused filters; `composer test:fresh-sqlite` for higher-confidence migration/test pass
**Target Platform**: Existing web ERP deployed as Laravel app
**Project Type**: Brownfield modular Laravel web application
**Performance Goals**: Authorized trained user can complete lookup, snapshot review, quantity/option entry, and submit within 3 minutes for a standard 25-line POS receipt in UAT/staging with production-like data
**Constraints**: Preserve existing POS, Sales Return, dispatch, stock, serial, payment, tax, setting, and permission behavior; all submit/approve/reject/receive/payment-return-settlement/dispatch/audited archive-cancel actions must be atomic database transactions; no rewrite of historical POS or Sales Return records
**Scale/Scope**: One POS return workflow covering normal lines, bundles, split-owner checkouts, partial returns, serial-tracked lines, payment return settlement, and same-SKU product replacement

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **I. Brownfield First**: PASS. Plan is based on existing `Modules/Pos`, `Modules/SalesReturn`, `app/Livewire/SalesReturn/*`, POS split posting, `pos_checkout_sales`, `sale_returns`, and dispatch detail behavior.
- **II. Domain and Data Integrity**: PASS. Plan calls out owner/location/tax mapping, dispatch detail links, stock-managed vs stockless bundle components, serial handling, payment return caps, cumulative return limits, and migration compatibility.
- **III. Laravel Pattern Fidelity**: PASS. Uses Eloquent models, Livewire screens, module routes/controllers/migrations, Spatie permissions, existing Sales Return services/controllers, and existing POS permission matrix conventions.
- **IV. Verification Proportional to Risk**: PASS. Requires focused feature/Livewire tests across lookup, permissions, split-owner mapping, bundle expansion, serials, lifecycle guards, atomic rollback, and payment return settlement/replacement dispatch option exclusivity.
- **V. Spec Traceability**: PASS. Artifacts in this feature directory trace from `spec.md` to `plan.md`, `research.md`, `data-model.md`, `contracts/pos-return-contract.md`, `quickstart.md`, and existing `tasks.md`.

## Project Structure

### Documentation (this feature)

```text
specs/20260501-224617-pos-return-by-trx-number/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   └── pos-return-contract.md
├── checklists/
│   ├── pos-return.md
│   └── requirements.md
└── tasks.md
```

### Source Code (repository root)

```text
Modules/Pos/
├── Entities/
│   ├── PosReturn.php
│   └── PosReturnLine.php
├── Database/Migrations/
│   └── *pos_return*
├── Http/Controllers/
│   └── PosReturnController.php
├── Resources/views/
│   └── returns/
├── Routes/
│   └── web.php
├── Services/
│   ├── PosReturnLookupService.php
│   ├── PosReturnSnapshotService.php
│   ├── PosReturnSubmissionService.php
│   └── PosReturnLifecycleService.php
├── Support/
│   └── PosPermissionMatrix.php
└── Tests/Feature/
    └── POSReturn*.php

app/
├── Config/Permissions.php
├── Livewire/PosReturn/
├── Support/PosReturn/
└── Support/SalesReturn/

Modules/SalesReturn/
├── Entities/
│   ├── SaleReturn.php
│   └── SaleReturnDetail.php
└── Http/Controllers/

resources/views/livewire/pos-return/

tests/Feature/Livewire/PosReturn/
```

**Structure Decision**: Implement the POS-facing workflow in `Modules/Pos` because routing, transaction lookup, receipt/checkout data, split-owner metadata, and POS permissions already live there. Keep all POS Return migrations in `Modules/Pos`, including nullable linkage columns added to existing Sales Return tables, so the feature-owned schema changes stay together. Add nullable links to existing Sales Return tables so the existing Sales Return lifecycle remains the execution engine for approval, receiving, payment return settlement, and replacement dispatch.

## Historical OpenSpec References

This feature overlaps existing POS, Sales Return, bundle, serial, permission, and checkout behavior. The following historical `openspec/` records were consulted as brownfield reference context:

- `openspec/specs/pos-checkout-split-posting/spec.md` for owner-aligned POS sale generation.
- `openspec/specs/pos-standalone-bundle-row-posting/spec.md` and `openspec/specs/sales-standalone-bundle-rows/spec.md` for bundle/component sale line behavior.
- `openspec/specs/pos-checkout-serial-stock-validation/spec.md` and `openspec/specs/pos-serial-dispatch-reservation-guard/spec.md` for serial and stock constraints.
- `openspec/specs/pos-permission-governance/spec.md` and `openspec/specs/pos-role-bundles/spec.md` for POS permission conventions.
- `openspec/specs/pos-payment-stage-persistence/spec.md` and `openspec/specs/pos-multi-payment-checkout-persistence/spec.md` for payment allocation context.

Implementation must preserve the behaviors described by these historical specs unless this feature explicitly defines a narrower POS-return-specific rule.

## Phase 0: Research Output

Research is captured in [research.md](./research.md). Key resolved decisions:

- One `pos_returns` wrapper record represents the customer-facing POS transaction return.
- Linked Sales Return records/details execute owner/sale-aligned lifecycle actions.
- Lookup accepts POS transaction code and receipt number, but requires exactly one active posted/completed source.
- Source snapshots are canonical JSON plus hash and are revalidated before submit/update.
- Bundle returns expand to every original component; stockless components are retained for audit/monetary mapping without inventory effects.
- Product replacement is same SKU only, same quantity as received returned quantity, sourced from original owner/location.
- Active non-reversed returns count against cumulative eligibility; rejected/deleted/fully audited cancelled or archived returns release eligibility.
- Submit, approve, reject, receive, payment return settlement, replacement dispatch, and audited archive/cancel are wrapped in atomic DB transactions.

## Phase 1: Design Output

Design artifacts are captured in:

- [data-model.md](./data-model.md)
- [contracts/pos-return-contract.md](./contracts/pos-return-contract.md)
- [quickstart.md](./quickstart.md)

Core data design:

- New `pos_returns` table for POS transaction-level header, source snapshot/hash, option, lifecycle/audit fields, and source POS references.
- New `pos_return_lines` table for owner/sale/dispatch-aligned lines, bundle grouping, stock behavior, serial references, and linked Sales Return details.
- Add nullable links from `sale_returns` and, if needed, `sale_return_details` back to POS Return entities.
- No destructive migration or historical data rewrite.

## Post-Design Constitution Check

- **Brownfield First**: PASS. Existing POS split posting and Sales Return execution are reused.
- **Domain and Data Integrity**: PASS. The design preserves source POS checkout, `pos_checkout_sales`, generated sale, dispatch detail, location, tax, serial, payment allocation, and bundle composition relationships.
- **Laravel Pattern Fidelity**: PASS. Proposed files follow existing Laravel module, route, controller, Eloquent, Livewire, and permission configuration patterns.
- **Verification Proportional to Risk**: PASS. Quickstart and tasks require targeted tests plus UAT composition for normal, bundled, split-owner, partial, serial-tracked, payment-return, and product-replacement cases.
- **Spec Traceability**: PASS. Requirements are represented in research, data model, contract, quickstart, and task artifacts.

## Complexity Tracking

No constitution violations requiring justification.
