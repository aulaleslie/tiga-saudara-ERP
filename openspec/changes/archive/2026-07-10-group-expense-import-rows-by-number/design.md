## Context

Expense import currently stages each CSV row into `expense_import_rows`, then `ExpenseImportService::processBatch()` processes each pending row independently. `processRow()` validates the row, rejects nonzero `Tax`, creates one approved `expenses` row, creates one `expense_details` row, and marks the import row processed. Duplicate source-number protection is enforced by checking whether an Expense already exists with the same target `setting_id` and `imported_expense_number`; the database also has a unique constraint for that pair.

The source expense CSV files show a different source model: repeated `Nomor` values can represent a single source expense document with multiple detail lines. For example, `Expense-2020-S1.csv` has two rows for `Nomor = 10004`, each with a different description and amount. Those rows should become one Expense with two Expense Details. Some source rows also carry nonzero `Tax` values, but the target import should stay tax-neutral because there is no reliable source-tax-to-ERP-tax mapping.

## Goals / Non-Goals

**Goals:**
- Create one approved Expense per CSV `Nomor` group within an import batch.
- Create one Expense Detail per source row in that group.
- Use the first row in source order for header-level fields: date, category, and supplier fallback.
- Sum grouped row `Jumlah` values into the Expense header `amount`.
- Parse `Tax` values for data hygiene but ignore parseable tax amounts, including nonzero values.
- Preserve existing duplicate source-number protection for previously imported `Nomor` values.
- Keep batch `success_count` row-based so it still reflects processed source rows.

**Non-Goals:**
- Do not import source tax into `tax_id`, `is_tax_included`, Expense amount, or Expense Detail tax fields.
- Do not remove or relax the existing `(setting_id, imported_expense_number)` uniqueness model.
- Do not change the upload UI, import routes, permissions, staging table schema, or batch detail views unless existing labels need minor wording updates.
- Do not create payable/payment ledger records or change manual Expense approval behavior.
- Do not support partially paid groups; nonzero `Sisa Tagihan` remains invalid.

## Decisions

- **Group pending rows by trimmed `Nomor` before creating Expenses.**
  - Rationale: `Nomor` is the source document identity, while individual CSV rows are detail lines.
  - Alternative considered: keep one row as one Expense and allow duplicate import numbers. Rejected because it loses document grouping and introduces unnecessary duplicate-source complexity.

- **Use first row wins for header fields.**
  - The first row in `row_number` order determines date, category, supplier fallback, `details` summary fallback, and source identity for the Expense header.
  - Rationale: this is simple, stable, and matches the clarified rule that different categories under the same number should use the first category.
  - Alternative considered: reject groups where later rows differ in category or supplier. Rejected because the source can legitimately use later rows as detail-specific context.

- **Create details from every grouped row.**
  - Each source row creates one `expense_details` record with amount from that row's `Jumlah` and name from row `Deskripsi` when present, otherwise row `Kategori`.
  - Rationale: this preserves line-level context like `kertas A4`, `kertas F4`, `PPN`, and `PPH`.

- **Validate the group before writing any group records.**
  - Every row in the group must have `Transaksi = Expense`, `Status = Paid`, zero `Sisa Tagihan`, positive parseable `Jumlah`, parseable `Tax`, valid date, and a nonblank `Nomor`.
  - The first row must provide a nonblank `Kategori`; if not, the group is invalid.
  - Rationale: group processing should be atomic so a partially imported source document cannot be created.

- **Ignore parseable source tax.**
  - `Tax` remains parsed so malformed source files still surface an error, but any numeric value is discarded. Persisted imported expenses use `is_tax_included = false`, detail `tax_id = null`, and amounts based only on `Jumlah`.
  - Rationale: the source tax amount alone cannot reliably identify an ERP tax record, and the user wants import to continue while skipping the tax value.

- **Skip duplicate source-number groups already imported.**
  - If an Expense already exists for the target setting and the group `Nomor`, mark every row in that group skipped and do not create new records.
  - Rationale: the existing uniqueness model remains correct when one `Nomor` equals one imported Expense document.

- **Count success by source rows.**
  - When a group with N rows creates one Expense and N Expense Details, increment `success_count` by N.
  - Rationale: batch totals and import rows are row-oriented; processed row counts should reconcile with staged row counts.

## Risks / Trade-offs

- [Risk] A bad row in a repeated-`Nomor` group prevents all rows in that source document from importing. -> Mitigation: mark every affected row invalid with a clear group-level error so the operator can fix the source document consistently.
- [Risk] First-row category or supplier may hide category differences in later rows. -> Mitigation: detail names still preserve later row descriptions/categories; the first-row rule is explicit and deterministic.
- [Risk] Ignoring nonzero tax changes historical gross-vs-net expectations. -> Mitigation: imported amount remains the CSV `Jumlah` sum only, and tax is deliberately not imported until a reliable mapping exists.
- [Risk] Batch success counts can exceed created Expense count. -> Mitigation: keep counts row-based and verify created detail count in tests; this matches existing import-row accounting.

## Migration Plan

No schema migration is expected. The change is code and test only. Existing imported Expenses keep their current source identity. Rollback is restoring row-by-row processing and the previous nonzero-tax rejection rule.

## Open Questions

None. The clarified rule is: same `Nomor` becomes one imported Expense, first row determines header category/supplier/date, every row becomes a detail, parseable tax is ignored, and success count remains row-based.
