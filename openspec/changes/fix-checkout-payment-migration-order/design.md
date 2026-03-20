## Context

The POS module uses modular migrations under `Modules/Pos/Database/Migrations/`. These are loaded and sorted by filename, which embeds a date-based ordering. Currently, the checkout payment tables migration is dated `2026_03_17` (March), but it depends on `pos_checkouts` which is created at `2026_08_13_000300` (August). This causes `migrate:fresh` to fail.

## Goals / Non-Goals

**Goals:**
- Fix migration ordering so `php artisan migrate:fresh --seed` runs cleanly
- Preserve all table definitions and foreign key constraints exactly as they are

**Non-Goals:**
- Modifying any migration content (schema, columns, indexes)
- Changing application code or models
- Fixing any other migration ordering issues (only this specific one)

## Decisions

### Rename the migration file to `2026_08_13_000400`

**Rationale**: Place it immediately after `2026_08_13_000300_create_pos_checkouts_table.php` in the sort order. This is the simplest fix — a single `git mv` — and preserves the existing migration content unchanged.

**Alternatives considered**:
- **Split the FK into a separate later migration**: Adds unnecessary complexity for a simple ordering fix.
- **Use `Schema::disableForeignKeyConstraints()`**: Masks the ordering issue and could hide real problems.

## Risks / Trade-offs

- **Already-migrated databases**: No risk. The `migrations` table records the migration class name (anonymous class), not the filename. On `migrate:fresh` the entire DB is dropped anyway. On incremental `migrate`, since the migration content is identical and the table already exists, this rename only affects fresh installs.
- **Batch number shift**: On a fresh migration, batch numbers may differ from before. This has no functional impact.
