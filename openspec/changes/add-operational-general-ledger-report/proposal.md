## Why

Users need a Buku Besar report that matches the familiar Jurnal-style report shape, but this ERP does not yet have complete chart-of-account journal posting for operational modules. The report should therefore provide a practical transaction-derived ledger view now, using the same operational-data stance already used by Neraca.

## What Changes

- Add an accessible **Buku Besar** report under Reports > Sekilas bisnis for users with `reports.access`.
- Generate the report from operational transactions instead of COA journal balances.
- Support date range filtering with a default period of today.
- Group operational movement rows by derived financial bucket, preserving the Buku Besar name while avoiding account-code/COA claims.
- Show beginning balance, period debit, period credit, running balance, and ending balance where supported by operational data.
- Include a clear source note that the report is calculated from operational transactions and does not yet use accounting journals or COA posting.
- Add XLSX export using the same rows and filters as the on-screen report.
- Convert the current Buku Besar reports landing placeholder into an active report link.

## Capabilities

### New Capabilities
- `operational-general-ledger-report`: Provides the Buku Besar report calculated from operational sales, purchases, returns, payments, expenses, and supported transaction buckets.

### Modified Capabilities
- `reports-landing-navigation`: Change the Buku Besar card from a placeholder into an active report link while preserving permission gating.

## Impact

- Affected code areas: Reports landing configuration, Reports routes/controller views, Livewire report component, report calculation service/value objects, XLSX export, and focused feature tests.
- Data sources: sales, sale payments, sales returns, sales return payments, purchases, purchase payments, purchase returns, purchase return payments, approved expenses, and current setting/currency context.
- No database schema change is expected.
- No general ledger posting, chart-of-account hierarchy, journal backfill, or historical transaction rewrite is included.
