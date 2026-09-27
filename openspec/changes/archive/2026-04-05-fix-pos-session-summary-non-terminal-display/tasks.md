## 1. Service Layer Enhancement

- [x] 1.1 Update PosSessionSummaryService to load cashier relationship
- [x] 1.2 Add PosTransaction loading logic for non-terminal sessions
- [x] 1.3 Add transaction mapping to consistent array structure
- [x] 1.4 Include cashier_name in returned summary array
- [x] 1.5 Test service returns correct data for both session types
- [x] 1.6 Verify transaction limit (50 records) is applied correctly

## 2. View Template Updates

- [x] 2.1 Update Ikhtisar Sesi card to use cashier_name instead of cashier_user_id
- [x] 2.2 Add conditional rendering for non-terminal session (simplified card)
- [x] 2.3 Hide cash threshold alert and related fields for non-terminal sessions
- [x] 2.4 Hide "Timeline Kas" section for non-terminal sessions
- [x] 2.5 Add "Timeline Transaksi" section for non-terminal sessions
- [x] 2.6 Map transaction data to timeline display format
- [x] 2.7 Add empty state message for transaction timeline when no transactions

## 3. JavaScript and Navigation

- [x] 3.1 Update transaction row click handler to navigate instead of opening modal
- [x] 3.2 Remove or conditionally hide checkout detail modal for non-terminal views
- [x] 3.3 Test navigation to /pos/transactions/{id} works correctly
- [x] 3.4 Verify transaction detail page loads with correct permissions

## 4. Testing

- [x] 4.1 Add test for non-terminal session summary view rendering
- [x] 4.2 Add test for terminal session summary view rendering (unchanged)
- [x] 4.3 Add test for service returning transactions for non-terminal sessions
- [x] 4.4 Add test for service returning checkouts for terminal sessions
- [x] 4.5 Add test for cashier_name in response
- [x] 4.6 Add test for transaction navigation from summary
- [x] 4.7 Verify authorization checks still work for both session types
- [x] 4.8 Test empty transaction timeline state

## 5. Code Review and Documentation

- [x] 5.1 Review service changes for query efficiency
- [x] 5.2 Review blade template conditionals for clarity
- [x] 5.3 Verify no breaking changes to existing terminal session behavior
- [x] 5.4 Update code comments in modified files
- [x] 5.5 Verify test coverage is adequate
