## 1. Global Adjustment Routing and Authorization

- [x] 1.1 Add dedicated Global Purchase Payment and Global Sales Payment routes for monetary edit entry/save and combined date adjustment, protected by the applicable global-access permission.
- [x] 1.2 Add a server-authoritative global adjustment context that resolves each document's actual setting while preserving the active-setting guards on every normal edit and date-adjustment route.
- [x] 1.3 Compose global access with the existing ordinary edit, lifecycle monetary-edit, reporting-date, and due-date permissions, including independent authorization of each date field and the existing Super Admin bypass.

## 2. Cross-Setting Monetary Adjustment

- [x] 2.1 Refactor or reuse purchase monetary edit orchestration so the dedicated global route can open and save only `MONETARY_ONLY` mode using the purchase's actual setting context without duplicating monetary persistence rules.
- [x] 2.2 Refactor or reuse sale monetary edit orchestration so the dedicated global route can open and save only `MONETARY_ONLY` mode using the sale's actual setting context without duplicating monetary persistence rules.
- [x] 2.3 Add server-selected Global Payment return handling that returns to global detail when still available and otherwise falls back to the matching global index with success feedback.

## 3. Global Detail Actions and Date Adjustment

- [x] 3.1 Render `Ubah Nilai (Moneter)` on eligible purchase and sale Global Payment details only when the resolved mode and complete permission set allow it.
- [x] 3.2 Make the shared combined date-adjustment UI global-context aware, submit to the dedicated global endpoint, and expose only the individually authorized reporting-date and due-date controls.
- [x] 3.3 Keep full edit, approval, receiving/dispatch, correction, archive, delete, duplication, and attachment-management controls absent from both Global Payment detail pages.

## 4. Focused Verification

- [x] 4.1 Add focused purchase feature coverage for action visibility, missing-permission denial, cross-setting monetary/date saves, actual-setting behavior, redirect fallback, and unchanged normal-route isolation.
- [x] 4.2 Add focused sale feature coverage for action visibility, missing-permission denial, cross-setting monetary/date saves, actual-setting behavior, redirect fallback, and unchanged normal-route isolation.
- [x] 4.3 Update the existing Global Payment detail assertions that intentionally hid date adjustment, while retaining regression assertions that unrelated mutation controls remain unavailable.
- [x] 4.4 Run only the touched Global Payment, monetary-edit authorization, reporting-date, and due-date focused tests plus directly affected purchase/sale detail regressions.
