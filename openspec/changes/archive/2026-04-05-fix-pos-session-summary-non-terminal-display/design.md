## Context

The POS session summary page currently treats all sessions uniformly, displaying cash event timelines and threshold information regardless of session type. Non-terminal sessions (created without a physical terminal) are used for transaction drafting and don't involve cash handling, making cash-focused UI elements misleading. The page also displays cashier user IDs instead of names, reducing usability.

**Current State:**
- All sessions show "Timeline Kas" (cash events) via `$cash_events` from service
- Ikhtisar Sesi shows `cashier_user_id` (numeric) instead of cashier name
- Transaction clicks open a modal (checkoutDetailModal) instead of navigating
- `PosSessionSummaryService` loads checkouts only (finalized sales), not transaction drafts
- No differentiation between terminal and non-terminal session display

**Data Model Context:**
- Terminal sessions: Have opening float, create PosCheckout records (finalized sales), track cash events
- Non-terminal sessions: No opening float, create PosTransaction draft records (staff work), no cash events
- These are different business workflows with different entities

## Goals / Non-Goals

**Goals:**
1. Display context-appropriate information based on session type (terminal vs non-terminal)
2. Show transaction timeline for non-terminal sessions instead of cash timeline
3. Display cashier name instead of ID for better UX
4. Allow users to navigate to full transaction details from summary
5. Simplify Ikhtisar Sesi card for non-terminal sessions to show only relevant metrics

**Non-Goals:**
- Modify terminal session display (continue showing cash timeline and thresholds)
- Change PosCheckout or PosTransaction entity structure
- Modify transaction detail page (`/pos/transactions/{id}`)
- Alter cash event or safe drop functionality
- Create new permission model

## Decisions

### Decision 1: Load PosTransaction Records for Non-Terminal Sessions
**Choice:** Modify `PosSessionSummaryService::getSummary()` to load PosTransaction records when `terminal_id` is null, instead of PosCheckout records.

**Rationale:** Non-terminal sessions don't create checkouts; they create transaction drafts. Showing checkouts (empty) gives wrong information. Transaction records show the actual work created.

**Alternatives Considered:**
- Show combined checkouts + transactions: Adds complexity, unnecessary for non-terminal (no checkouts)
- Show empty timeline for non-terminal: Poor UX, confusing

**Implementation:**
- Check `session->terminal_id` in service
- If null: query `PosTransaction::where('source_pos_session_id', $sessionId)` 
- If not null: continue current checkout logic
- Both use same array structure for template compatibility

### Decision 2: Load Cashier Relationship in Service
**Choice:** Include cashier relationship in service data instead of just cashier_user_id.

**Rationale:** Blade template shouldn't fetch missing relationships; service should provide complete data.

**Implementation:**
- Modify session query: `->with(['cashier', 'terminal.policy', 'cashEvents.performer', 'cashEvents.approver'])`
- Include in return array: `'cashier_name' => $session->cashier?->name ?? 'Unknown'`
- Blade uses `$cashier_name` instead of `$cashier_user_id`

### Decision 3: Conditional UI Rendering in Blade
**Choice:** Use session type (terminal vs non-terminal) to conditionally show/hide UI sections.

**Rationale:** Single template with conditional logic is simpler than two views; keeps template DRY.

**Implementation:**
- Ikhtisar Sesi card: Show all fields for terminal, subset for non-terminal
- Timeline section: Show "Timeline Kas" for terminal sessions, "Timeline Transaksi" for non-terminal
- Use `@if($terminal_id)` and `@unless($terminal_id)` blocks

### Decision 4: Direct Transaction Navigation (Not Modal)
**Choice:** Transaction rows navigate to `/pos/transactions/{id}` instead of opening modal.

**Rationale:** Modal was designed for checkouts (finalized); transactions have dedicated detail page with load/cancel actions. Navigation is clearer workflow.

**Implementation:**
- Update JavaScript: transaction row click → `window.location.href = '/pos/transactions/{id}'`
- Remove checkout detail modal from non-terminal view
- Keep modal for terminal sessions if modal was in use (check current behavior)

### Decision 5: Timeline Presentation
**Choice:** Unified timeline structure; same data model for both cash events and transactions.

**Rationale:** Single template loop works for both, reducing complexity.

**Map:**
```
Cash Event → {timestamp, amount, performer, event_type, direction}
Transaction → {timestamp, amount, owner, code/receipt_number, status}
```

Transactions will be mapped to similar structure for template compatibility.

## Risks / Trade-offs

**[Risk] Service Complexity**
- getSummary() now has branching logic for terminal vs non-terminal
- **Mitigation:** Keep branching minimal and clearly commented; consider extracting to private methods if it grows

**[Risk] Data Loading Performance**
- Loading transaction relationships could add query overhead for sessions with many transactions
- **Mitigation:** Limit transaction query to recent 50 (same as checkouts currently); use `limit(50)`

**[Risk] Transaction Detail Page Expectations**
- Users navigating to `/pos/transactions/{id}` might expect different permissions/actions than what's available
- **Mitigation:** Transaction detail page already handles authorization; it's the right place for that logic

**[Trade-off] Modal Removal
- If checkout modal was being used elsewhere, removing it could break those flows
- **Mitigation:** Verify checkout detail modal is only used in session summary; terminal sessions still get modal if needed

## Migration Plan

**Deploy Steps:**
1. Update `PosSessionSummaryService` to load transactions and cashier name
2. Update `summary.blade.php` with conditional UI and navigation logic
3. Update JavaScript handlers for transaction clicks
4. Add tests for non-terminal session scenarios
5. Verify in staging with both terminal and non-terminal sessions

**Rollback Strategy:**
- Revert service and blade changes; restore old branch
- No data migrations needed (no database schema changes)

## Open Questions

1. **Current modal behavior**: Is the checkout detail modal currently working/used? Should it stay for terminal sessions?
2. **Transaction filtering**: Should non-terminal transactions be filtered by status (DRAFT vs POSTED)? If POSTED, do we need checkouts?
3. **Timeline ordering**: Should transaction timeline be sorted newest-first (like cash events)?
