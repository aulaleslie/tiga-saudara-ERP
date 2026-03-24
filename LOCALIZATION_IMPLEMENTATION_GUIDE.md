# Localization Implementation Guide

**Approach:** Direct string replacement (no Laravel localization helpers)

---

## Quick Start

### What You Have
1. **ENGLISH_STRINGS_INVENTORY.md** - Complete detailed inventory with file paths and line numbers
2. **ENGLISH_STRINGS_CHECKLIST.csv** - Spreadsheet-ready checklist with all strings, status tracking
3. **This guide** - Implementation strategy and execution steps

### Three Implementation Phases

---

## PHASE 1: CRITICAL (HIGHEST IMPACT)
**Estimated Time:** 2-3 hours
**Files to Update:** 4 main service files
**Strings to Replace:** 43 critical exception messages

### Phase 1.1: POS Checkout Finalization Service
**File:** `/Modules/Pos/Services/FinalizePosCheckoutService.php`
**Strings:** 13 exception messages
**Impact:** HIGH - Checkout workflow validation

Steps:
1. Open file in editor
2. Use Find & Replace for each string (see ENGLISH_STRINGS_INVENTORY.md lines 64-530)
3. Run tests: `php artisan test Modules/Pos/Tests/Feature/POS*`
4. Verify no errors in checkout flow

**Estimated Time:** 30 minutes

---

### Phase 1.2: POS Cart Service
**File:** `/Modules/Pos/Services/PosCartService.php`
**Strings:** 13 exception messages
**Impact:** HIGH - Cart operations validation

Steps:
1. Open file in editor
2. Use Find & Replace for each string (see ENGLISH_STRINGS_INVENTORY.md lines 57-542)
3. Run tests: `php artisan test Modules/Pos/Tests/Feature/POSCart*`
4. Verify cart add/remove/update operations work

**Estimated Time:** 30 minutes

---

### Phase 1.3: POS Inventory/Stock Posting Adapter
**File:** `/Modules/Pos/Services/Adapters/InlinePosCheckoutPostingAdapter.php`
**Strings:** 10 exception messages
**Impact:** HIGH - Stock availability validation

Steps:
1. Open file in editor
2. Use Find & Replace for each string (see ENGLISH_STRINGS_INVENTORY.md lines 37-275)
3. Run tests: `php artisan test Modules/Pos/Tests/Feature/POS*Posting*`
4. Verify stock checks work correctly

**Estimated Time:** 20 minutes

---

### Phase 1.4: POS Session Finalization Service
**File:** `/Modules/Pos/Services/PosSessionFinalizeService.php`
**Strings:** 4 exception messages
**Impact:** HIGH - Session closure with variance approval

Steps:
1. Open file in editor
2. Replace supervisor approval messages (see ENGLISH_STRINGS_INVENTORY.md lines 126-151)
3. Test session finalization with variance
4. Test supervisor approval flow

**Estimated Time:** 15 minutes

---

### Phase 1.5: Authentication Controller
**File:** `/app/Http/Controllers/Auth/LoginController.php`
**Strings:** 5 messages
**Impact:** CRITICAL - User-facing login flow

Steps:
1. Open file in editor
2. Replace account deactivation, success, and error messages (see ENGLISH_STRINGS_INVENTORY.md lines 50-113)
3. Test login flow:
   - Valid credentials
   - Invalid credentials
   - Deactivated account
4. Test API login response messages

**Estimated Time:** 15 minutes

---

## PHASE 2: HIGH PRIORITY (USER EXPERIENCE)
**Estimated Time:** 1-2 hours
**Files to Update:** 5 secondary files
**Strings to Replace:** 15 user-facing messages

### Phase 2.1: POS Controllers Authorization Messages
**Files:**
- `/Modules/Pos/Http/Controllers/PosSellController.php` (3 messages)
- `/Modules/Pos/Http/Controllers/PosSessionController.php` (3 messages)

Steps:
1. Open each file
2. Replace abort(403) messages with Indonesian (see ENGLISH_STRINGS_INVENTORY.md sections 5)
3. Test unauthorized access scenarios
4. Verify proper error messages shown

**Estimated Time:** 20 minutes

---

### Phase 2.2: Livewire Component Messages
**Files:**
- `/app/Livewire/Barcode/ProductTable.php` (2 messages)
- `/app/Livewire/Transfer/TransferProductTable.php` (1 message)
- `/app/Livewire/Adjustment/ProductTable.php` (1 message)

Steps:
1. Open each file
2. Replace session()->flash() messages
3. Test each component's validation:
   - Add duplicate product
   - Exceed quantity limits
4. Verify flash messages appear in Indonesian

**Estimated Time:** 20 minutes

---

### Phase 2.3: Validation Request
**File:** `/Modules/Pos/Http/Requests/StorePosSessionCloseRequest.php`

Steps:
1. Replace validation failure message
2. Test by submitting invalid data
3. Verify error response shows Indonesian message

**Estimated Time:** 10 minutes

---

## PHASE 3: MEDIUM PRIORITY (NICE TO HAVE)
**Estimated Time:** 1-2 hours
**Files to Update:** 3 files
**Strings to Replace:** 5 messages

### Phase 3.1: Search Controller Error Messages
**Files:**
- `/app/Http/Controllers/GlobalPurchaseAndSalesSearchController.php`
- `/Modules/Sale/Http/Controllers/GlobalSalesSearchController.php`

Steps:
1. Replace search error messages
2. Test search functionality with errors
3. Verify Indonesian error messages display

**Estimated Time:** 15 minutes

---

### Phase 3.2: Form Labels & UI Text
**File:** `/Modules/Pos/Resources/views/session/_finalize-modal.blade.php`

Review the following (most are already Indonesian):
- "Email Supervisor" → Consider: "Email Supervisor" (international convention) or "Email Supervisor (Internasional)"
- "Password/PIN" → "Kata Sandi/PIN"

Steps:
1. Check if translation needed
2. Update only if different from English
3. Test session finalization modal display

**Estimated Time:** 10 minutes

---

### Phase 3.3: Remaining JavaScript Alerts
**File:** `/Modules/User/Resources/views/users/create.blade.php`

Steps:
1. Replace user management alert
2. Test role selection flow
3. Verify Indonesian message displays

**Estimated Time:** 5 minutes

---

## Execution Workflow

### Before You Start
- Create a new branch: `git checkout -b feat/localize-english-strings`
- Make sure you have the ENGLISH_STRINGS_INVENTORY.md open
- Have the ENGLISH_STRINGS_CHECKLIST.csv open for tracking

### For Each File:
1. **Open the file** in your editor
2. **Find & Replace** each string from the inventory:
   - Use Ctrl+H (Find & Replace)
   - Paste the English string in "Find"
   - Paste the Indonesian string in "Replace"
   - **IMPORTANT:** Use "Replace" (not "Replace All") to verify context
3. **Save the file**
4. **Update the CSV** - mark the row as "COMPLETED"

### After Each Phase:
1. Run relevant tests: `php artisan test`
2. Check git diff: `git diff` to verify changes
3. Commit your work: `git add .` then `git commit -m "..."`

### Final Verification:
1. Run full test suite: `php artisan test`
2. Test POS workflow end-to-end
3. Test auth flow with all scenarios
4. Verify error messages show Indonesian

---

## Testing Strategy

### Automated Tests to Run

```bash
# POS Tests
php artisan test Modules/Pos/Tests/Feature/POSCheckout*
php artisan test Modules/Pos/Tests/Feature/POS*Cart*
php artisan test Modules/Pos/Tests/Feature/POS*Session*
php artisan test Modules/Pos/Tests/Feature/POS*Payment*

# Auth Tests
php artisan test --filter LoginController

# All Tests
php artisan test
```

### Manual Testing Checklist

After completing all phases:
- [ ] Login with valid credentials → "Login berhasil" message
- [ ] Login with invalid credentials → "Kredensial tidak valid" message
- [ ] Try to access POS without session → "Konteks sesi POS yang aktif diperlukan."
- [ ] Add product to cart → Works in Indonesian
- [ ] Remove product from cart → Works in Indonesian
- [ ] Checkout → "Keranjang harus berisi setidaknya satu item baris." if empty
- [ ] Finalize session → "Sesi POS harus DIBUKA untuk menyelesaikan checkout." if closed
- [ ] Add duplicate in product table → "Sudah ada dalam daftar produk!"
- [ ] Barcode generation → "Kuantitas maksimal adalah 100 per pembuatan barcode!" when exceeded
- [ ] Search → "Pencarian gagal. Silakan coba lagi." on error

---

## Troubleshooting

### Issue: Tests fail after replacement
**Solution:**
- Check that you replaced the exact string (watch for quotes, punctuation)
- Verify context - the string should be in a message/error context
- Compare with ENGLISH_STRINGS_INVENTORY.md

### Issue: String not found when searching
**Solution:**
- Copy the exact string from ENGLISH_STRINGS_INVENTORY.md
- Check if the line number is still correct (code may have changed)
- Use Ctrl+G to go to line number first

### Issue: Users still see English message
**Solution:**
- Clear application cache: `php artisan cache:clear`
- Check if there's a view cache: `php artisan view:clear`
- Restart the application server

---

## Progress Tracking

Use ENGLISH_STRINGS_CHECKLIST.csv to track your progress. Columns:
- **Priority** - CRITICAL, HIGH, MEDIUM, LOW
- **File Path** - Where to find the string
- **Line** - Approximate line number
- **Category** - Type of message
- **English String** - What to find
- **Indonesian Replacement** - What to replace with
- **Status** - Update to "COMPLETED" when done

### Recommended Progress:
- Day 1: Complete all CRITICAL items (Phase 1)
- Day 2: Complete all HIGH items (Phase 2)
- Day 3: Complete MEDIUM items (Phase 3)
- Day 4: Testing and verification

---

## Rollback Plan

If something goes wrong:
```bash
# Undo all changes
git checkout -- .

# Or undo specific file
git checkout -- path/to/file.php

# Or use git reflog to undo last commit
git reflog
git reset --hard <commit-hash>
```

---

## Notes & Best Practices

1. **Use Find & Replace Carefully**
   - Always use "Replace" (singular) not "Replace All"
   - Verify context before replacing
   - Some strings appear multiple times - replace all instances in that file

2. **Watch for Quote Types**
   - Single quotes: `'String here'`
   - Double quotes: `"String here"`
   - Make sure to include the correct quote type in search

3. **Preserve Error Codes**
   - Some messages have error codes: `'ERROR_CODE', 'Message'`
   - Only replace the message part, not the code
   - Example: `'PAYMENT_INVALID', 'Old message'` → `'PAYMENT_INVALID', 'Pesan baru'`

4. **Dynamic Strings**
   - Some strings have variables: `'Error: ' . $e->getMessage()`
   - Replace the constant part only: `'Gagal: ' . $e->getMessage()`
   - Don't replace variables like `{$amount}` or `{:value}`

5. **HTML & Formatting**
   - Preserve HTML tags: `<br>`, `<strong>`, etc.
   - Preserve newlines and formatting
   - Example: `"Line 1\nLine 2"` - keep the `\n`

6. **Testing Each Change**
   - After Phase 1: Run checkout tests
   - After Phase 2: Run auth + component tests
   - After Phase 3: Run full test suite

---

## Summary Table

| Phase | Time | Files | Strings | Priority | Status |
|-------|------|-------|---------|----------|--------|
| 1 | 2-3h | 5 | 43 | CRITICAL | PENDING |
| 2 | 1-2h | 5 | 15 | HIGH | PENDING |
| 3 | 1-2h | 3 | 5 | MEDIUM | PENDING |
| **Total** | **4-7h** | **13** | **63** | **Mixed** | **PENDING** |

---

## Support Resources

- **Inventory Details:** See ENGLISH_STRINGS_INVENTORY.md (sections 1-12)
- **Checklist:** See ENGLISH_STRINGS_CHECKLIST.csv (for tracking)
- **File Locations:** All files have absolute paths starting with `/home/aulaleslie/Workspace/Rahmat/tiga-saudara-ERP`

---

**Status:** Ready to implement
**Last Updated:** March 25, 2026
**Approach:** Direct string replacement
