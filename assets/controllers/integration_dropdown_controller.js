import { Controller } from '@hotwired/stimulus';

/**
 * Integration Dropdown Controller
 * Handles the "Add New Integration" dropdown menu behavior
 */
export default class extends Controller {
    static targets = ['button', 'menu', 'searchInput', 'item', 'showMore'];

    connect() {
        this.experimentalExpanded = false;

        // Bind click outside handler
        this.boundHandleClickOutside = this.handleClickOutside.bind(this);
        document.addEventListener('click', this.boundHandleClickOutside);

        // Bind ESC key handler
        this.boundHandleEscape = this.handleEscape.bind(this);
        document.addEventListener('keydown', this.boundHandleEscape);
    }

    disconnect() {
        // Clean up event listeners
        document.removeEventListener('click', this.boundHandleClickOutside);
        document.removeEventListener('keydown', this.boundHandleEscape);
    }

    /**
     * Toggle dropdown menu visibility
     */
    toggle(event) {
        event.stopPropagation();

        if (this.menuTarget.style.display === 'none' || !this.menuTarget.style.display) {
            this.open();
        } else {
            this.close();
        }
    }

    /**
     * Open dropdown menu
     */
    open() {
        this.menuTarget.style.display = 'block';
        this.buttonTarget.classList.add('active');

        // Set ARIA attributes
        this.buttonTarget.setAttribute('aria-expanded', 'true');

        // Reset search and experimental state
        if (this.hasSearchInputTarget) {
            this.searchInputTarget.value = '';
            setTimeout(() => this.searchInputTarget.focus(), 0);
        }

        this.experimentalExpanded = false;
        this.applyExperimentalVisibility();

        if (this.hasShowMoreTarget) {
            this.showMoreTarget.style.display = '';
        }
    }

    /**
     * Close dropdown menu
     */
    close() {
        this.menuTarget.style.display = 'none';
        this.buttonTarget.classList.remove('active');

        // Set ARIA attributes
        this.buttonTarget.setAttribute('aria-expanded', 'false');

        // Reset search
        if (this.hasSearchInputTarget) {
            this.searchInputTarget.value = '';
        }

        // Reset experimental visibility
        this.experimentalExpanded = false;
        this.applyExperimentalVisibility();
    }

    /**
     * Filter items based on search input
     */
    filter() {
        const query = this.searchInputTarget.value.toLowerCase().trim();
        const isSearching = query.length > 0;

        this.itemTargets.forEach((item) => {
            const name = (item.dataset.name || '').toLowerCase();
            const matchesSearch = name.includes(query);

            if (isSearching) {
                item.style.display = matchesSearch ? '' : 'none';
            } else {
                // No search: respect experimental visibility
                const isExperimental = item.dataset.experimental === 'true';
                item.style.display = (isExperimental && !this.experimentalExpanded) ? 'none' : '';
            }
        });

        // Hide show-more button while searching
        if (this.hasShowMoreTarget) {
            this.showMoreTarget.style.display = isSearching ? 'none' : '';
        }
    }

    /**
     * Toggle visibility of experimental items
     */
    toggleExperimental() {
        this.experimentalExpanded = !this.experimentalExpanded;
        this.applyExperimentalVisibility();
    }

    /**
     * Apply experimental visibility based on current state
     */
    applyExperimentalVisibility() {
        this.itemTargets.forEach((item) => {
            if (item.dataset.experimental === 'true') {
                item.style.display = this.experimentalExpanded ? '' : 'none';
            }
        });
    }

    /**
     * Handle clicks outside the dropdown
     */
    handleClickOutside(event) {
        if (!this.element.contains(event.target)) {
            this.close();
        }
    }

    /**
     * Handle ESC key to close dropdown
     */
    handleEscape(event) {
        if (event.key === 'Escape' && this.menuTarget.style.display !== 'none') {
            this.close();
            this.buttonTarget.focus();
        }
    }
}
