<div x-data="productCart()" x-init="init()">
    <div class="table position-relative">
        <!-- Loading Overlay -->
        <div x-show="loading" class="col-12 position-absolute justify-content-center align-items-center"
             style="top:0;right:0;left:0;bottom:0;background-color: rgba(255,255,255,0.5);z-index: 99;">
            <div class="spinner-border text-primary" role="status">
                <span class="sr-only">Loading...</span>
            </div>
        </div>

        <table class="table table-bordered" style="table-layout: fixed;">
            <colgroup>
                <col style="width: 20%;">
                <col style="width: 12%;">
                <col style="width: 8%;">
                <col style="width: 12%;">
                <col style="width: 15%;">
                <col style="width: 13%;">
                <col style="width: 10%;">
                <col style="width: 10%;">
                <col style="width: 10%;">
            </colgroup>
            <thead class="thead-dark">
            <tr>
                <th class="align-middle">Produk</th>
                <th class="align-middle text-center">Harga Beli</th>
                <th class="align-middle text-center">Stok</th>
                <th class="align-middle text-center">Jumlah</th>
                <th class="align-middle text-center">Diskon</th>
                <th class="align-middle text-center">Pajak</th>
                <th class="align-middle text-center">Sub Total Sebelum Pajak</th>
                <th class="align-middle text-center" style="width: 15%;">
                    Total Baris
                    <span class="d-inline-block"
                          data-toggle="tooltip"
                          data-placement="top"
                          title="Total setelah diskon baris, termasuk pajak, sebelum diskon global dan ongkos kirim.">
                        <i class="bi bi-info-circle text-primary" style="cursor: pointer; font-size: 0.8rem;"></i>
                    </span>
                </th>
                <th class="align-middle text-center">Aksi</th>
            </tr>
            </thead>
            <tbody>
            <template x-for="(item, index) in cart" :key="item.id">
                <tr>
                    <td class="align-middle text-break">
                        @can('products.manage_cross_business_prices')
                            <a :href="'{{ route('products.cross-business-prices.edit', 'ID_PLACEHOLDER') }}'.replace('ID_PLACEHOLDER', item.product_id)" target="_blank" class="text-primary font-weight-bold" x-text="item.product_name" title="Manage Cross-Business Prices"></a> <br>
                        @else
                            <strong x-text="item.product_name"></strong> <br>
                        @endcan
                        <span class="badge badge-success" x-text="item.product_code"></span>
                    </td>

                    <td class="align-middle text-right">
                        <input
                            type="text"
                            class="form-control text-right"
                            x-model="item.unit_price_display"
                            @focus="item.unit_price_display = toPlainNumber(item.unit_price)"
                            @blur="formatUnitPrice(index)"
                            @input="handleUnitPriceInput(index, $event.target.value)"
                            inputmode="decimal"
                        >
                    </td>

                    <td class="align-middle text-right">
                        <span class="badge badge-info" x-text="item.stock + ' ' + item.base_unit_name"></span>
                    </td>

                    <td class="align-middle text-right">
                        <div class="input-group input-group-sm">
                            <input
                                type="number"
                                step="0.001"
                                min="0.001"
                                class="form-control text-right"
                                x-model="item.quantity"
                                @input="updateItem(index)"
                            >
                            <select
                                class="form-control form-control-sm ml-1"
                                style="max-width: 100px;"
                                x-model="item.selected_unit_key"
                                @change="changeItemUnit(index, $event.target.value)"
                            >
                                <option value="base" x-text="item.base_unit_name"></option>
                                <template x-for="conv in (item.conversions || [])" :key="conv.id">
                                    <option :value="conv.id" x-text="conv.unit_name"></option>
                                </template>
                            </select>
                        </div>
                        <div class="text-muted small mt-1 text-right" x-text="formatQuantityBreakdown(item)"></div>
                    </td>

                    <td class="align-middle text-center position-relative">
                        <div class="input-group input-group-sm" style="max-width: 180px;">
                            <button class="btn btn-outline-secondary btn-sm dropdown-toggle px-3"
                                    type="button" data-toggle="dropdown" aria-expanded="false"
                                    style="font-size: 0.875rem; min-width: 50px;">
                                <span x-text="item.discount_type == 'percentage' ? '%' : 'Rp'"></span>
                            </button>
                            <ul class="dropdown-menu">
                                <li><a class="dropdown-item" href="#" @click.prevent="setDiscountType(index, 'fixed')">Rp</a></li>
                                <li><a class="dropdown-item" href="#" @click.prevent="setDiscountType(index, 'percentage')">%</a></li>
                            </ul>
                            <input
                                type="number"
                                class="form-control form-control-sm text-right"
                                x-model="item.discount"
                                @input="updateItem(index)"
                                :max="item.discount_type == 'percentage' ? 100 : undefined"
                                min="0"
                                step="0.01"
                            >
                        </div>
                    </td>

                    <td class="align-middle text-center">
                        <div class="d-flex flex-column gap-1">
                            <select
                                class="form-control form-control-sm"
                                x-model="item.tax_id"
                                @change="updateItem(index)"
                            >
                                <option value="" :disabled="isPkp">{{ ($isPkp ?? false) ? 'Wajib Pilih Pajak' : 'Non Pajak' }}</option>
                                <template x-for="tax in taxes" :key="tax.id">
                                    <option :value="tax.id" x-text="tax.name + ' (' + tax.value + '%)'"></option>
                                </template>
                            </select>
                            <a href="#" class="small" @click.prevent="$dispatch('open-tax-modal')">Tambah pajak baru</a>
                        </div>
                    </td>

                    <td class="align-middle text-center">
                        <span x-text="formatCurrency(item.subtotalBeforeTax)"></span>
                    </td>

                    <td class="align-middle text-right">
                        <input
                            type="text"
                            class="form-control text-right"
                            :class="{ 'is-invalid': item.validationError }"
                            x-model="item.total_display"
                            @focus="item.total_display = toPlainNumber(item.total)"
                            @blur="formatLineTotal(index)"
                            @input="handleLineTotalInput(index, $event.target.value)"
                            inputmode="decimal"
                        >
                        <div x-show="item.validationError" class="invalid-feedback d-block mt-1" x-text="item.validationError"></div>
                    </td>

                    <td class="align-middle text-center">
                        <button type="button" class="btn btn-sm btn-danger" @click="removeItem(index)">
                            <i class="bi bi-x-circle"></i>
                        </button>
                    </td>
                </tr>
            </template>

            <tr x-show="cart.length === 0">
                <td colspan="9" class="text-center">
                    <span class="text-danger">Silahkan cari dan pilih produk!</span>
                </td>
            </tr>
            </tbody>
        </table>
    </div>

    <!-- Cart Totals -->
    <div class="row justify-content-md-end" x-show="cart.length > 0">
        <div class="col-md-4">
            <div class="table-responsive">
                <table class="table table-striped">
                    <tr>
                        <th>Termasuk Pajak</th>
                        <td>
                            <div class="form-check">
                                <input
                                    type="checkbox"
                                    class="form-check-input"
                                    x-model="isTaxIncluded"
                                    @change="updateAllItems()"
                                >
                                <label class="form-check-label">Termasuk Pajak</label>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th>Total Sebelum Pajak</th>
                        <td x-text="formatCurrency(totals.subtotalBeforeTax)"></td>
                    </tr>
                    <tr>
                        <th>Pajak (%)</th>
                        <td x-text="formatCurrency(totals.taxTotal)"></td>
                    </tr>
                    <tr>
                        <th>Total Setelah Pajak</th>
                        <td x-text="formatCurrency(totals.subtotal)"></td>
                    </tr>
                    <tr>
                        <th>Diskon Global</th>
                        <td x-text="formatCurrency(totals.globalDiscountAmount)"></td>
                    </tr>
                    <tr>
                        <th>Biaya Ongkir</th>
                        <td x-text="formatCurrency(totals.shipping)"></td>
                    </tr>
                    <tr>
                        <th>Grand Total</th>
                        <th x-text="formatCurrency(totals.grandTotal)"></th>
                    </tr>
                </table>
            </div>
        </div>
    </div>

    <!-- Global Discount and Shipping -->
    <div class="form-row" x-show="cart.length > 0">
        <div class="col-lg-4">
            <div class="form-group">
                <label for="global_discount">Diskon Global</label>
                <div class="input-group">
                    <button class="btn btn-outline-secondary dropdown-toggle px-3"
                            type="button" data-toggle="dropdown" aria-expanded="false"
                            style="min-width: 60px;">
                        <span x-text="globalDiscountType == 'percentage' ? '%' : 'Rp'"></span>
                    </button>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="#" @click.prevent="setGlobalDiscountType('fixed')">Rp</a></li>
                        <li><a class="dropdown-item" href="#" @click.prevent="setGlobalDiscountType('percentage')">%</a></li>
                    </ul>
                    <input
                        type="number"
                        class="form-control text-right"
                        x-model="globalDiscount"
                        @input="updateTotals()"
                        :max="globalDiscountType == 'percentage' ? 100 : undefined"
                        min="0"
                        step="0.01"
                    >
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="form-group">
                <label for="shipping">Ongkos Kirim</label>
                <input
                    type="text"
                    class="form-control text-right"
                    x-model="shipping_display"
                    @input="handleShippingInput($event.target.value)"
                    @focus="shipping_display = toPlainNumber(shipping)"
                    @blur="formatShipping()"
                    inputmode="decimal"
                >
            </div>
        </div>
    </div>

    <!-- Hidden inputs for form submission -->
    <input type="hidden" name="total_amount" :value="totals.grandTotal">
    <input type="hidden" name="is_tax_included" :value="isTaxIncluded ? 1 : 0">
    <input type="hidden" name="shipping_amount" :value="shipping">
    <input type="hidden" :name="globalDiscountType == 'percentage' ? 'discount_percentage' : 'discount_amount'" :value="globalDiscount">
</div>

<script>
function productCart() {
    return {
        cart: [],
        taxes: @js($taxes ?? []),
        isPkp: @js((bool) ($isPkp ?? false)),
        isTaxIncluded: true,
        globalDiscount: 0,
        globalDiscountType: 'percentage',
        shipping: 0,
        shipping_display: 'Rp 0',
        loading: false,
        totals: {
            subtotalBeforeTax: 0,
            taxTotal: 0,
            subtotal: 0,
            globalDiscountAmount: 0,
            shipping: 0,
            grandTotal: 0
        },

        init() {
            // Listen for product selection
            window.addEventListener('productSelected', (event) => {
                this.addProduct(event.detail);
            });

            // Listen for tax creation
            window.addEventListener('taxCreated', (event) => {
                this.taxes.push(event.detail);
            });

            this.shipping_display = this.formatRupiah(this.shipping);
            this.emitCart();
        },

        addProduct(product) {
            const baseUnitName = product.base_unit_name || product.product_unit || 'pc';
            const defaultUnitKey = 'base';
            const rowId = product.id + '_' + defaultUnitKey;

            // Check if product with base unit already exists
            const existingIndex = this.cart.findIndex(item => item.id === rowId);
            if (existingIndex >= 0) {
                this.cart[existingIndex].quantity = (parseFloat(this.cart[existingIndex].quantity) || 0) + 1;
                this.updateItem(existingIndex, true);
                return;
            }

            const purchasePrice = this.getProductPurchasePrice(product);
            const purchaseTaxId = this.getProductPurchaseTaxId(product);
            const defaultTaxId = purchaseTaxId || null;

            // Add new product with stable row identity
            const newItem = {
                id: rowId,
                product_id: product.id,
                product_name: product.product_name,
                product_code: product.product_code,
                purchase_unit_id: product.base_unit_id || null,
                product_unit_conversion_id: null,
                selected_unit_key: defaultUnitKey,
                unit_name: baseUnitName,
                base_unit_name: baseUnitName,
                conversion_factor: 1.0,
                is_serialized: !!(product.is_serialized || product.serial_number_required),
                unit_price: purchasePrice,
                unit_price_display: this.formatRupiah(purchasePrice),
                stock: product.product_quantity || 0,
                unit: baseUnitName,
                conversions: product.conversions || [],
                quantity: 1,
                discount_type: 'fixed',
                discount: 0,
                tax_id: defaultTaxId,
                taxAmount: 0,
                subtotalBeforeTax: 0,
                total: 0,
                total_display: this.formatRupiah(0),
                validationError: null
            };

            this.cart.push(newItem);
            this.updateItem(this.cart.length - 1, true);
            this.emitCart();
        },

        changeItemUnit(index, selectedKey) {
            const item = this.cart[index];
            let newUnitId = item.base_unit_id;
            let newConvId = null;
            let newUnitName = item.base_unit_name;
            let newFactor = 1.0;

            if (selectedKey !== 'base') {
                const conv = (item.conversions || []).find(c => c.id == selectedKey);
                if (conv) {
                    newUnitId = conv.unit_id;
                    newConvId = conv.id;
                    newUnitName = conv.unit_name;
                    newFactor = parseFloat(conv.factor) || 1.0;
                }
            }

            const newRowId = item.product_id + '_' + (newConvId || 'base');
            const existingIndex = this.cart.findIndex((other, idx) => idx !== index && other.id === newRowId);

            if (existingIndex >= 0) {
                this.cart[existingIndex].quantity = (parseFloat(this.cart[existingIndex].quantity) || 0) + (parseFloat(item.quantity) || 0);
                this.cart.splice(index, 1);
                this.updateItem(existingIndex);
                return;
            }

            item.id = newRowId;
            item.purchase_unit_id = newUnitId;
            item.product_unit_conversion_id = newConvId;
            item.unit_name = newUnitName;
            item.conversion_factor = newFactor;
            item.selected_unit_key = selectedKey;

            this.updateItem(index);
        },

        formatQuantityBreakdown(item) {
            const qty = parseFloat(item.quantity) || 0;
            const factor = parseFloat(item.conversion_factor) || 1;
            const canonical = qty * factor;

            if (factor > 1) {
                return `= ${canonical.toLocaleString('id-ID', {maximumFractionDigits: 3})} ${item.base_unit_name}`;
            }
            return `${qty.toLocaleString('id-ID', {maximumFractionDigits: 3})} ${item.base_unit_name}`;
        },

        updateItem(index, formatDisplay = false) {
            const item = this.cart[index];
            const result = window.purchaseCalculator.calculateItemSubtotalAndTax(
                item.unit_price,
                item.quantity,
                item.discount,
                item.discount_type,
                item.tax_id,
                this.taxes,
                this.isTaxIncluded
            );

            item.subtotalBeforeTax = result.subtotalBeforeTax;
            item.taxAmount = result.taxAmount;
            item.total = result.total;

            if (formatDisplay) {
                item.unit_price_display = this.formatRupiah(item.unit_price);
            }
            item.total_display = this.formatRupiah(item.total);

            // Validation rules
            const qtyFloat = parseFloat(item.quantity) || 0;
            const factor = parseFloat(item.conversion_factor) || 1;
            const canonicalQty = qtyFloat * factor;

            if (item.is_serialized && Math.abs(canonicalQty - Math.round(canonicalQty)) > 1e-6) {
                item.validationError = 'Produk serial memerlukan jumlah unit dasar bulat.';
            } else if (Math.abs(qtyFloat - Math.round(qtyFloat * 1000) / 1000) > 1e-6) {
                item.validationError = 'Jumlah tidak boleh melebihi 3 digit desimal.';
            } else if (Math.abs(canonicalQty - Math.round(canonicalQty * 1000) / 1000) > 1e-6) {
                item.validationError = 'Hasil unit dasar melebihi presisi desimal terdukung.';
            } else {
                item.validationError = null;
            }

            this.updateTotals();
            this.emitCart();
        },

        updateAllItems() {
            this.cart.forEach((item, index) => {
                this.updateItem(index);
            });
            this.emitCart();
        },

        removeItem(index) {
            this.cart.splice(index, 1);
            this.updateTotals();
            this.emitCart();
        },

        setDiscountType(index, type) {
            this.cart[index].discount_type = type;
            this.cart[index].discount = 0;
            this.updateItem(index);
        },

        setGlobalDiscountType(type) {
            this.globalDiscountType = type;
            this.globalDiscount = 0;
            this.updateTotals();
        },

        updateTotals() {
            this.totals = window.purchaseCalculator.calculateCartTotals(
                this.cart,
                this.globalDiscount,
                this.globalDiscountType,
                this.shipping
            );
        },

        formatCurrency(amount) {
            return window.purchaseCalculator.formatCurrency(amount);
        },

        formatRupiah(amount) {
            const numeric = this.toNumber(amount);
            return 'Rp ' + new Intl.NumberFormat('id-ID', {
                minimumFractionDigits: 0,
                maximumFractionDigits: 2
            }).format(numeric);
        },

        toNumber(value) {
            const num = parseFloat(value);
            return isNaN(num) ? 0 : num;
        },

        toPlainNumber(value) {
            const num = this.toNumber(value);
            return Number.isFinite(num) ? num.toString() : '0';
        },

        parseCurrencyInput(value) {
            if (value === null || value === undefined) return 0;
            const cleaned = value
                .toString()
                .replace(/[^0-9,.-]/g, '');
            const withoutThousands = cleaned.replace(/\./g, '');
            const normalized = withoutThousands.replace(',', '.');
            const parsed = parseFloat(normalized);
            return isNaN(parsed) ? 0 : parsed;
        },

        handleUnitPriceInput(index, rawValue) {
            const numericValue = this.parseCurrencyInput(rawValue);
            this.cart[index].unit_price = numericValue;
            this.cart[index].unit_price_display = rawValue;
            this.updateItem(index);
        },

        formatUnitPrice(index) {
            const numericValue = this.parseCurrencyInput(this.cart[index].unit_price_display);
            this.cart[index].unit_price = numericValue;
            this.cart[index].unit_price_display = this.formatRupiah(numericValue);
            this.updateItem(index, true);
        },

        handleLineTotalInput(index, rawValue) {
            this.cart[index].total_display = rawValue;
        },

        formatLineTotal(index) {
            const item = this.cart[index];
            const priorFormattedTotal = this.formatRupiah(item.total);

            // Validate raw input BEFORE parsing
            const validation = window.purchaseCalculatorHelper.validateLineTotalInput(item.total_display);
            if (!validation.isValid) {
                item.total_display = priorFormattedTotal;
                this.showValidationError(index, validation.error);
                return;
            }

            const numericValue = validation.numericValue;

            // Check 100% discount constraint: only allow zero total with 100% discount
            const taxRate = item.tax_id ? (this.taxes.find(t => t.id == item.tax_id)?.value || 0) / 100 : 0;
            if (item.discount_type === 'percentage') {
                const pct = Math.min(100, Math.max(0, item.discount)) / 100;
                if (pct >= 1.0) {
                    if (numericValue > 0.01) {
                        item.total_display = priorFormattedTotal;
                        this.showValidationError(index, 'Total baris lebih dari 0 tidak dimungkinkan dengan diskon 100%.');
                        return;
                    }
                }
            }

            // Calculate new unit price from total
            item.unit_price = window.purchaseCalculatorHelper.calculateUnitPriceFromTotal(
                numericValue,
                item.quantity,
                item.discount,
                item.discount_type,
                taxRate,
                this.isTaxIncluded
            );

            item.unit_price_display = this.formatRupiah(item.unit_price);
            item.validationError = null;
            this.updateItem(index, true);
        },

        showValidationError(index, message) {
            this.cart[index].validationError = message;
        },

        getProductPurchasePrice(product) {
            return this.toNumber(product.last_purchase_price ?? product.purchase_price ?? 0);
        },

        getProductPurchaseTaxId(product) {
            return product.purchase_tax_id ?? null;
        },

        handleShippingInput(rawValue) {
            const numericValue = this.parseCurrencyInput(rawValue);
            this.shipping = numericValue;
            this.shipping_display = rawValue;
            this.updateTotals();
            this.emitCart();
        },

        formatShipping() {
            const numericValue = this.parseCurrencyInput(this.shipping_display);
            this.shipping = numericValue;
            this.shipping_display = this.formatRupiah(numericValue);
            this.updateTotals();
            this.emitCart();
        },

        emitCart() {
            window.dispatchEvent(new CustomEvent('cart-updated', {
                detail: {
                    cart: this.cart,
                    totals: this.totals,
                    shipping: this.shipping,
                    globalDiscount: this.globalDiscount,
                    globalDiscountType: this.globalDiscountType,
                    isTaxIncluded: this.isTaxIncluded
                }
            }));
        }
    };
}
</script>
