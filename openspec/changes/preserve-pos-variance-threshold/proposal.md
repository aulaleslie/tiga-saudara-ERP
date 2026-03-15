## Why

The POS codebase currently has a broken and contradictory story around `close_variance_approval_threshold`: a fresh migration fails because the column is dropped before `pos_terminal_policies` exists, while active POS services and settlement specs still rely on the threshold. This needs to be reconciled now so clean installs, local development, and future POS settlement work all share one consistent contract.

## What Changes

- Preserve `close_variance_approval_threshold` as an active terminal-policy field in the finalized POS design.
- Remove the schema contradiction that drops the threshold before the terminal policy table exists, and define a safe migration path for both fresh installs and upgraded databases.
- Reconcile OpenSpec artifacts so terminal policy configuration and session finalization consistently describe the threshold as part of the supported workflow.
- Restore code-level consistency across terminal policy persistence, session finalization, POS UI, and tests.

## Capabilities

### New Capabilities
- `pos-terminal-variance-threshold`: Terminal policies store a variance approval threshold that remains available to POS finalization workflows, configuration screens, and related session summaries.

### Modified Capabilities
<!-- None -->

## Impact

- Affected systems: POS database migrations, terminal policy persistence, session finalization logic, POS session reconciliation UI, and POS tests.
- Affected code: `database/migrations/`, `Modules/Pos/Database/Migrations/`, `Modules/Pos/Entities/`, `Modules/Pos/Services/`, `Modules/Pos/Http/Controllers/`, `public/js/`.
- Artifact alignment required across current and archived OpenSpec changes that describe terminal cash thresholds and variance approval.
