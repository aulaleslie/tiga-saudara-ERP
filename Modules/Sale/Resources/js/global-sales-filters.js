/**
 * Global Menu Filter Handler
 * Manages filter state, URL synchronization, and dynamic updates
 */

class GlobalSalesFilterHandler {
    constructor(options = {}) {
        this.formSelector = options.formSelector || '#filter-form';
        this.storageKey = options.storageKey || 'global_sales_filters';
        this.updateDelay = options.updateDelay || 500;
        this.urlSync = options.urlSync !== false;

        this.filters = {};
        this.updateTimer = null;
        this.initialized = false;

        this.init();
    }

    init() {
        this.loadFromStorage();
        this.loadFromURL();
        this.attachEventListeners();
        this.initialized = true;
        this.emitFiltersChanged();
    }

    attachEventListeners() {
        // Listen for form changes
        document.addEventListener('input', (e) => {
            if (e.target.matches(`${this.formSelector} input, ${this.formSelector} select`)) {
                this.handleFilterChange(e.target);
            }
        });

        document.addEventListener('change', (e) => {
            if (e.target.matches(`${this.formSelector} input[type="checkbox"], ${this.formSelector} select`)) {
                this.handleFilterChange(e.target);
            }
        });

        // Listen for Livewire updates
        document.addEventListener('livewire:updated', () => {
            this.syncFormValues();
        });

        // Handle browser back/forward
        window.addEventListener('popstate', () => {
            this.loadFromURL();
            this.emitFiltersChanged();
        });

        // Clear filters button
        document.addEventListener('click', (e) => {
            if (e.target.matches('[data-action="clear-filters"]')) {
                this.clearAllFilters();
            }
        });

        // Preset buttons
        document.addEventListener('click', (e) => {
            if (e.target.matches('[data-preset]')) {
                const preset = e.target.getAttribute('data-preset');
                this.applyPreset(preset);
            }
        });
    }

    handleFilterChange(element) {
        const name = element.name || element.getAttribute('data-filter-name');
        if (!name) return;

        let value = element.value;

        // Handle checkboxes
        if (element.type === 'checkbox') {
            value = element.checked;
        }

        // Handle multiple selects
        if (element.multiple) {
            value = Array.from(element.selectedOptions).map(option => option.value);
        }

        this.setFilter(name, value);
    }

    setFilter(name, value) {
        // Normalize empty values
        if (value === '' || value === null || value === undefined ||
            (Array.isArray(value) && value.length === 0)) {
            delete this.filters[name];
        } else {
            this.filters[name] = value;
        }

        this.scheduleUpdate();
    }

    getFilter(name, defaultValue = null) {
        return this.filters[name] !== undefined ? this.filters[name] : defaultValue;
    }

    getAllFilters() {
        return { ...this.filters };
    }

    clearAllFilters() {
        this.filters = {};
        this.updateURL();
        this.saveToStorage();
        this.syncFormValues();
        this.emitFiltersChanged();
        this.showNotification('Filters cleared', 'info');
    }

    scheduleUpdate() {
        clearTimeout(this.updateTimer);
        this.updateTimer = setTimeout(() => {
            this.updateURL();
            this.saveToStorage();
            this.emitFiltersChanged();
        }, this.updateDelay);
    }

    emitFiltersChanged() {
        // Emit to Livewire components
        if (window.Livewire) {
            Livewire.emit('filtersChanged', this.getAllFilters());
        }

        // Emit custom event
        const event = new CustomEvent('globalMenuFiltersChanged', {
            detail: { filters: this.getAllFilters() }
        });
        document.dispatchEvent(event);
    }

    syncFormValues() {
        if (!this.initialized) return;

        Object.keys(this.filters).forEach(name => {
            const elements = document.querySelectorAll(`${this.formSelector} [name="${name}"]`);
            elements.forEach(element => {
                const value = this.filters[name];

                if (element.type === 'checkbox') {
                    element.checked = Boolean(value);
                } else if (element.multiple) {
                    Array.from(element.options).forEach(option => {
                        option.selected = Array.isArray(value) && value.includes(option.value);
                    });
                } else {
                    element.value = value;
                }
            });
        });
    }

    applyPreset(preset) {
        const now = new Date();
        let dateFrom, dateTo;

        switch (preset) {
            case 'today':
                dateFrom = dateTo = this.formatDate(now);
                break;
            case 'yesterday':
                const yesterday = new Date(now);
                yesterday.setDate(yesterday.getDate() - 1);
                dateFrom = dateTo = this.formatDate(yesterday);
                break;
            case 'this_week':
                const weekStart = new Date(now);
                weekStart.setDate(now.getDate() - now.getDay());
                dateFrom = this.formatDate(weekStart);
                dateTo = this.formatDate(now);
                break;
            case 'last_week':
                const lastWeekStart = new Date(now);
                lastWeekStart.setDate(now.getDate() - now.getDay() - 7);
                const lastWeekEnd = new Date(lastWeekStart);
                lastWeekEnd.setDate(lastWeekStart.getDate() + 6);
                dateFrom = this.formatDate(lastWeekStart);
                dateTo = this.formatDate(lastWeekEnd);
                break;
            case 'this_month':
                dateFrom = this.formatDate(new Date(now.getFullYear(), now.getMonth(), 1));
                dateTo = this.formatDate(now);
                break;
            case 'last_month':
                const lastMonth = new Date(now.getFullYear(), now.getMonth() - 1, 1);
                const lastMonthEnd = new Date(now.getFullYear(), now.getMonth(), 0);
                dateFrom = this.formatDate(lastMonth);
                dateTo = this.formatDate(lastMonthEnd);
                break;
            default:
                return;
        }

        this.setFilter('date_from', dateFrom);
        this.setFilter('date_to', dateTo);
        this.showNotification(`Applied ${preset.replace('_', ' ')} preset`, 'success');
    }

    formatDate(date) {
        return date.toISOString().split('T')[0];
    }

    updateURL() {
        if (!this.urlSync) return;

        const url = new URL(window.location);

        // Clear existing filter params
        Array.from(url.searchParams.keys()).forEach(key => {
            if (key.startsWith('filter_')) {
                url.searchParams.delete(key);
            }
        });

        // Add current filters
        Object.keys(this.filters).forEach(key => {
            const value = this.filters[key];
            if (Array.isArray(value)) {
                value.forEach(v => url.searchParams.append(`filter_${key}`, v));
            } else {
                url.searchParams.set(`filter_${key}`, value);
            }
        });

        // Update URL without triggering page reload
        window.history.replaceState({}, '', url.toString());
    }

    loadFromURL() {
        if (!this.urlSync) return;

        const url = new URL(window.location);
        const newFilters = {};

        url.searchParams.forEach((value, key) => {
            if (key.startsWith('filter_')) {
                const filterName = key.substring(7); // Remove 'filter_' prefix

                if (newFilters[filterName]) {
                    // Multiple values for same filter
                    if (!Array.isArray(newFilters[filterName])) {
                        newFilters[filterName] = [newFilters[filterName]];
                    }
                    newFilters[filterName].push(value);
                } else {
                    newFilters[filterName] = value;
                }
            }
        });

        this.filters = newFilters;
    }

    saveToStorage() {
        try {
            localStorage.setItem(this.storageKey, JSON.stringify(this.filters));
        } catch (e) {
            console.warn('Failed to save filters to localStorage:', e);
        }
    }

    loadFromStorage() {
        try {
            const stored = localStorage.getItem(this.storageKey);
            if (stored) {
                this.filters = JSON.parse(stored);
            }
        } catch (e) {
            console.warn('Failed to load filters from localStorage:', e);
        }
    }

    showNotification(message, type = 'info') {
        // Simple notification - could be enhanced with a proper notification system
        const notification = document.createElement('div');
        notification.className = `alert alert-${type} alert-dismissible fade show position-fixed`;
        notification.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
        notification.innerHTML = `
            ${message}
            <button type="button" class="close" data-dismiss="alert">
                <span>&times;</span>
            </button>
        `;

        document.body.appendChild(notification);

        // Auto-remove after 3 seconds
        setTimeout(() => {
            if (notification.parentNode) {
                $(notification).alert('close');
            }
        }, 3000);
    }

    getActiveFilterCount() {
        return Object.keys(this.filters).length;
    }

    hasActiveFilters() {
        return this.getActiveFilterCount() > 0;
    }

    exportFilters() {
        return {
            filters: this.getAllFilters(),
            count: this.getActiveFilterCount(),
            timestamp: new Date().toISOString()
        };
    }

    importFilters(data) {
        if (data && data.filters) {
            this.filters = data.filters;
            this.scheduleUpdate();
            this.showNotification('Filters imported successfully', 'success');
        }
    }

    destroy() {
        clearTimeout(this.updateTimer);
        // Remove event listeners if needed
    }
}

// Initialize filter handler when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    if (document.querySelector('#filter-form')) {
        window.globalSalesFilterHandler = new GlobalSalesFilterHandler({
            formSelector: '#filter-form',
            storageKey: 'global_sales_filters',
            updateDelay: 500,
            urlSync: true
        });
    }
});

// Export for module usage
if (typeof module !== 'undefined' && module.exports) {
    module.exports = GlobalSalesFilterHandler;
}