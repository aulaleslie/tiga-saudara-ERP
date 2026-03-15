## Context

The repository currently treats `close_variance_approval_threshold` in two incompatible ways. Active POS code and settlement-oriented specs still use the threshold during session finalization, but an archived terminal-config change introduced a migration that drops the column and removed it from the terminal configuration surface. That migration is timestamped earlier than the module migration that creates `pos_terminal_policies`, so `migrate:fresh` fails on a clean database before the POS table even exists.

The user decision for this exploration is explicit: `close_variance_approval_threshold` remains part of the finalized POS design. The technical design therefore has to restore one authoritative contract across schema, configuration UI, runtime services, and OpenSpec artifacts.

Constraints:
- POS module migrations are loaded through the module service provider and are sorted together with root migrations.
- Some environments may already have run the destructive drop migration, so the solution must handle both fresh installs and upgraded databases.
- Archived OpenSpec artifacts are useful history, but they no longer reflect the intended product direction.

## Goals / Non-Goals

**Goals:**
- Keep `close_variance_approval_threshold` as a supported field in POS terminal policy storage.
- Make fresh installs and `migrate:fresh` succeed with the threshold column present.
- Repair upgraded databases where the threshold column is missing.
- Restore consistency across terminal configuration, session finalization, POS UI payloads, and tests.
- Record a clear superseding design direction in OpenSpec.

**Non-Goals:**
- Redesign the business meaning of variance approval thresholds.
- Replace terminal-specific thresholds with a global-only setting.
- Recover previously dropped threshold values automatically when no backup exists.
- Refactor unrelated POS terminal policy fields or approval workflows.

## Decisions

### Decision 1: Keep the threshold as a first-class terminal policy field
**Choice**: `close_variance_approval_threshold` remains persisted on `pos_terminal_policies`, remains editable through terminal-policy management, and remains available to finalization and reconciliation flows.

**Rationale**:
- Active runtime code already depends on the field for finalization decisions.
- Existing settlement specs describe threshold-based approval behavior.
- Removing it now would force a broader redesign of finalized POS settlement behavior.

**Alternatives Considered**:
- Remove the field entirely and rewrite finalization rules: contradicts the chosen product direction.
- Move the threshold to a global setting: loses terminal-specific behavior and expands the scope unnecessarily.

### Decision 2: Use a compatibility-first migration repair strategy
**Choice**: The destructive drop path will be neutralized for fresh installs, and the migration sequence will include an explicit repair step that restores `close_variance_approval_threshold` when the column is missing on upgraded databases.

**Rationale**:
- Fresh installs must stop failing immediately.
- Upgraded databases must converge to the intended schema even if the bad migration already ran.
- A repair-oriented path is safer than assuming the bad migration never reached any shared environment.

**Alternatives Considered**:
- Only delete or retimestamp the drop migration: helps clean installs but does not repair environments that already lost the column.
- Keep the drop migration and rework code around its absence: preserves the bug and conflicts with the product decision.

### Decision 3: Restore threshold consistency across admin and runtime contracts
**Choice**: Terminal policy forms, request validation, controller payload assembly, Eloquent model attributes, and POS reconciliation/session payloads all continue to expose the threshold as a supported value.

**Rationale**:
- A persisted field without configuration and read paths becomes operationally invisible.
- Finalization UI and service responses need the same threshold source to remain trustworthy.

**Alternatives Considered**:
- Keep the threshold internal-only: reduces admin control and leaves the configuration surface inconsistent with runtime behavior.
- Reintroduce only the schema field: incomplete and likely to create future regressions.

### Decision 4: Treat the archived removal path as superseded history
**Choice**: This new change becomes the active source of truth. Historical archive content may be referenced, but the implementation direction will not continue treating the threshold as unused.

**Rationale**:
- The archive explains how the repo became inconsistent, but it should not keep driving implementation.
- Preserving historical records is useful; making them authoritative would be harmful.

**Alternatives Considered**:
- Rewrite archived artifacts retroactively: hides the history of the wrong turn.
- Leave the contradiction undocumented: guarantees future confusion.

## Risks / Trade-offs

- [An environment may already have lost non-zero threshold values] → Recreate the column with a safe default for runtime continuity and document manual recovery from backups where necessary.
- [Only fixing one migration file could still leave upgrade paths inconsistent] → Verify both fresh-install and upgrade scenarios explicitly in implementation and tests.
- [The repo already contains contradictory OpenSpec narratives] → Add a superseding spec and design so future work has a single active reference point.

## Migration Plan

1. Make the migration sequence non-destructive for fresh installs so `pos_terminal_policies` is never altered before it exists.
2. Add an upgrade-safe repair step that recreates `close_variance_approval_threshold` when the column is absent.
3. Restore the threshold in terminal policy configuration and runtime payload contracts.
4. Re-run fresh migration and targeted POS finalization/terminal-policy tests to confirm the reconciled contract.
5. Archive or reference this change as the superseding source for future POS settlement work.

## Open Questions

1. Whether any shared or production-like environment already executed the drop migration and needs manual threshold-value restoration from backup.
