# Tasks: POS UX Refinement

## Research and Audit
- [x] Identify all `confirm()` and `prompt()` calls in `Modules/Pos` <!-- id: 0 -->
- [x] Map `renderCart` snapshot structure for button logic <!-- id: 1 -->

## Implementation - POS Sell Page
- [x] Replace `window.confirm` in `ApprovalManager.wrapAction` <!-- id: 2 -->
- [x] Replace `window.prompt` in `ApprovalManager.requestApproval` <!-- id: 3 -->
- [x] Add `canClear` logic to `renderCart` <!-- id: 4 -->

## Implementation - Management Views
- [x] Replace `confirm()` in Terminal Management (`terminals/index.blade.php`) <!-- id: 5 -->
- [x] Replace `confirm()` in Transaction History (`transactions/index.blade.php`) <!-- id: 6 -->
- [x] Replace `confirm()` in Transaction Details (`transactions/show.blade.php`) <!-- id: 7 -->

## Quality Assurance
- [x] Verify modal styling matches theme <!-- id: 8 -->
- [x] Test button disabled state with various cart/customer combinations <!-- id: 9 -->
- [x] Verify approval requests still work with the new prompt modal <!-- id: 10 -->
