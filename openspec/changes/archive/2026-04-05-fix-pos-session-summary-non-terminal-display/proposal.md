## Why

Non-terminal POS sessions (created without a physical register) are used by staff to create transaction drafts for later finalization. The current session summary page displays a cash timeline and threshold information that are irrelevant for non-terminal sessions, causing confusion. Additionally, the cashier is displayed by ID instead of name, making it harder to identify who created the session. Users should see transaction-focused information for non-terminal sessions and be able to view detailed transaction records.

## What Changes

- **Cash Timeline Visibility**: Hide the cash events timeline for non-terminal sessions; replace with a transaction timeline showing drafts created during the session
- **Ikhtisar Sesi Card**: Simplify the session summary card for non-terminal sessions to show only: Status, Cashier Name (not ID), Transaction Count, and Total Amount
- **Cashier Display**: Replace cashier user ID with cashier name across all session types for better UX
- **Transaction Detail Navigation**: Clicking a transaction from the summary page navigates directly to `/pos/transactions/{id}` instead of opening a modal
- **PosSessionSummaryService Enhancement**: Update service to load PosTransaction records for non-terminal sessions and cashier relationship data

## Capabilities

### New Capabilities
- `pos-session-summary-non-terminal-view`: Session summary page displays transaction-focused information for non-terminal sessions instead of cash-related fields
- `pos-transaction-timeline-display`: Session summary shows a timeline of transactions created during the session with timestamps and amounts

### Modified Capabilities
- `pos-session-summary`: Existing session summary now conditionally displays content based on whether session has a terminal; includes cashier name instead of ID

## Impact

**Affected Code:**
- `Modules/Pos/Services/PosSessionSummaryService.php` - Add transaction loading and cashier relationship
- `Modules/Pos/Resources/views/session/summary.blade.php` - Conditional UI rendering and transaction click handling
- `Modules/Pos/Http/Controllers/PosSessionController.php` - No changes (service handles logic)
- `Modules/Pos/Tests/Feature/POSSessionSummaryViewTest.php` - Add tests for non-terminal sessions

**User-Facing Changes:**
- Session summary page now shows relevant information per session type
- Better session owner identification (name instead of ID)
- Clearer transaction viewing workflow (direct navigation instead of modal)

**No Breaking Changes**
- Existing terminal session views remain unchanged
- API structure remains the same
- All existing permissions and controls preserved
