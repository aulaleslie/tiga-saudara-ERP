## Context

The repository already declares SQLite for testing in `phpunit.xml` and `.env.testing`, but Laravel is still able to boot the testing environment with cached configuration from the developer's normal environment. In the current state, `APP_ENV=testing` can still resolve `database.default=mysql` and point at the local `tiga_saudara` database, which makes `RefreshDatabase`, migration refreshes, and agent-run test commands destructive to local development data.

The user decision for this exploration is also explicit: automated tests should use a dedicated file-backed SQLite database. The design therefore needs to solve two problems at once: choose a stable SQLite target and prevent cached non-testing config from overriding it.

Constraints:
- The solution must work for PHPUnit-driven runs and `php artisan test` flows.
- It must not depend on developers remembering to clear config cache manually before running tests.
- Some existing tests or migrations may still expose SQLite incompatibilities after isolation is fixed; those are follow-on fixes, not reasons to keep using MySQL.
- The repository already ignores `database/*.sqlite*`, so generated test database files can remain untracked.

## Goals / Non-Goals

**Goals:**
- Force automated test execution onto an isolated, dedicated SQLite database file.
- Prevent cached MySQL configuration from contaminating the testing environment.
- Provide a fail-fast signal if the resolved test connection is unsafe.
- Keep the setup deterministic for local development and CI.

**Non-Goals:**
- Convert normal development or production environments to SQLite.
- Resolve every latent SQLite-specific migration or query issue in the same change.
- Redesign unrelated caching behavior outside what is required for safe test bootstrapping.

## Decisions

### Decision 1: Use a repository-local file-backed SQLite database
**Choice**: Automated tests will target a dedicated file such as `database/testing.sqlite`.

**Rationale**:
- File-backed SQLite works across multiple Laravel connections and subprocesses more reliably than `:memory:`.
- The file location is predictable, easy to inspect when debugging, and already covered by repository ignore rules.
- This matches the user's preference for a dedicated file-backed database.

**Alternatives Considered**:
- `:memory:` SQLite: faster but fragile when tests or artisan commands create additional connections.
- Temporary files under `/tmp`: workable, but less discoverable and less consistent across environments.

### Decision 2: Redirect config-cache loading in testing
**Choice**: Testing bootstrap will use a testing-specific `APP_CONFIG_CACHE` path so Laravel does not load the normal `bootstrap/cache/config.php` file during test execution.

**Rationale**:
- The current failure mode comes from cached config being loaded before testing env values take effect.
- Redirecting the config cache path is more reliable than assuming `config:clear` was run manually.
- Local verification shows that overriding `APP_CONFIG_CACHE` causes Laravel to boot `APP_ENV=testing` with uncached SQLite settings instead of cached MySQL settings.

**Alternatives Considered**:
- Require `php artisan config:clear` before every test run: too easy to forget and unsafe for agents.
- Delete cached config files automatically in test bootstrap: more invasive and surprising than redirecting the lookup path.

### Decision 3: Keep explicit SQLite settings in both test env sources
**Choice**: `phpunit.xml` and `.env.testing` will both point to the same dedicated SQLite file path.

**Rationale**:
- Redundant declarations make the intended test database obvious.
- Different entry points (`phpunit`, `artisan test`, IDE runners) can pick up environment state slightly differently.
- Consistency across both files reduces ambiguity during debugging.

**Alternatives Considered**:
- Define the database path in only one place: simpler, but easier for one execution path to drift.

### Decision 4: Fail fast if test boot resolves to an unsafe database
**Choice**: Test bootstrap will validate that `APP_ENV=testing` resolves to the dedicated SQLite connection and abort early if it instead points to MySQL or another non-isolated database.

**Rationale**:
- Silent fallback to MySQL is the destructive behavior we need to eliminate.
- A clear failure is preferable to discovering the problem after a `migrate:fresh` wipes local data.

**Alternatives Considered**:
- Trust configuration alone with no guardrail: too brittle given the current repo state.
- Allow any SQLite path: safer than MySQL, but weaker than enforcing a single known-isolated location.

### Decision 5: Create the SQLite file on demand rather than committing it
**Choice**: The dedicated SQLite database file will be generated locally when needed and remain untracked in git.

**Rationale**:
- Binary database artifacts should not be committed.
- The repository already ignores `*.sqlite*`, so on-demand creation fits the current workflow.

**Alternatives Considered**:
- Commit an empty SQLite file: unnecessary repository noise and potential merge churn.

## Risks / Trade-offs

- [Some migrations or tests may still fail once SQLite is truly used] → Treat those failures as genuine compatibility bugs and fix them incrementally after isolation is in place.
- [A single shared SQLite file may be insufficient for future parallel test execution] → If parallelism is adopted later, extend the design to generate worker-specific SQLite files.
- [Direct artisan commands run outside the testing bootstrap may still use the developer database] → Scope this change clearly to automated test execution and document the expected commands.

## Migration Plan

1. Point the testing environment at a dedicated SQLite file.
2. Redirect testing config-cache lookup away from the normal cached config artifact.
3. Add a fail-fast guard in the test bootstrap path.
4. Verify that testing boot reports SQLite and not cached MySQL config.
5. Run representative migration-backed tests and address any SQLite-specific failures separately.

## Open Questions

1. If the team later enables parallel test execution, should each worker receive its own SQLite file automatically?
