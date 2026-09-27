## 1. Conversion Price Presentation

- [x] 1.1 Confirm the shared POS unit-options payload exposes the acting business's nullable `price_for_setting` consistently for name search and base-barcode resolution, adjusting only the response mapping or contract documentation if needed.
- [x] 1.2 Update the POS unit-selection conversion cards to show a formatted `Harga konversi` value with the conversion unit label when a numeric price is present.
- [x] 1.3 Add the explicit missing-conversion-price state without changing conversion eligibility, selection submission, or cart pricing behavior.

## 2. Focused Verification

- [x] 2.1 Add or update focused POS search/scan assertions for configured business-scoped conversion prices, cross-business isolation, and nullable missing prices.
- [x] 2.2 Add a focused rendering assertion for the configured and missing-price conversion-card branches where practical, then run only the directly affected POS tests.
- [x] 2.3 Document a short human browser checklist covering configured, missing, and disabled conversion cards and confirm that final cart totals still follow existing pricing rules; leave browser execution to the human tester.
