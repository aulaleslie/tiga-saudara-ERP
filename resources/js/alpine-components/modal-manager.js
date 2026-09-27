/**
 * Reusable Alpine.js modal manager component
 * Handles modal state and communication with quick-add modals
 */
function modalManager() {
    return {
        modals: {},

        init() {
            // Initialize modal states
            this.modals = {
                supplier: false,
                product: false,
                paymentTerm: false,
                tax: false
            };

            // Listen for modal open events
            this.$watch('modals.supplier', (value) => {
                if (value) this.$dispatch('openSupplierModal');
            });
            this.$watch('modals.product', (value) => {
                if (value) this.$dispatch('openProductModal');
            });
            this.$watch('modals.paymentTerm', (value) => {
                if (value) this.$dispatch('openPaymentTermModal');
            });
            this.$watch('modals.tax', (value) => {
                if (value) this.$dispatch('openTaxModal');
            });
        },

        openModal(type) {
            this.modals[type] = true;
        },

        closeModal(type) {
            this.modals[type] = false;
        },

        closeAllModals() {
            Object.keys(this.modals).forEach(key => {
                this.modals[key] = false;
            });
        }
    };
}

// Register as global Alpine data function
window.modalManager = modalManager;