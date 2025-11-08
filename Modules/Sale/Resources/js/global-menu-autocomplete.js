/**
 * Global Menu Autocomplete Functionality
 * Provides autocomplete suggestions for serial numbers, sale references, and customers
 */

class GlobalMenuAutocomplete {
    constructor(options = {}) {
        this.inputSelector = options.inputSelector || 'input[name="query"]';
        this.suggestionsContainer = options.suggestionsContainer || null;
        this.minChars = options.minChars || 2;
        this.debounceTime = options.debounceTime || 300;
        this.maxSuggestions = options.maxSuggestions || 10;
        this.apiEndpoint = options.apiEndpoint || '/api/global-menu/suggest';
        this.searchType = options.searchType || 'all';

        this.currentInput = null;
        this.suggestions = [];
        this.selectedIndex = -1;
        this.debounceTimer = null;
        this.cache = new Map();

        this.init();
    }

    init() {
        this.attachEventListeners();
        this.createSuggestionsContainer();
    }

    attachEventListeners() {
        document.addEventListener('input', (e) => {
            if (e.target.matches(this.inputSelector)) {
                this.handleInput(e.target);
            }
        });

        document.addEventListener('keydown', (e) => {
            if (e.target.matches(this.inputSelector)) {
                this.handleKeydown(e);
            }
        });

        document.addEventListener('click', (e) => {
            if (!e.target.closest('.autocomplete-container')) {
                this.hideSuggestions();
            }
        });

        // Listen for Livewire updates
        document.addEventListener('livewire:updated', () => {
            this.attachToNewInputs();
        });
    }

    attachToNewInputs() {
        const inputs = document.querySelectorAll(this.inputSelector);
        inputs.forEach(input => {
            if (!input.hasAttribute('data-autocomplete-attached')) {
                input.setAttribute('data-autocomplete-attached', 'true');
                // Re-attach listeners if needed
            }
        });
    }

    createSuggestionsContainer() {
        if (this.suggestionsContainer) return;

        this.suggestionsContainer = document.createElement('div');
        this.suggestionsContainer.className = 'autocomplete-suggestions position-absolute bg-white border rounded shadow-sm';
        this.suggestionsContainer.style.cssText = `
            z-index: 1000;
            max-height: 300px;
            overflow-y: auto;
            display: none;
            min-width: 100%;
        `;

        document.body.appendChild(this.suggestionsContainer);
    }

    handleInput(input) {
        this.currentInput = input;
        const query = input.value.trim();

        clearTimeout(this.debounceTimer);

        if (query.length < this.minChars) {
            this.hideSuggestions();
            return;
        }

        this.debounceTimer = setTimeout(() => {
            this.fetchSuggestions(query);
        }, this.debounceTime);
    }

    async fetchSuggestions(query) {
        const cacheKey = `${this.searchType}:${query}`;

        if (this.cache.has(cacheKey)) {
            this.showSuggestions(this.cache.get(cacheKey));
            return;
        }

        try {
            const params = new URLSearchParams({
                q: query,
                type: this.searchType
            });

            const response = await fetch(`${this.apiEndpoint}?${params}`, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                }
            });

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const data = await response.json();

            if (data.success && data.suggestions) {
                this.cache.set(cacheKey, data.suggestions);
                this.showSuggestions(data.suggestions);
            } else {
                this.hideSuggestions();
            }
        } catch (error) {
            console.error('Autocomplete fetch error:', error);
            this.hideSuggestions();
        }
    }

    showSuggestions(suggestions) {
        if (!suggestions || suggestions.length === 0) {
            this.hideSuggestions();
            return;
        }

        this.suggestions = suggestions.slice(0, this.maxSuggestions);
        this.selectedIndex = -1;

        const html = this.suggestions.map((suggestion, index) => `
            <div class="autocomplete-item px-3 py-2 cursor-pointer"
                 data-index="${index}"
                 data-value="${suggestion.label}"
                 data-type="${suggestion.type}">
                <div class="d-flex justify-content-between align-items-center">
                    <span>${this.highlightMatch(suggestion.label, this.currentInput.value)}</span>
                    <small class="text-muted">${suggestion.type}</small>
                </div>
            </div>
        `).join('');

        this.suggestionsContainer.innerHTML = html;
        this.positionContainer();
        this.suggestionsContainer.style.display = 'block';

        // Attach click listeners to suggestions
        this.suggestionsContainer.querySelectorAll('.autocomplete-item').forEach((item, index) => {
            item.addEventListener('click', () => this.selectSuggestion(index));
            item.addEventListener('mouseenter', () => this.highlightSuggestion(index));
        });
    }

    hideSuggestions() {
        this.suggestionsContainer.style.display = 'none';
        this.selectedIndex = -1;
    }

    positionContainer() {
        if (!this.currentInput) return;

        const rect = this.currentInput.getBoundingClientRect();
        const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
        const scrollLeft = window.pageXOffset || document.documentElement.scrollLeft;

        this.suggestionsContainer.style.top = `${rect.bottom + scrollTop}px`;
        this.suggestionsContainer.style.left = `${rect.left + scrollLeft}px`;
        this.suggestionsContainer.style.width = `${rect.width}px`;
    }

    highlightMatch(text, query) {
        if (!query) return text;

        const regex = new RegExp(`(${this.escapeRegex(query)})`, 'gi');
        return text.replace(regex, '<mark>$1</mark>');
    }

    escapeRegex(string) {
        return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    handleKeydown(e) {
        if (this.suggestionsContainer.style.display === 'none') return;

        switch (e.key) {
            case 'ArrowDown':
                e.preventDefault();
                this.navigateSuggestions(1);
                break;
            case 'ArrowUp':
                e.preventDefault();
                this.navigateSuggestions(-1);
                break;
            case 'Enter':
                e.preventDefault();
                if (this.selectedIndex >= 0) {
                    this.selectSuggestion(this.selectedIndex);
                }
                break;
            case 'Escape':
                this.hideSuggestions();
                break;
        }
    }

    navigateSuggestions(direction) {
        const items = this.suggestionsContainer.querySelectorAll('.autocomplete-item');
        if (items.length === 0) return;

        // Remove previous highlight
        if (this.selectedIndex >= 0) {
            items[this.selectedIndex].classList.remove('bg-light');
        }

        // Calculate new index
        this.selectedIndex += direction;
        if (this.selectedIndex < 0) this.selectedIndex = items.length - 1;
        if (this.selectedIndex >= items.length) this.selectedIndex = 0;

        // Add new highlight
        items[this.selectedIndex].classList.add('bg-light');
        items[this.selectedIndex].scrollIntoView({ block: 'nearest' });
    }

    highlightSuggestion(index) {
        const items = this.suggestionsContainer.querySelectorAll('.autocomplete-item');

        // Remove previous highlight
        items.forEach(item => item.classList.remove('bg-light'));

        // Add new highlight
        if (items[index]) {
            items[index].classList.add('bg-light');
            this.selectedIndex = index;
        }
    }

    selectSuggestion(index) {
        if (!this.suggestions[index] || !this.currentInput) return;

        this.currentInput.value = this.suggestions[index].label;
        this.hideSuggestions();

        // Trigger input event to update Livewire
        this.currentInput.dispatchEvent(new Event('input', { bubbles: true }));

        // Focus the input
        this.currentInput.focus();
    }

    updateSearchType(type) {
        this.searchType = type;
        this.cache.clear(); // Clear cache when search type changes
    }

    destroy() {
        if (this.suggestionsContainer) {
            this.suggestionsContainer.remove();
        }
        clearTimeout(this.debounceTimer);
    }
}

// Initialize autocomplete when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    // Initialize autocomplete for global menu search
    if (document.querySelector('input[name="query"]')) {
        window.globalMenuAutocomplete = new GlobalMenuAutocomplete({
            inputSelector: 'input[name="query"]',
            minChars: 2,
            debounceTime: 300,
            maxSuggestions: 8
        });
    }
});

// Export for module usage
if (typeof module !== 'undefined' && module.exports) {
    module.exports = GlobalMenuAutocomplete;
}