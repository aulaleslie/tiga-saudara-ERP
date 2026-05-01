# Implementation Plan: POS Return by Transaction Number

**Branch**: `20260501-224617-pos-return-by-trx-number` | **Date**: 2026-05-01 | **Spec**: `specs/20260501-224617-pos-return-by-trx-number/spec.md`
**Input**: Feature specification from `/specs/20260501-224617-pos-return-by-trx-number/spec.md`

## Summary

Add a POS-specific return workflow that starts from a completed POS transaction or receipt number, builds an immutable source snapshot, and creates one POS Return header linked to owner/sale-aligned Sales Return records or lines. The implementation will reuse the existing Sales Return lifecycle for approval, receiving, settlement, replacement dispatch, serial handling, and dispatch-quantity return behavior while adding POS-specific lookup, permissions, snapshot validation, bundle rules, and split-sale mapping.

## Technical Context

**Language/Version**: PHP 8.x with Laravel 10 and Livewire 3  
**Primary Dependencies**: Laravel Framework, Livewire, Eloquent ORM, nwidart Modules, POS module services, Sales Return module, Sale/Dispatch entities, Spatie-style Gate permissions, Bootstrap/CoreUI Blade views  
**Storage**: MySQL/MariaDB via existing ERP database; migrations are created and applied through Laravel migration tooling (`php artisan make:migration` / `php artisan migrate`); SQLite is used only for focused automated test execution where the existing test suite supports it  
**Testing**: PHPUnit via `php artisan test` and `composer test:fresh-sqlite` with focused filters  
**Target Platform**: Web application (server-rendered Blade + Livewire under authenticated `role.setting` and POS feature middleware)  
**Project Type**: Modular Laravel ERP using `Modules/` plus app-level Livewire/support services  
**Performance Goals**: POS transaction lookup by code/receipt should use indexed fields and load a standard receipt of up to 25 lines in under 3 minutes end-to-end for return intake; cumulative quantity checks must aggregate by dispatch detail without scanning unrelated sales  
**Constraints**: Must preserve existing POS posting and Sales Return lifecycle behavior; must enforce `pos.returns.*` permissions and existing Super Admin bypass conventions; must scope lookup to the current setting/user visibility; must block stale snapshots; must not allow parent-only bundle returns; replacement dispatch must use original owner/location unless a separate transfer/override exists; cash refunds are manual after approval and receiving; migration changes must define reversible `down()` behavior and keep existing Sales Return rows valid through nullable/default-compatible linkage columns  
**Scale/Scope**: POS transaction return screens, POS return persistence, Sales Return linkage, POS permission registration/matrix update, split-owner sale mapping, bundle component return rules, cash/refund and replacement lifecycle guards, and focused tests across POS, Sale, and Sales Return modules

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- Brownfield First: PASS. Plan extends existing `Modules/Pos`, `Modules/SalesReturn`, and `Modules/Sale` behavior rather than replacing POS posting or Sales Return execution.
- Domain and Data Integrity: PASS. Plan calls out setting scope, owner/location alignment, source snapshot hashing, dispatch detail quantity caps, serial handling, bundle component rules, settlement caps, audited lifecycle actors, MySQL migration compatibility, reversible rollback behavior, and nullable/default-safe compatibility with existing Sales Return records.
- Laravel Pattern Fidelity: PASS. Work stays in Laravel/Livewire/Eloquent/nwidart module patterns, reuses current Gate permission checks and existing Sales Return lifecycle services/controllers where practical.
- Verification Proportional to Risk: PASS. Plan requires feature and Livewire tests for authorization, snapshot lookup, split sale mapping, bundle rules, approval/receive/settlement/dispatch guards, and quantity concurrency.
- Spec Traceability: PASS. Design artifacts map to FR-001..FR-025 and SC-001..SC-007, with existing OpenSpec POS/Sales Return behavior referenced as brownfield context.

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
└── tasks.md
```

### Source Code (repository root)

```text
app/
├── Livewire/
│   └── PosReturn/
│       ├── PosReturnCreateForm.php
│       └── PosReturnTable.php
└── Support/
    └── PosReturn/
        ├── PosReturnLifecycleService.php
        ├── PosReturnSnapshotService.php
        ├── PosReturnSubmissionService.php
        └── PosReturnQuantityGuard.php

Modules/
├── Pos/
│   ├── Database/Migrations/
│   │   ├── create_pos_returns_table.php
│   │   ├── create_pos_return_lines_table.php
│   │   ├── add_pos_return_link_columns_to_sale_returns.php
│   │   └── add_pos_return_line_link_columns_to_sale_return_details.php
│   ├── Entities/
│   │   ├── PosReturn.php
│   │   └── PosReturnLine.php
│   ├── Http/Controllers/
│   │   └── PosReturnController.php
│   ├── Resources/views/returns/
│   │   ├── index.blade.php
│   │   ├── create.blade.php
│   │   ├── edit.blade.php
│   │   └── show.blade.php
│   ├── Routes/web.php
│   └── Support/PosPermissionMatrix.php
├── SalesReturn/
│   ├── Entities/SaleReturn.php
│   ├── Entities/SaleReturnDetail.php
│   └── Http/Controllers/
│       ├── SalesReturnController.php
│       └── SaleReturnDispatchController.php
└── Sale/
    └── Entities/
        ├── Dispatch.php
        ├── DispatchDetail.php
        ├── Sale.php
        ├── SaleDetails.php
        └── SaleBundleItem.php

resources/
└── views/livewire/pos-return/
    ├── pos-return-create-form.blade.php
    └── pos-return-table.blade.php

Modules/Pos/Tests/Feature/
└── POSReturn*.php

tests/Feature/Livewire/PosReturn/
└── PosReturnCreateFormTest.php
```

**Structure Decision**: Keep POS-specific entry, navigation, permissions, routes, and wrapper persistence inside `Modules/Pos`; keep reusable orchestration services in `app/Support/PosReturn` following existing `app/Support/SalesReturn` precedent. Reuse Sales Return records/details for owner-aligned execution instead of building a separate return engine.

## Phase 0: Research Output

Completed in `specs/20260501-224617-pos-return-by-trx-number/research.md` with decisions for POS wrapper persistence, source snapshot immutability, transaction lookup, split-owner mapping, bundle return expansion, lifecycle delegation, manual cash refund settlement, replacement dispatch source constraints, permission registration, and verification scope.

## Phase 1: Design Output

Completed:
- `specs/20260501-224617-pos-return-by-trx-number/data-model.md`
- `specs/20260501-224617-pos-return-by-trx-number/contracts/pos-return-contract.md`
- `specs/20260501-224617-pos-return-by-trx-number/quickstart.md`

Agent context update:
- `AGENTS.md` `<!-- SPECKIT START -->` block points to `specs/20260501-224617-pos-return-by-trx-number/plan.md`.

## Post-Design Constitution Check

- Brownfield First: PASS
- Domain and Data Integrity: PASS
- Laravel Pattern Fidelity: PASS
- Verification Proportional to Risk: PASS
- Spec Traceability: PASS

No constitution violations identified; complexity tracking table not required.
