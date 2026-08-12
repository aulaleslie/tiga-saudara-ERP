## 1. Refresh-Safe Action Menu Recovery

- [x] 1.1 Add stable document keys to Sales list rows and refresh-sensitive identities to the Purchase and Sales action-menu roots.
- [x] 1.2 Update the existing Alpine action-menu partials so a Livewire search, clear, sort, filter, or page refresh creates a fresh menu instance for each current result.
- [x] 1.3 Add scoped, idempotent Bootstrap/CoreUI dropdown restoration for Global Purchase Payment action controls after its Livewire table refresh.
- [x] 1.4 Confirm the existing action links, status checks, and authorization conditions remain unchanged in normal and global modes.

## 2. Verification

- [x] 2.1 Identify and use the project-supported browser test runner, or add focused browser coverage if the runner is already available.
- [x] 2.2 Add a regression scenario for normal Purchase and Sales: search a matching document, open its three-dot menu, and assert an authorized action is visible.
- [x] 2.3 Add a regression scenario for Global Purchase Payment and Global Sales Payment: search a matching document, open its three-dot menu, and assert a permitted payment action is visible.
- [x] 2.4 Verify a follow-up search change or clear-search leaves actions associated with the currently displayed result and run the focused relevant test suite.
