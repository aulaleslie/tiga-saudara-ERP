# Tiga Saudara ERP Constitution

## Core Principles

### I. Brownfield First

All changes must start from the existing application behavior, database shape, module boundaries, and historical `openspec/` records. New Spec Kit work must not assume a greenfield rewrite, replace established workflows without migration rationale, or remove existing behavior unless the spec explicitly calls for it.

### II. Domain and Data Integrity

ERP flows must preserve financial, inventory, sales, purchase, POS, tax, customer, supplier, serial number, payment, and permission integrity. Specs and plans must call out data mutations, migration impact, tenant or location scoping, stock posting behavior, and compatibility with existing records.

### III. Laravel Pattern Fidelity

Implementation must prefer the application's existing Laravel 10, Livewire 3, Eloquent, Blade, nwidart module, permission, service, and test conventions. Introduce new abstractions only when they remove real complexity or match an established local pattern.

### IV. Verification Proportional to Risk

Each implementation plan must define focused verification before coding starts. Use `composer test:fresh-sqlite`, `php artisan test`, or targeted manual checks according to blast radius. Financial posting, inventory movement, permissions, checkout, migration, and cross-module changes require stronger test coverage or an explicit residual-risk note.

### V. Spec Traceability

Every non-trivial change should be traceable from specification to plan, tasks, implementation, and verification notes. New work uses `.specify/` and root `specs/`; existing `openspec/` artifacts remain historical reference material and must be consulted when overlapping capabilities already exist.

## Project Constraints

The project is a Laravel 10 ERP with Livewire 3, Blade views, Eloquent ORM, nwidart modules under `Modules/`, Vite assets, and PHPUnit/Laravel test tooling. Database changes require explicit migration, rollback, and data compatibility notes. UI work must preserve existing Bootstrap/CoreUI/Blade/Livewire conventions unless the spec requires a deliberate UI change.

## Development Workflow

Start each feature with a Spec Kit specification before planning implementation. Plans must identify affected modules, routes, Livewire components, models, migrations, policies or permissions, views, jobs, and tests. Tasks should be small enough for agent execution and should protect unrelated working-tree changes. Implementation must update or add tests where risk justifies it and must record any verification not performed.

## Governance

This constitution is the default guidance for Spec Kit work in this repository. Amendments require a short rationale in the related spec or plan, plus updates to affected templates or agent guidance when the rule changes future behavior. If this constitution conflicts with a feature spec, the spec must explicitly document the exception and risk.

**Version**: 0.1.0 | **Ratified**: 2026-04-29 | **Last Amended**: 2026-04-29
