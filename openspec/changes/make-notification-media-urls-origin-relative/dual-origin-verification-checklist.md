# Dual-Origin Browser Verification Checklist & Deployment Handoff

This checklist provides step-by-step instructions for a human operator or developer to manually verify origin-relative URLs across dual origins (LAN IP e.g. `http://192.168.1.x:8000` and Cloudflare Tunnel e.g. `https://erp.example.com`).

---

## 1. Pre-Verification Deployment Steps

Execute the following commands in the application root directory:

```bash
# 1. Clear application and configuration caches so the updated public disk URL is loaded
php artisan optimize:clear
php artisan config:clear

# 2. Restart background queue workers so newly queued jobs use the updated configuration
php artisan queue:restart
```

---

## 2. Notification Action URL Repair

### Step 2.1: Dry-Run / Preview
Inspect how many legacy notification records contain absolute URLs pointing to either your LAN host or Cloudflare host. This command runs in preview mode by default (no mutations):

```bash
# Inspect LAN and Cloudflare origin matches
php artisan notifications:repair-action-urls \
  --origin="https://192.168.1.24" \
  --origin="https://app.tiga-saudara.my.id"
```

Verify that:
- [ ] The command summary table reports the counts for "Already Origin-Relative", "Repairable Rows", and "Unrecognized External Origins".
- [ ] Unrecognized external origins (if any) are highlighted without altering any records.
- [ ] In preview mode (without `--apply`), the "Repaired Rows (persisted)" metric remains 0.
- [ ] The "Repairable Rows" count accurately reflects legacy notifications requiring normalization.

### Step 2.2: Apply Repair
Run the repair with `--apply`:

```bash
php artisan notifications:repair-action-urls \
  --origin="https://192.168.1.24" \
  --origin="https://app.tiga-saudara.my.id" \
  --apply
```

Verify that:
- [ ] Repaired count matches the preview candidate count.
- [ ] Notification read state, resolved state, and timestamps are unchanged.

---

## 3. Manual Browser Verification

Perform the following checks twice:
- **Origin A:** Local LAN access (`http://<LAN_IP>:<PORT>`)
- **Origin B:** Cloudflare Tunnel domain (`https://<CLOUDFLARE_DOMAIN>`)

### 3.1 Notifications (`/notifications`)
- [ ] Open the notifications dropdown or index page `/notifications`.
- [ ] Click on a notification item (e.g., purchase approval, stock alert).
- [ ] **Expectation:** The browser redirects to the destination on the *current origin* without switching to the other origin.
- [ ] Verify the URL remains on the current host/scheme.

### 3.2 User Avatar
- [ ] Navigate to user profile or observe the navigation bar avatar.
- [ ] Inspect the avatar `<img>` tag in Developer Tools.
- [ ] **Expectation:** `src` attribute begins with `/storage/...` (origin-relative) or fallback URL, loading successfully without cross-origin blocked content.

### 3.3 Product Image
- [ ] Navigate to the Products list or open a Product detail page (`/products/{id}`).
- [ ] Inspect the product thumbnail/image element.
- [ ] **Expectation:** `src` attribute begins with `/storage/...` and the image renders properly on both LAN and Cloudflare.

### 3.4 General / Document Attachment
- [ ] Navigate to an Expense detail page (`/expenses/{id}`) or Purchase detail page (`/purchases/{id}`).
- [ ] Check an existing attachment link or preview modal.
- [ ] **Expectation:** Attachment link resolves to `/storage/...` and downloads/displays in browser without cross-origin redirect.

### 3.5 POS Payment Image / Sale Payment Attachment
- [ ] Navigate to Sales / POS payments with transfer proofs (`/sale-payments`).
- [ ] Click to view the payment proof image.
- [ ] **Expectation:** Loads on the current origin without mixed-content warnings or CORS issues.

### 3.6 Return / Settlement Proof
- [ ] Navigate to Sales Return detail (`/sales-return/{id}`) or Purchase Return detail.
- [ ] In the Settlement section, click "Lihat Bukti" (`cash_proof_path` or settlement item `proof_path`).
- [ ] **Expectation:** The proof opens in a new tab via `/storage/...` under the current origin.
