# Human Browser Verification

Browser execution belongs to the human developer. This checklist is a handoff artifact; automated implementation verification is limited to focused backend/component tests (see `rollout.md` for the exact commands and results).

Use a disposable environment with representative good/broken, serialized/non-serialized and same/cross-business stock. Set `STOCK_TRANSFERS_V3_CREATION_ENABLED=true`, run migrations, and grant roles explicitly. Use accounts with create/edit/show, approval, receive, cancellation and history permissions separately, plus one end-to-end account. Do not use the production replica for destructive test actions.

## Routes and labels

| Surface | Route name | Permission | Expected labels |
| --- | --- | --- | --- |
| List | `transfers.index` | `stockTransfers.access` | send icon (Ajukan Persetujuan) on v3 DRAFT; diagram icon (Alokasi & Persetujuan) on v3 PENDING; no Archive |
| Create | `transfers.create` | `stockTransfers.create` | "Buat Transfer Stok", Barang Baik / Barang Rusak, Simpan Draf, Ajukan Persetujuan |
| Edit | `transfers.edit` | `stockTransfers.edit` | "Ubah Transfer Stok", condition shown read-only |
| Detail | `transfers.show` | `stockTransfers.show` | Daftar Barang (goods, quantities, serials); Terima Barang; Batalkan Pengiriman |
| Submit draft | `transfers.v3.submit` (POST) | `stockTransfers.edit` | redirects to detail |
| Approval workspace | `transfers.v3.approval` | `stockTransfers.approval` | Simpan Progres, Setujui dan Kirim, Tolak, + Tambah Sumber |
| Progress / review | `transfers.v3.approval.progress` (POST) | `stockTransfers.approval` | Simpan Progres → detail; Setujui dan Kirim → "Ringkasan Persetujuan & Pengiriman" modal |
| Approve | `transfers.v3.approve` (POST) | `stockTransfers.approval` | "Konfirmasi Setujui dan Kirim" |
| Reject | `transfers.v3.reject` (POST) | `stockTransfers.approval` | "Tolak Transfer", reason required |
| Back to draft | `transfers.v3.acknowledge-rejection` (POST) | `stockTransfers.edit` | "Kembalikan ke Draf untuk Revisi" |
| Receive | `transfers.v3.receive` (POST) | `stockTransfers.receive` | modal "Terima Barang": Batal / Konfirmasi Penerimaan |
| Cancel dispatch | `transfers.v3.cancel-dispatch` (POST) | `stockTransfers.cancel-dispatch` | modal "Batalkan Pengiriman", reason + confirmation checkbox |
| Timeline | detail section "Riwayat Transfer" | `stockTransfers.show` + `stockTransfers.view-history` | Dibuat, Diajukan untuk persetujuan, Progres alokasi disimpan, Disetujui, Dikirim, Diterima, Selesai, Pengiriman dibatalkan |

Receipt modal text (exact): "Pastikan seluruh barang telah dihitung dan jumlahnya sesuai dengan daftar pada dokumen ini. Dengan mengonfirmasi, Anda menyatakan seluruh barang telah diterima lengkap."

Cancellation modal text (exact): "Pastikan seluruh barang belum diserahkan atau telah dikembalikan ke lokasi asal sebelum membatalkan pengiriman. Stok akan dikembalikan ke lokasi asal sesuai pengiriman."

## Creation and submission

- Open creation and verify no source/destination controls or leaked location details; retain Barang Baik / Barang Rusak. Switching condition with rows entered asks for confirmation and clears rows.
- **Layout:** the main form shows the **Scan Barcode / Nomor Seri** input (focused on load) with a **Cari Produk** button. There is no inline search field.

**Search modal**
- Click **Cari Produk**. A large dialog titled "Cari Produk (Stok Dikelola)" opens and its search input has focus.
- Search by name, product code, barcode, category and brand, including multi-word terms. A product stocked only in another business appears, with no location or stock shown. Long result lists scroll inside the dialog.
- With exactly one result, nothing is added until you click **Pilih**. Pressing Enter in the search input only searches: it never selects a product, submits the transfer or closes the dialog.
- Selecting a product closes the dialog, adds one base unit (or a row with no serials selected yet for serialized products), and returns focus to the scan input.
- Close with **×**, with **Tutup** and with **Escape**. Each time, focus returns to the scan input.
- With the dialog open, fire a scanner read into the dialog's search input (or leave a scan request in flight from just before opening). Focus must stay in the dialog. Also type in a quantity field while a scan finishes: focus stays in the quantity field.

**Scanner terminators**
- Test with the scanner set to Enter/CR, CR+LF and (if supported) LF only. Each scan is processed exactly once, the page never submits or navigates, and the input is cleared and ready for the next scan.
- Type a product *name* into the scan input and press Enter: the red "tidak ditemukan" message appears and nothing is searched or offered.
- Unknown code (red), serial not eligible for the chosen condition (red) and the same serial twice ("sudah dipilih", amber): the input stays empty and focused.

**Ambiguity followed by more scans**
- Scan a barcode shared by two products, then immediately scan 3 more codes. A candidate list appears, and the status panel reads "Pindaian menunggu pilihan produk…". The 3 later scans are not applied yet and the list does not disappear.
- Choose a candidate. It is added once, then the 3 held scans apply in scan order.
- Repeat and click **Batalkan Pindaian** instead. Nothing from the ambiguous scan is added, and the held scans then apply in order.
- While a candidate list is open, try changing Barang Baik / Barang Rusak. The change is refused with a message.

**Save/submit during processing** (DevTools → Network → Slow 3G)
- Scan several codes quickly, then click **Simpan Draf** straight away. The panel shows "Menunggu seluruh pindaian selesai diproses sebelum menyimpan…" and the save runs only after every scan is applied, including the last ones. Repeat with **Ajukan Persetujuan**.
- With a candidate list open, click **Simpan Draf** or **Ajukan Persetujuan**. Neither runs, and the "masih ada pindaian yang menunggu pilihan produk" message appears.
- Queue scans that end in an ambiguous code, and click Simpan Draf while they are still processing. The save is cancelled with the "Penyimpanan dibatalkan…" message and does not run later on its own.
- Double-clicking Simpan Draf saves only once.

**Jumlah matches component state** (on the real create and edit pages, where the app layout's JS is loaded)
- Scan two distinct serials of one product (e.g. `NXDDESN001533033AA6L01` then `NXDDESN00153303C1E6L01`). **Jumlah** shows 1, then 2. The hint below shows "2 dari 2 nomor seri dipilih", and the input and the hint always agree.
- Scan the same serial again. The serial list, the hint and Jumlah stay at 2.
- Type 5 into Jumlah, click the scan input and scan two serials. Jumlah stays 5 and the hint shows "2 dari 5".
- Repeat the two-serial scan quickly and on Slow 3G. After the queue is idle, Jumlah matches the hint every time.
- Save a draft with one serial, open **Ubah** and scan a second serial. Jumlah shows 2.
- Edit Jumlah and press Tab, and edit it and click Simpan Draf directly. The saved quantity equals what you typed.
- Known trade-off: if a scan changes a row's quantity while you are still typing in that same row's Jumlah field, the field is re-rendered with the server value. Retype it after the scan.

**Overlapping operations**
- With an ambiguous list open, choose a candidate and immediately scan B, all on Slow 3G. The chosen product is added once, then B is added. B is never lost and the ambiguous code is never requested twice. Repeat with **Batalkan Pindaian** followed by an immediate scan.
- Click a candidate several times quickly, or a candidate and then Batalkan. Only one choice is applied.

**Scans during save/submit**
- On Slow 3G, click **Simpan Draf** with no scans pending, then scan while it is saving. The scan is refused with "…tidak diproses karena dokumen sedang disimpan. Pindai ulang…" and both buttons are disabled. After the redirect, that code is not in the document, as the message said.
- Trigger a save that fails validation (e.g. Ajukan Persetujuan with a serial-count mismatch). Once the error shows, scanning works again.

**Slow responses** (a custom throttling profile of about 30 s latency)
- Scan A, then B. After about 8 s the panel shows "Koneksi lambat: masih menunggu respons server untuk "A"…" with **no** retry or cancel buttons, and B is held. When A's response arrives, A is added once and B follows. Simpan Draf clicked meanwhile waits and then saves both.

**Server error, recoverable** (return HTTP 500/503 for `/livewire/update`, e.g. a DevTools override or a temporary `abort(503)` in a local copy)
- Scan A, then B and C. The red panel shows "Gagal memproses pindaian "A"…" with **Coba Lagi** and **Batalkan Pindaian**. Livewire's own error modal does **not** cover the page. B and C are held.
- Simpan Draf is refused with the "gagal diproses" message.
- Remove the error and click **Coba Lagi**. A is added exactly once, then B and C, in order. Repeat, but click **Batalkan Pindaian**: only A is discarded.
- Force a failure while choosing from an ambiguous list. **Coba Lagi** applies that same choice once, and **Batal** returns to the candidate list.
- Session expiry (419) still shows Livewire's standard "page expired" prompt.
- With **no** scan pending, make a quantity edit or modal search return 500. Livewire's standard error modal **does** appear (only scan and save failures are redirected to the retry panel).

**Network failure, fatal** (DevTools → Offline, then scan)
- The panel says the connection is lost, that the page must be reloaded, and lists the codes that were not recorded. There is no retry button. Further scans and Simpan Draf are refused with the reload message.
- Go offline, then **search in the Cari Produk modal** (or change a quantity and tab out), and only then scan. The reload warning appears as soon as the search or edit fails, and the scan is refused with the reload message. It must never sit on "Koneksi lambat…" indefinitely.
- Start a modal search on Slow 3G, go offline while it is in flight, and scan straight away. The scan is listed as unrecorded in the reload warning.
- Reload the page and confirm that unsaved rows are gone. This is a known Livewire 3.0.5 limitation: after a network failure its request queue stays blocked until reload.
- No failure is reported only in the browser console.
- Scan a serial that lives in another business: it is added without showing its location.
- Save a draft with incomplete serial selection ("x dari y nomor seri dipilih"); reopen via Ubah and complete it.
- Confirm Ajukan Persetujuan blocks serialized quantity/count disagreement on both creation and editing with the Bahasa Indonesia message.
- Create-and-submit directly, submit an existing draft from the list, and verify detail redirects.
- Confirm a material pending edit returns the document to Draf and invalidates the previously saved allocation plan (workspace starts empty after resubmission).

## Approval

- Group serials of one product from different businesses by actual source (one row per source, one destination each).
- Split a non-serialized product across several source locations and destinations with + Tambah Sumber; check Select2 searchable dropdowns and that source options show available totals (tax/non-tax breakdown only with Lihat Stok Sistem).
- Save incomplete progress, return to detail, resume approval and recover choices.
- Open two approval sessions; saving from the stale one is rejected, and a stale summary confirmation requires review.
- Inspect the final modal (sources, destinations, quantities, serials, Lintas Bisnis marker), then approve and verify immediate dispatch with no second preparation.
- Verify the end-to-end account can approve its own request.
- Verify Tolak requires a reason and cannot change goods.

## Receipt

- Verify receiver sees product quantities but no routing controls/details and no count-entry or scanning workflow.
- Open Terima Barang, inspect the Bahasa Indonesia confirmation, press Batal and verify the document stays Dikirim.
- Confirm complete receipt and verify document completion; repeat click/refresh and verify stock is not added twice.
- Verify receive permission suffices without separate receipt-approval permission.

## Cancellation

- On a separate dispatched/unreceived document, verify cancellation permission and required reason/confirmation.
- Inspect the physical-return confirmation before cancelling.
- Verify cancellation returns source stock, preserves unrelated intervening balances and prevents receipt/reuse.
- Verify a completed transfer cannot be cancelled.

## History and compatibility

- With history permission, inspect creation, submission, progress, approval/dispatch, receipt or cancellation events.
- Without history permission, verify no timeline; with history but no approval, verify no route allocation metadata (no "Rute Pengiriman", no revision evidence).
- Verify stock visibility without approval does not reveal route configuration.
- Verify no Archive button remains on list or detail.
- Inspect representative historical v1/v2 details, actions and return records.
- Verify the stock mutation report shows v3 dispatch/receipt/cancellation rows with the TSM document number.
- Record findings and screenshots as appropriate; no full-suite test run is required.

## Approval workspace layout (`transfers.v3.approval`)

Setup: rename two or three locations (and a business) to very long names, e.g. 60+ characters with the business prefix. Use a document with one non-serialized product and one serialized product, with serials in two locations. Test with the sidebar open.

- **Desktop, sidebar open:** in the non-serialized table, **Lokasi Sumber**, **Lokasi Tujuan**, **Jumlah** and the **×** remove button are all visible inside the card with no horizontal scrollbar. Long selected labels are cut off with "…", and hovering shows the full label.
- **Open searchable dropdown:** full location/business labels are readable (wrapped, not cut off) for both the source and destination selectors. Search still filters.
- **Source options** show only the location label, with no stock figures. After you pick a source, the line below it shows "Stok tersedia: N".
  - As an approver **with** Lihat Stok Sistem, it also shows "(pajak X, non-pajak Y)".
  - As an approver **without** it, only the total shows. View page source and confirm the `v3-stock-map` JSON has only `available` values.
  - Changing the source updates the line.
- **Tambah Sumber:** add two rows. Each new row has the same column widths, an empty source/destination/quantity, an empty stock line that fills in once a source is picked, and working dropdowns. Remove a middle row, then the last remaining row (which is cleared rather than removed, including its stock line).
- **Saved selections:** click Simpan Progres with several rows and reopen the workspace. Sources, destinations and quantities are restored, and each restored row shows its stock line.
- **Reduced viewport** (about the width of the reported screenshot, and a phone-width ~400px): only the table area scrolls sideways. The page itself never scrolls horizontally, and every control can be reached.
- **Serialized groups:** long source names and long serial numbers wrap within their cells, and the destination selector stays visible.
- **Summary modal (Setujui dan Kirim):** long product, location and serial values wrap. On a narrow screen the table scrolls inside the modal, and the confirm buttons stay visible.
- **Unchanged:** validation messages, Simpan Progres, Setujui dan Kirim, Tolak, and the v1/v2 transfer screens and other Select2 dropdowns elsewhere in the app look as before.

## Approval summary on `?review=1`

Use a disposable environment. Do **not** confirm approval on transfer 3 or on the production replica.

- **Normal load:** fill in allocations and click **Setujui dan Kirim**. The page reloads at `…/v3/approval?review=1` and the "Ringkasan Persetujuan & Pengiriman" modal opens by itself. The browser console shows no `modal is not a function` error.
- **Slow asset loading:** in DevTools, throttle to Slow 3G (or add latency to the Vite dev server/`app.js` request) and reload `?review=1`. The modal still opens once the page finishes loading, with no error.
- **Asset failure:** in DevTools → Network, block `resources/js/app.js` (or the built `app-*.js`) and reload `?review=1`. No modal appears. Instead, a yellow notice "Jendela ringkasan tidak dapat dibuka…" is shown above the same summary table, with **Batal** and **Konfirmasi Setujui dan Kirim**, and focus moves to it. **Batal** returns to the workspace without approving. With JavaScript disabled, the same inline summary is visible.
- **Cancel and reopen:** close the modal with **Batal**, with **×**, and with Escape. The transfer stays PENDING (check the detail page). Click **Lihat Ringkasan Persetujuan** and the modal opens again. Repeat a few times.
- **Confirm (disposable data only):** click **Konfirmasi Setujui dan Kirim** once. The transfer becomes DISPATCHED. Going back and resubmitting the same summary does not dispatch twice.
- **Stale summary:** open `?review=1` in two tabs, change and save the allocation in one tab, then confirm in the other. The approval is rejected as stale.
- **Tolak:** on the workspace and on `?review=1` (after closing the summary), **Tolak** opens the reject modal. **Batal** closes it, an empty reason is blocked, and a reason submits the rejection.
- **Validation errors:** with an incomplete allocation, `?review=1` shows the error list and no **Konfirmasi Setujui dan Kirim** button, in both the modal and the fallback (block `app.js` to see the fallback). The fallback notice tells you to click **Batal** to fix the allocation.
