# POS Draft Repair Command: Operational Guide & Procedures

## 1. Overview & Purpose
`pos:repair-draft` is an administrative CLI command designed to safely repair uncompleted POS draft transactions where line-item amounts lack authoritative rounding metadata (such as in legacy draft transaction 3393), causing a discrepancy between raw quantity × price and the saved draft header total.

The command operates with conservative eligibility rules to ensure transactional integrity and auditability.

---

## 2. Command Usage

### 2.1 Preview Mode (Default / Dry-Run)
Always run preview first. Preview calculates missing metadata without modifying any database records.

```bash
php artisan pos:repair-draft <id> --setting=<setting_id>
```

#### Example Output:
```
POS Draft Repair Preview for Transaction #3393
Status: DRAFT
Setting ID: 1
Increment: 100.00
Grand Total: 318,000.00
Candidate Sum: 318,000.00
Preview Hash: a1b2c3d4e5f6...

Lines:
  - Line #1: Net 250,000.00 -> Candidate 250,000.00
  - Line #2: Net 25,992.00 -> Candidate 26,000.00 (Adjusted by +8.00)
  - Line #3: Net 14,000.00 -> Candidate 14,000.00
  - Line #4: Net 28,000.00 -> Candidate 28,000.00

Draft is eligible for repair.
To apply this repair, run:
  php artisan pos:repair-draft 3393 --setting=1 --apply --preview-hash=a1b2c3d4e5f6... --actor=1
```

### 2.2 Apply Mode
Requires explicit flags `--apply`, `--preview-hash=<sha256>`, and `--actor=<user_id>` (must be a valid integer ID of an existing user in the database).

```bash
php artisan pos:repair-draft <id> --setting=<setting_id> --apply --preview-hash=<preview_hash> --actor=1
```

---

## 3. Eligibility & Guardrails

The command strictly refuses to repair if:
1. **Status is not DRAFT**: Completed (`FINAL`, `COMPLETED`), `CANCELLED`, or any non-draft status is rejected. Completed records remain immutable.
2. **Draft is actively loaded**: Transactions with `status = LOADED` or loaded into an active cashier session are rejected to avoid race conditions.
3. **Draft has no line items**: Empty drafts cannot be repaired.
4. **Ambiguous or mismatched totals**: The sum of candidate line totals MUST equal the draft's header `grand_total` (in minor units). If there is any discrepancy, the command refuses to mutate.
5. **Setting ID does not match**: Ensures the operator is targeting the correct business/store context.
6. **Preview Hash required and verified**: On apply, the command requires `--preview-hash`, acquires an atomic row lock (`lockForUpdate`), completely re-evaluates the fresh draft snapshot under lock, and verifies that the preview SHA-256 hash matches before updating.
7. **Manual overrides preserved**: Lines with manual price overrides or canonical override metadata bypass automatic increment rounding and retain their explicit amounts. Already-authoritative rows are not overwritten.
8. **Actor attribution required**: The `--actor` flag must specify a valid, existing user ID.

---

## 4. Audit & Idempotency

- **Atomic Update**: Line metadata, `snapshot_hash`, and `last_saved_by` are updated inside a single database transaction under `lockForUpdate`.
- **Audit Logging**: Successful repairs emit an `audit` / `info` log containing:
  - `transaction_id`: Transaction ID
  - `actor_user_id`: Numeric ID of the attributing user
  - `increment`: Business rounding increment applied
  - `preview_hash`: SHA-256 preview hash verified under lock
  - `new_snapshot_hash`: Newly generated snapshot hash
  - `before_snapshot`: Complete state of lines and totals before repair
  - `after_snapshot`: Complete state of lines and totals after repair
  - `timestamp`: Event timestamp
- **Idempotency**: Running repair multiple times with `--apply` on an already-repaired draft is safe. It revalidates the exact matching metadata and updates nothing new.

---

## 5. Deployment & Rollback Procedures

### 5.1 Deployment
1. Deploy application code (services, controllers, views, commands).
2. Run database migrations / tests.
3. **No automatic background data rewrite runs on deployment.** All legacy drafts remain untouched until explicitly inspected by operators.

### 5.2 Operator Remediation Workflow (e.g. Transaction 3393)
1. Identify candidate draft ID and setting ID.
2. Execute preview command:
   `php artisan pos:repair-draft 3393 --setting=1`
3. Inspect output table to verify each row's candidate net amount and ensure candidate sum matches header grand total.
4. Copy the deterministic `Preview Hash`.
5. Run apply command with user ID:
   `php artisan pos:repair-draft 3393 --setting=1 --apply --preview-hash=<hash> --actor=1`
6. Verify in transaction detail view / POS cart reload that row amounts now match the header.

### 5.3 Rollback Procedure
If an explicit draft repair must be rolled back:
1. Locate the repair log entry for the transaction in the application log (searching for `"POS Draft Repaired"` and `transaction_id`).
2. Extract the `before_snapshot` object from the log context. It contains the exact `line_meta` and `snapshot_totals` prior to repair.
3. Execute a targeted artisan tinker script or database update under transaction:
   - For each line in `before_snapshot['lines']`, restore `line_meta` to the recorded pre-repair values.
   - Recompute and restore `snapshot_hash` and `last_saved_by`.
4. Run preview again (`php artisan pos:repair-draft <id> --setting=<setting_id>`) to confirm the draft has reverted to its pre-repair state.
