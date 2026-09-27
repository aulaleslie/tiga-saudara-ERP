## 1. Implement Toast Helper Function

- [x] 1.1 Add `showToast(message, type, duration)` global function to `resources/js/app.js`
- [x] 1.2 Configure SweetAlert toast with correct position (top-end), duration (2000ms), and progress bar
- [ ] 1.3 Test toast function in browser console to verify it works for all types (success, error, warning, info)

## 2. Replace Alert Calls in Approval Queue

- [x] 2.1 Replace first `alert('Persetujuan berhasil disimpan.')` call (line 293) with `showToast('Persetujuan berhasil disimpan.', 'success')`
- [x] 2.2 Replace second `alert('Gagal menyetujui: ' + err.message)` call (line 297) with `showToast('Gagal menyetujui: ' + err.message, 'error')`
- [x] 2.3 Replace third `alert('Penolakan berhasil disimpan.')` call (line 333) with `showToast('Penolakan berhasil disimpan.', 'success')`
- [x] 2.4 Replace fourth `alert('Gagal menolak: ' + err.message)` call (line 336) with `showToast('Gagal menolak: ' + err.message, 'error')`

## 3. Verification and Testing

- [x] 3.1 Visit approval queue page in browser
- [x] 3.2 Approve a request and verify success toast appears and auto-closes
- [x] 3.3 Reject a request and verify success toast appears and auto-closes
- [x] 3.4 Test error handling (e.g., invalid request ID) and verify error toast appears and auto-closes
- [x] 3.5 Check browser console for any JavaScript errors
- [x] 3.6 Verify toast appears in top-right corner with appropriate icon and color
