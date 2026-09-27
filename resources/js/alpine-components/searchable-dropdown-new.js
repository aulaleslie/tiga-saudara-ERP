/**
 * Reusable Alpine.js searchable dropdown component (v2)
 * Clean implementation to prevent duplication with Livewire/Alpine interaction
 * 
 * USAGE: Always add wire:ignore to the parent container
 * <div wire:ignore x-data="searchableDropdown(...)" x-init="init()">
 */

function buildDropdown(options = [], config = {}) {
    return {
        // State
        inputValue: '',
        results: [...options],
        selectedId: null,
        selectedName: '',
        open: false,
        loading: false,
        disabled: false,
        
        // Config with defaults
        config: {
            placeholder: 'Select...',
            minQueryLength: 1,
            debounceMs: 300,
            displayField: 'name',
            valueField: 'id',
            ...config,
        },
        
        // Initialization - only runs once
        init() {
            // Set initial values from config
            this.selectedId = this.config.initialSelectedId || null;
            this.selectedName = this.config.initialSelectedName || '';
            this.disabled = this.config.initialDisabled || false;
            this.results = Array.isArray(this.config.staticOptions) ? [...this.config.staticOptions] : [...options];
            
            // Set up search debouncing
            this.debouncedSearch = this.debounce(() => this.search(), this.config.debounceMs);
        },
        
        // Search/filter results
        search() {
            const query = (this.inputValue || '').trim().toLowerCase();
            
            if (!query || query.length < this.config.minQueryLength) {
                this.results = [...(this.config.staticOptions || options)];
                return;
            }
            
            const field = this.config.displayField;
            this.results = (this.config.staticOptions || options).filter(item => {
                const itemValue = String(item[field] || '').toLowerCase();
                return itemValue.includes(query);
            });
        },
        
        // Select an item
        selectItem(item) {
            if (!item) return;
            
            this.selectedId = item[this.config.valueField];
            this.selectedName = item[this.config.displayField];
            this.inputValue = '';
            this.open = false;
            
            // Dispatch event for other listeners
            window.dispatchEvent(new CustomEvent('dropdown-selected', {
                detail: { id: this.selectedId, name: this.selectedName }
            }));
        },
        
        // Toggle dropdown
        toggleDropdown() {
            if (this.disabled) return;
            this.open = !this.open;
            if (this.open) {
                this.search();
                this.$nextTick(() => {
                    const input = this.$el.querySelector('input[type="text"]');
                    if (input) input.focus();
                });
            }
        },
        
        // Clear selection
        clearSelection() {
            this.selectedId = null;
            this.selectedName = '';
            this.inputValue = '';
            this.results = [...(this.config.staticOptions || options)];
        },
        
        // Utility: debounce function
        debounce(fn, delay) {
            let timeout;
            return function(...args) {
                clearTimeout(timeout);
                timeout = setTimeout(() => fn.apply(this, args), delay);
            };
        },
    };
}

// Global dropdown factories (entity-specific)
window.brandDropdown = function(options = [], selectedId = null, selectedName = '', configOverrides = {}) {
    return buildDropdown(options, {
        placeholder: 'Pilih merek...',
        displayField: 'name',
        valueField: 'id',
        minQueryLength: 1,
        initialSelectedId: selectedId,
        initialSelectedName: selectedName,
        staticOptions: options,
        ...configOverrides,
    });
};

window.categoryDropdown = function(options = [], selectedId = null, selectedName = '', configOverrides = {}) {
    return buildDropdown(options, {
        placeholder: 'Pilih kategori...',
        displayField: 'name',
        valueField: 'id',
        minQueryLength: 1,
        initialSelectedId: selectedId,
        initialSelectedName: selectedName,
        staticOptions: options,
        ...configOverrides,
    });
};

window.taxDropdown = function(options = [], selectedId = null, selectedName = '', initialDisabled = false, configOverrides = {}) {
    return buildDropdown(options, {
        placeholder: 'Pilih pajak...',
        displayField: 'name',
        valueField: 'id',
        minQueryLength: 1,
        initialSelectedId: selectedId,
        initialSelectedName: selectedName,
        initialDisabled: initialDisabled,
        staticOptions: options,
        ...configOverrides,
    });
};

window.unitDropdown = function(options = [], selectedId = null, selectedName = '', initialDisabled = false, configOverrides = {}) {
    return buildDropdown(options, {
        placeholder: 'Pilih unit...',
        displayField: 'name',
        valueField: 'id',
        minQueryLength: 1,
        initialSelectedId: selectedId,
        initialSelectedName: selectedName,
        initialDisabled: initialDisabled,
        staticOptions: options,
        ...configOverrides,
    });
};

// Helper for binding disabled state to checkbox
window.bindDisabledToCheckbox = function(checkboxId, alpineComponent) {
    const checkbox = document.getElementById(checkboxId);
    if (!checkbox || !alpineComponent) return;
    
    const updateDisabled = () => {
        alpineComponent.disabled = !checkbox.checked;
    };
    
    checkbox.addEventListener('change', updateDisabled);
    updateDisabled();
};
