# Master Data Deactivation & Lifecycle Inventory

## 1. Master Data Entities Covered

| Master Entity | Table | Existing Lifecycle Field | Target Lifecycle Field | Delete Route | Primary Permission |
|---|---|---|---|---|---|
| **Product** | `products` | `merged_into_id` (merge retirement only) | `is_active` (boolean, default true) | `DELETE products/{product}` | `products.edit` / `products.delete` |
| **Customer** | `customers` | None | `is_active` (boolean, default true) | `DELETE customers/{customer}` | `customers.edit` / `customers.delete` |
| **Supplier** | `suppliers` | None | `is_active` (boolean, default true) | `DELETE suppliers/{supplier}` | `suppliers.edit` / `suppliers.delete` |
| **Tax** | `taxes` | `is_default` (boolean) | `is_active` (boolean, default true) | `DELETE taxes/{tax}` | `taxes.edit` / `taxes.delete` |
| **Payment Method** | `payment_methods` | None | `is_active` (boolean, default true) | `DELETE payment-methods/{payment_method}` | `paymentMethods.edit` / `paymentMethods.delete` |
| **Payment Term** | `payment_terms` | None | `is_active` (boolean, default true) | `DELETE payment-terms/{payment_term}` | `paymentTerms.edit` / `paymentTerms.delete` |
| **Location** | `locations` | None (`setting_sale_locations.is_enabled` exists for POS sales channel) | `is_active` (boolean, default true) | `DELETE locations/{location}` | `locations.edit` |
| **Unit** | `units` | None | `is_active` (boolean, default true) | `DELETE units/{unit}` | `units.edit` / `units.delete` |
| **Chart of Account** | `chart_of_accounts` | None | `is_active` (boolean, default true) | `DELETE chart-of-account/{chart_of_account}` | `chartOfAccounts.edit` / `chartOfAccounts.delete` |

---

## 2. Per-Master Default Handling and Structural Deactivation Guards

### 2.1 Default Handling
1. **Tax (`taxes`)**:
   - Deactivating a tax marked with `is_default = true` is blocked unless another active tax is set as default first, OR the default flag is transferred to another active tax.
2. **Payment Term (`payment_terms`)**:
   - Fallback terms (such as `Cash on Delivery` / `COD` or `longevity = 0`) are guarded. If a default COD term is deactivated, operational resolvers must fall back to the first available active term.
3. **Location (`locations`)**:
   - Deactivating a business's only standard location or active sales location is guarded. At least one active standard location must remain available per business to prevent empty warehouse operational states.
4. **Payment Method (`payment_methods`)**:
   - Cash payment method linked to primary cash account should be guarded against deactivation if no alternative cash method is active.

### 2.2 Structural Deactivation Guards
1. **Chart of Accounts (`chart_of_accounts`)**:
   - Accounts that have active child accounts cannot be deactivated until child accounts are deactivated or reassigned.
   - Accounts linked to active Payment Methods (`payment_methods.coa_id`) cannot be deactivated while the payment method is active.
2. **Product (`products`)**:
   - Products that are active components of active bundles or parent bundle products are warned/guarded, but `is_active = false` does not alter `merged_into_id` lineage.
3. **Location (`locations`)**:
   - Locations with non-zero stock (available, tax, non-tax, broken) trigger explicit confirmation or guard upon deactivation (and are prevented from destructive delete). Deactivated locations cannot receive new stock transfers, purchases, or sales.

---

## 3. Backward-Compatible Permission Mapping

To preserve existing role assignments without breaking legacy setups:
- Deactivation and Reactivation actions check `*.edit` OR `*.delete` permissions for each respective domain:
  - `products`: `products.edit` or `products.delete`
  - `customers`: `customers.edit` or `customers.delete`
  - `suppliers`: `suppliers.edit` or `suppliers.delete`
  - `taxes`: `taxes.edit` or `taxes.delete`
  - `paymentMethods`: `paymentMethods.edit` or `paymentMethods.delete`
  - `paymentTerms`: `paymentTerms.edit` or `paymentTerms.delete`
  - `locations`: `locations.edit`
  - `units`: `units.edit` or `units.delete`
  - `chartOfAccounts`: `chartOfAccounts.edit` or `chartOfAccounts.delete`
- Legacy HTTP `DELETE` routes will execute safe deactivation (or return error if deactivation is guarded) rather than physically deleting master records.
