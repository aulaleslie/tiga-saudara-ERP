/**
 * Reusable Alpine.js searchable dropdown component
 * Supports API-based search with debouncing and loading states
 */
function searchableDropdown() {
    return {
        query: '',
        inputValue: '',
        results: [],
        selectedId: null,
        selectedName: '',
        open: false,
        loading: false,
        disabled: false,
        abortController: null,
        staticResults: [],
        allTerms: [],

        // Configuration passed via x-data attributes
        config: {
            apiUrl: '',
            initialSelectedId: null,
            initialSelectedName: '',
            initialDisabled: false,
            placeholder: 'Search...',
            minQueryLength: 2,
            debounceMs: 300,
            displayField: 'display_name',
            valueField: 'id',
            limit: 10,
            additionalParams: {},
            staticOptions: [],
            filterItem: null,
        },

        safeArray(value = []) {
            return Array.isArray(value) ? value : [];
        },

        passesFilter(item = {}) {
            const filterFn = this.config?.filterItem;
            if (typeof filterFn !== 'function') {
                return true;
            }

            try {
                return !!filterFn(item);
            } catch (error) {
                console.error('Dropdown filter error:', error);
                return false;
            }
        },

        filterList(list = []) {
            const items = this.safeArray(list);
            if (typeof this.config?.filterItem !== 'function') {
                return items;
            }

            return items.filter(entry => this.passesFilter(entry));
        },

        dedupeList(list = []) {
            const filtered = this.filterList(list);
            const seen = new Set();
            const valueKey = this.config.valueField;

            return filtered.filter(item => {
                const key = item && (item[valueKey] ?? item.id);
                const dedupeKey = key === undefined || key === null ? null : String(key);

                if (dedupeKey === null) {
                    return true;
                }

                if (seen.has(dedupeKey)) {
                    return false;
                }

                seen.add(dedupeKey);
                return true;
            });
        },

        normalizeItem(item = {}) {
            const valueKey = this.config.valueField;
            const displayKey = this.config.displayField;

            const normalizedId = (item && item[valueKey]) ?? (item ? item.id : null);
            const normalizedName =
                (item && item[displayKey]) ??
                (item && item.name) ??
                (item && item.display_name) ??
                (item && item.category_name) ??
                '';

            return { ...item, [valueKey]: normalizedId, [displayKey]: normalizedName };
        },

        upsertItem(item) {
            if (!this.passesFilter(item)) {
                return null;
            }

            const normalized = this.normalizeItem(item);
            const valueKey = this.config.valueField;

            this.staticResults = this.safeArray(this.staticResults);
            this.allTerms = this.safeArray(this.allTerms);

            const existingIndex = this.staticResults.findIndex(entry =>
                entry && normalized && entry[valueKey] == normalized[valueKey]
            );

            if (existingIndex >= 0) {
                this.staticResults.splice(existingIndex, 1, { ...this.staticResults[existingIndex], ...normalized });
            } else {
                this.staticResults.unshift(normalized);
            }

            this.staticResults = this.safeArray(this.staticResults);
            this.allTerms = this.safeArray([...this.staticResults]);
            this.results = this.safeArray([...this.staticResults]);

            return normalized;
        },

        setResults(list = []) {
            this.results = this.dedupeList(list);
            return this.results;
        },

        init() {
            // Check if already initialized using DOM attribute
            if (this.$el?.getAttribute('data-initialized') === 'true') {
                return;
            }
            
            // Mark as initialized immediately to prevent re-runs
            this.$el?.setAttribute('data-initialized', 'true');

            // Initialize from config
            this.selectedId = this.config.initialSelectedId;
            this.selectedName = this.config.initialSelectedName;
            this.disabled = this.config.initialDisabled;
            this.inputValue = '';
            this.staticResults = this.dedupeList(this.config.staticOptions);
            this.results = this.dedupeList(this.staticResults.length ? this.staticResults : this.results);
            this.allTerms = this.dedupeList(this.allTerms.length ? this.allTerms : this.results);

            // Seed local cache if provided outside config
            if (!this.staticResults.length) {
                const seeded = this.dedupeList(
                    this.allTerms.length ? this.allTerms : this.results
                );
                this.staticResults = this.safeArray(seeded);
            }

            // Ensure cached lists respect current filters
            this.results = this.dedupeList(this.results);
            this.allTerms = this.dedupeList(this.allTerms);

            // Set initial selected name if we have an ID but no name
            if (this.selectedId && !this.selectedName) {
                const candidates = this.safeArray(
                    this.staticResults.length ? this.staticResults : this.results
                );
                const item = candidates.find(r => r[this.config.valueField] == this.selectedId);
                if (item) {
                    const normalized = this.normalizeItem(item);
                    this.selectedName = normalized[this.config.displayField];
                }
            }

            // Listen for entity creation events
            if (this.config.entityType) {
                window.addEventListener(`${this.config.entityType}Created`, (event) => {
                    this.handleEntityCreated(event.detail);
                });
                
                // Listen for entity cleared events
                window.addEventListener(`${this.config.entityType.toLowerCase().replace(/([A-Z])/g, '-$1')}-cleared`, (event) => {
                    this.clearSelection(false); // avoid re-dispatch loops
                });
            }

            // Watch for selectedId changes and update display
            this.$watch('selectedId', (newValue, oldValue) => {
                if (newValue !== oldValue) {
                    this.updateSelectedName();
                }
            });
        },

        async search() {
            // Always sync query from the input so we separate UI display from request value
            this.query = this.inputValue;

            // Ensure we have valid config
            const minLength = this.config.minQueryLength || 2;
            const limit = this.config.limit || 10;

            if (!this.query || this.query.length < minLength) {
                // For local datasets, show all options when there is no query
                if (!this.config.apiUrl) {
                    const base = this.safeArray(
                        this.staticResults.length
                            ? this.staticResults
                            : (this.allTerms.length ? this.allTerms : this.results)
                    );
                    this.setResults(base);
                    this.open = true;
                    this.loading = false;
                    return;
                } else {
                    this.setResults([]);
                    this.open = false;
                    return;
                }
            }

            // Local filtering mode (no API)
            if (!this.config.apiUrl) {
                const haystack = this.safeArray(
                    this.staticResults.length ? this.staticResults : (this.allTerms.length ? this.allTerms : this.results)
                );
                const keyword = this.query.toLowerCase();
                this.setResults(haystack.filter(item =>
                    (item[this.config.displayField] || '').toLowerCase().includes(keyword)
                ));
                this.open = true;
                this.loading = false;
                return;
            }

            this.loading = true;
            this.open = true;

            // Cancel previous request
            if (this.abortController) {
                this.abortController.abort();
            }

            this.abortController = new AbortController();

            try {
                const params = new URLSearchParams({
                    query: this.query,
                    limit: limit,
                    ...this.config.additionalParams
                });

                const response = await fetch(`${this.config.apiUrl}?${params}`, {
                    signal: this.abortController.signal,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
                    }
                });

                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }

                const data = await response.json();
                this.setResults(data);
            } catch (error) {
                if (error.name !== 'AbortError') {
                    console.error('Search error:', error);
                    this.setResults([]);
                }
            } finally {
                this.loading = false;
            }
        },

        selectItem(item) {
            if (this.disabled || !item) return;

            const normalized = this.upsertItem(item);
            if (!normalized) return;

            this.selectedId = normalized[this.config.valueField];
            this.selectedName = normalized[this.config.displayField];
            this.inputValue = '';
            this.query = '';
            this.results = this.safeArray(this.results);
            this.open = false;

            // Dispatch selection event
            this.dispatchSelectionEvents(normalized);
        },

        toggleDropdown() {
            if (this.disabled) return;

            this.open = !this.open;
            if (this.open) {
                this.search();
            }
        },

        bindDisabledToCheckbox(checkboxId) {
            const checkbox = checkboxId ? document.getElementById(checkboxId) : null;
            if (!checkbox) return;

            this.disabled = !checkbox.checked;
            checkbox.addEventListener('change', (event) => {
                this.disabled = !event.target.checked;
            });
        },

        // Watch for selectedId changes and update display
        updateSelectedName() {
            if (this.selectedId) {
                const searchArray = this.safeArray(
                    (this.staticResults && this.staticResults.length) ? this.staticResults :
                        (this.allTerms.length ? this.allTerms : this.results)
                );
                const item = searchArray.find(r => r[this.config.valueField] == this.selectedId);
                if (item) {
                    const normalized = this.normalizeItem(item);
                    this.selectedName = normalized[this.config.displayField];
                } else {
                    // Fallback: keep current name or set to ID
                    this.selectedName = this.selectedName || `ID: ${this.selectedId}`;
                }
            } else {
                this.selectedName = '';
            }
        },

        clearSelection(emitEvent = true) {
            this.selectedId = null;
            this.selectedName = '';
            this.inputValue = '';
            this.query = '';
            this.results = this.safeArray(this.results);
            this.open = false;

            // Dispatch clear event
            if (emitEvent && this.config.entityType) {
                this.dispatchWithKebab(`${this.config.entityType}Cleared`);
            }
        },

        showInput() {
            // Switch to input mode while keeping the previous value visible/selectable
            const previousValue = this.selectedName || '';
            this.selectedId = null;
            this.query = previousValue;
            this.inputValue = previousValue;
            this.results = [];
            this.open = false;

            // Focus and highlight the text so users can overwrite quickly
            this.$nextTick(() => {
                const input = this.$el.querySelector('input');
                if (input) {
                    input.focus();
                    input.select();
                }
            });
        },

        handleEntityCreated(data) {
            // Auto-select the newly created entity
            const normalized = this.upsertItem(data);
            if (!normalized) {
                return;
            }

            this.selectedId = normalized[this.config.valueField];
            this.selectedName = normalized[this.config.displayField];
            this.query = '';
            this.results = this.safeArray(this.results);
            this.open = false;

            // Dispatch selection event
            this.dispatchSelectionEvents(normalized);
        },

        handlePaymentTermUpdate(data) {
            if (this.config.entityType === 'paymentTerm') {
                this.selectedId = data.id;
                if (data.name) {
                    this.selectedName = data.name;
                } else {
                    this.updateSelectedName();
                }
            }
        },

        handlePaymentTermClear() {
            if (this.config.entityType === 'paymentTerm') {
                this.clearSelection();
            }
        },

        // Debounced search method
        debouncedSearch: null,
        updatedQuery() {
            if (this.debouncedSearch) {
                clearTimeout(this.debouncedSearch);
            }
            // Keep the transport query in sync with what's displayed
            this.query = this.inputValue;
            this.debouncedSearch = setTimeout(() => {
                this.search();
            }, this.config.debounceMs);
        },

        dispatchSelectionEvents(item) {
            if (!this.config.entityType) {
                return;
            }

            const detail = {
                id: this.selectedId,
                name: this.selectedName,
                item: item
            };

            if (item && Object.prototype.hasOwnProperty.call(item, 'payment_term_id')) {
                detail.payment_term_id = item.payment_term_id;
            }

            this.dispatchWithKebab(`${this.config.entityType}Selected`, detail);
        },

        dispatchWithKebab(eventName, detail = {}) {
            if (!eventName) {
                return;
            }

            this.$dispatch(eventName, detail);

            const kebab = this.toKebabCase(eventName);
            if (kebab && kebab !== eventName) {
                this.$dispatch(kebab, detail);
            }
        },

        toKebabCase(value) {
            return value
                .replace(/([a-z0-9])([A-Z])/g, '$1-$2')
                .replace(/_/g, '-')
                .toLowerCase();
        }
    };
}

// Register as global Alpine data function
window.searchableDropdown = searchableDropdown;

function normalizeDropdownOptions(options = [], valueField = 'id', displayField = 'name') {
    const seen = new Set();
    const safeOptions = Array.isArray(options) ? options : [];

    return safeOptions.reduce((carry, item) => {
        if (!item) return carry;

        const normalizedId = item[valueField] ?? item.id ?? null;
        const idKey = normalizedId === undefined || normalizedId === null ? null : String(normalizedId);
        if (idKey === null) return carry;

        const normalizedName =
            item[displayField] ??
            item.name ??
            item.display_name ??
            item.category_name ??
            '';

        if (seen.has(idKey)) return carry;
        seen.add(idKey);

        carry.push({
            ...item,
            [valueField]: normalizedId,
            [displayField]: normalizedName,
        });

        return carry;
    }, []);
}

function buildDropdownInstance(options = [], overrides = {}) {
    const base = searchableDropdown();
    const valueField = overrides.valueField || base.config.valueField;
    const displayField = overrides.displayField || base.config.displayField;

    const normalizedOptions = normalizeDropdownOptions(options, valueField, displayField).map(opt => ({ ...opt }));

    return {
        ...base,
        config: {
            ...base.config,
            ...overrides,
            valueField,
            displayField,
            staticOptions: normalizedOptions.map(opt => ({ ...opt })),
            initialSelectedId: overrides.initialSelectedId ?? null,
            initialSelectedName: overrides.initialSelectedName ?? '',
        },
        results: normalizedOptions.map(opt => ({ ...opt })),
        allTerms: normalizedOptions.map(opt => ({ ...opt })),
        init() {
            base.init.call(this);
        },
    };
}

window.categoryDropdown = function (options = [], selectedId = null, selectedName = '', configOverrides = {}) {
    // Only log for main product category dropdown
    const shouldLog = configOverrides.placeholder === 'Pilih kategori...' && !configOverrides.filterItem;

    if (shouldLog) {
        console.log('=== categoryDropdown Function Called ===');
        console.log('Input options:', JSON.stringify(options));
        console.log('Selected ID:', JSON.stringify(selectedId));
        console.log('Selected Name:', JSON.stringify(selectedName));
        console.log('Config overrides:', JSON.stringify(configOverrides));
    }

    const result = buildDropdownInstance(options, {
        apiUrl: null,
        entityType: 'category',
        placeholder: 'Cari kategori...',
        displayField: 'name',
        valueField: 'id',
        minQueryLength: 1,
        initialSelectedId: selectedId,
        initialSelectedName: selectedName,
        ...configOverrides,
    });

    if (shouldLog) {
        console.log('Dropdown instance created:', JSON.stringify(result));
    }
    return result;
};

window.brandDropdown = function (options = [], selectedId = null, selectedName = '') {
    return buildDropdownInstance(options, {
        apiUrl: null,
        entityType: 'brand',
        placeholder: 'Cari merek...',
        displayField: 'name',
        valueField: 'id',
        minQueryLength: 1,
        initialSelectedId: selectedId,
        initialSelectedName: selectedName,
    });
};

window.unitDropdown = function (options = [], selectedId = null, selectedName = '', initialDisabled = false) {
    return buildDropdownInstance(options, {
        apiUrl: null,
        entityType: 'unit',
        placeholder: 'Cari unit...',
        displayField: 'name',
        valueField: 'id',
        minQueryLength: 1,
        initialSelectedId: selectedId,
        initialSelectedName: selectedName,
        initialDisabled,
    });
};

window.taxDropdown = function (options = [], selectedId = null, selectedName = '', initialDisabled = false) {
    return buildDropdownInstance(options, {
        apiUrl: null,
        entityType: 'tax',
        placeholder: 'Cari pajak...',
        displayField: 'name',
        valueField: 'id',
        minQueryLength: 1,
        initialSelectedId: selectedId,
        initialSelectedName: selectedName,
        initialDisabled,
    });
};
