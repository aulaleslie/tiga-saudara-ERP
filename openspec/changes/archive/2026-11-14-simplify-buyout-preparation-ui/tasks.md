# Tasks

## 1. Evidence reuse

- [x] 1.1 Add an opt-in read-only memo scope to `ConsignmentBuyoutCapacityService` covering attribution, capacity, residual, pool, pool blockers, custody-event and serial-eligibility reads without exclusion ids; verify mutation paths never enable it.
- [x] 1.2 Add a representative query-budget feature test and record before/after counts.

## 2. Preparation read model

- [x] 2.1 Extend `ConsignmentBuyoutProposalService::allEligible` with deferred destinations, full serial options and separated hard blockers without changing existing proposal results.
- [x] 2.2 Add `ConsignmentBuyoutPreparationService` producing product/receipt groups, pool and sold-source limits, receipt prices, serial labels, holding businesses and plain-language blocked rows with actions.

## 3. Screen

- [x] 3.1 Rewrite `buyouts/create.blade.php` as three steps (supplier & destinations, select goods, review & save) with preserved selections, select-all-available, partial quantities, searchable serial selector, blocked list, totals and Simpan Draft.
- [x] 3.2 Update `create`/`store`: preparation service under the memo scope, server-side tax-bucket derivation for unsold lines without buckets, unchanged permissions and mutation gate.

## 4. Verification

- [x] 4.1 Feature tests: complete and partial selection, serial selection by number, local and remote destinations, blocked rows, back-navigation/old-input rehydration, totals, permissions and disabled-mutation behaviour.
- [x] 4.2 Update existing preparation-screen assertions to the new UI while keeping their protective intent; run the consignment buyout/regression suites.
- [x] 4.3 Validate the change with `openspec validate`; leave human UAT open and do not archive this or other changes.

## 5. Step 2 refinement: receipt → product → unsold/sold, serial checkboxes

- [x] 5.1 Read model: receipt/product/kind tree, per-location unsold groups with acquiring business, one-serial-once guard, settled (fully billed) summary, batched POS/Sales document references with permission-aware links and selling business.
- [x] 5.2 Proposal: one unsold row per receipt + location (no duplicate custody rows); return evidence reused inside the read-only scope.
- [x] 5.3 View: per-serial checkboxes, non-serialized quantity rows under the same hierarchy, global/receipt/product controls with indeterminate states, row-key rehydration preserved; remove the Select2 serial selector.
- [x] 5.4 Tests: grouping order, single serial checkboxes, document link permissions, POS preference with selling business, fully billed summary, remote location/owner groups without duplicates.
- [x] 5.5 Headless-browser check of rendered fixture pages (local serialized, non-serialized split, failed-save recovery, remote), and real-data read-model and query check for TOP IT / CANDRA. Browser login against the real data was not possible from this session (see testing.md).

## 6. Human acceptance (open)

- [ ] 6.1 Human UAT in a browser (logged in as a real user on TOP IT / CANDRA): walk the three steps with a local and a remote holding business, a serialized receipt (serial search), partial quantities, back-navigation and a failed save; confirm the review estimate against the saved draft's pricing.
