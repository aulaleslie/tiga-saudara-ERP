# Production Cutover, Dry-Run, and Monotonic Recovery Runbook

This runbook documents the operational procedure for bootstrapping sequence counters from historical data, conducting pre-cutover dry runs, reviewing blockers, executing staged activation, and recovering from stale counters in production.

---

## 1. Pre-Cutover Dry Run & Blocker Review

Before enabling the new allocator in production, perform a dry-run analysis on an anonymized recent production backup (or directly in a read-only staging environment).

### 1.1 Run Dry-Run Command
```bash
php artisan sequence:bootstrap --family=all --dry-run
```

### 1.2 Review Output Sections

1. **Malformed / Unparseable References:**
   - Review reported rows.
   - Any unparseable reference will not participate in sequence derivation and will not be altered.
   - If an unparseable reference is recent and represents the true current sequence, manually determine if the counter target needs an explicit manual bump after bootstrap.
2. **Unexpected Prefixes (Historical Prefix Changes):**
   - Check if businesses previously used alternate prefixes (e.g. `PR-` before changing to `PD-BL-PR-`).
   - The bootstrap creates distinct namespace rows for each historical prefix. Subsequent documents under the new prefix will correctly allocate from the current prefix's counter.
3. **Date Drift References:**
   - References where document `date` month != embedded reference month.
   - The reconciliation service properly indexes references by their embedded namespace, so date drift does not corrupt sequence counters.
4. **Namespace Summary:**
   - Verify that for every active business setting and document type (Purchase/Sale), the derived `Target Counter` matches or exceeds the expected current maximum reference.

---

## 2. Staged Production Cutover Procedure

### Stage 1: Purchase Cutover
1. **Enter Maintenance Mode (or quiet window):**
   ```bash
   php artisan down --render="errors::503" --secret="cutover-token"
   ```
2. **Run Migrations:**
   ```bash
   php artisan migrate --force
   ```
3. **Run Purchase Bootstrap:**
   ```bash
   php artisan sequence:bootstrap --family=purchase
   ```
4. **Enable Purchase Sequence Activation Gate:**
   Set `SEQUENCE_PURCHASE_ENABLED=true` in `.env`.
5. **Smoke Checks:**
   - Create a test draft Purchase in UI; verify reference matches `current_counter + 1`.
   - Verify Purchase import dry-run/preview.
6. **Resume Traffic:**
   ```bash
   php artisan up
   ```

### Stage 2: Sale & POS Cutover
1. **Enter Maintenance Mode:**
   ```bash
   php artisan down --render="errors::503" --secret="cutover-token"
   ```
2. **Run Sale Bootstrap:**
   ```bash
   php artisan sequence:bootstrap --family=sale
   ```
3. **Enable Sale Sequence Activation Gate:**
   Set `SEQUENCE_SALE_ENABLED=true` in `.env`.
4. **Smoke Checks:**
   - Create a normal Sale in UI.
   - Perform a POS single-owner checkout.
   - Perform a POS split-owner checkout across two businesses; verify each Sale gets its respective business prefix and sequence.
5. **Resume Traffic:**
   ```bash
   php artisan up
   ```

---

## 3. Monotonic Recovery Procedure for a Stale Counter

If a counter row in `document_sequences` becomes stale (e.g. due to manual DB manipulation or restoring older data):

1. **Automatic Self-Healing:**
   The `DocumentSequenceAllocator::executeWithConflictRetry()` automatically catches unique constraint violations on `(setting_id, reference)`, scans historical references via `reconcileCounter()`, advances `last_number` to `max(current_counter, historical_max)`, logs a structured warning, and retries the allocation once safely.

2. **Manual Re-synchronization:**
   To re-align all counters to historical maximums without downtime:
   ```bash
   php artisan sequence:bootstrap --family=all
   ```
   *Note: `sequence:bootstrap` is monotonic and safe to run online. It only increases `last_number` and never decrements.*
