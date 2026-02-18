import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['webhookType', 'webhookUrl', 'workflowUrl', 'workflowColumn', 'workflowContainer', 'n8nApiKeyGroup', 'workflowUrlGroup', 'workflowModal'];

    connect() {
        console.log('Channel controller connected');

        // Check if we should initialize workflow visualization on page load
        if (this.webhookTypeTarget.value === 'N8N' && this.hasWorkflowUrlTarget && this.workflowUrlTarget.value) {
            this.showWorkflowVisualization();
            this.loadWorkflow();
        }

        // Add keyboard listener for ESC key
        this.boundHandleKeydown = this.handleKeydown.bind(this);
        document.addEventListener('keydown', this.boundHandleKeydown);

        // Add click outside modal listener
        this.boundHandleClickOutside = this.handleClickOutside.bind(this);
    }

    disconnect() {
        // Clean up event listeners
        document.removeEventListener('keydown', this.boundHandleKeydown);
    }

    // Called when webhook type changes
    onWebhookTypeChange(event) {
        const webhookType = event.target.value;

        if (webhookType === 'N8N') {
            this.showWorkflowVisualization();
            // Show N8N API key field and workflow URL field
            if (this.hasN8nApiKeyGroupTarget) {
                this.n8nApiKeyGroupTarget.style.display = 'block';
            }
            if (this.hasWorkflowUrlGroupTarget) {
                this.workflowUrlGroupTarget.style.display = 'block';
            }
        } else {
            this.hideWorkflowVisualization();
            // Hide N8N API key field and workflow URL field
            if (this.hasN8nApiKeyGroupTarget) {
                this.n8nApiKeyGroupTarget.style.display = 'none';
            }
            if (this.hasWorkflowUrlGroupTarget) {
                this.workflowUrlGroupTarget.style.display = 'none';
            }
        }
    }

    // Called when webhook URL changes (with debouncing) - kept for backward compatibility
    onWebhookUrlChange(event) {
        // This is kept but doesn't trigger visualization reload
        // Visualization reload is triggered by workflowUrl change
    }

    // Called when workflow URL changes (with debouncing)
    onWorkflowUrlChange(event) {
        // Clear existing timeout
        if (this.urlChangeTimeout) {
            clearTimeout(this.urlChangeTimeout);
        }

        // Debounce the URL change to avoid too many API calls
        this.urlChangeTimeout = setTimeout(() => {
            if (this.webhookTypeTarget.value === 'N8N' && event.target.value) {
                this.refreshWorkflowVisualization();
            }
        }, 1000); // Wait 1 second after user stops typing
    }

    showWorkflowVisualization() {
        // Show the workflow column
        this.workflowColumnTarget.classList.remove('hidden');
    }

    hideWorkflowVisualization() {
        // Hide the workflow column
        this.workflowColumnTarget.classList.add('hidden');
    }

    async loadWorkflow() {
        // Show loading state
        const loadingEl = document.getElementById('workflow-loading');
        const errorEl = document.getElementById('workflow-error');
        const placeholderEl = document.getElementById('workflow-placeholder');
        const containerEl = document.getElementById('workflow-container');
        const viewer = document.getElementById('workflow-viewer');

        // Hide all except loading
        if (loadingEl) loadingEl.classList.remove('hidden');
        if (errorEl) errorEl.classList.add('hidden');
        if (placeholderEl) placeholderEl.classList.add('hidden');
        if (containerEl) containerEl.classList.add('hidden');

        try {
            const orgId = this.workflowContainerTarget.dataset.orgId;
            const workflowUrl = this.workflowContainerTarget.dataset.workflowUrl;

            if (!workflowUrl) {
                this.showPlaceholder();
                return;
            }

            // Fetch workflow from API
            const response = await fetch(`/channel/api/n8n-workflow/${orgId}`);
            const data = await response.json();

            if (data.error || !data.workflow) {
                this.showError(data.error || 'Failed to load workflow');
                return;
            }

            // Set workflow data on n8n-demo component
            if (viewer) {
                viewer.workflow = JSON.stringify(data.workflow);
            }

            // Store workflow data for modal reuse
            this.workflowData = data.workflow;

            // Show the workflow container
            if (loadingEl) loadingEl.classList.add('hidden');
            if (containerEl) containerEl.classList.remove('hidden');

            console.log('Workflow loaded successfully');
        } catch (error) {
            console.error('Error loading workflow:', error);
            this.showError('Failed to fetch workflow: ' + error.message);
        }
    }

    refreshWorkflowVisualization() {
        // Reload the workflow
        this.loadWorkflow();
    }

    showPlaceholder() {
        const loadingEl = document.getElementById('workflow-loading');
        const errorEl = document.getElementById('workflow-error');
        const placeholderEl = document.getElementById('workflow-placeholder');
        const containerEl = document.getElementById('workflow-container');

        if (loadingEl) loadingEl.classList.add('hidden');
        if (errorEl) errorEl.classList.add('hidden');
        if (placeholderEl) placeholderEl.classList.remove('hidden');
        if (containerEl) containerEl.classList.add('hidden');
    }

    showError(message) {
        const loadingEl = document.getElementById('workflow-loading');
        const errorEl = document.getElementById('workflow-error');
        const errorMessageEl = document.getElementById('workflow-error-message');
        const placeholderEl = document.getElementById('workflow-placeholder');
        const containerEl = document.getElementById('workflow-container');

        if (loadingEl) loadingEl.classList.add('hidden');
        if (errorEl) errorEl.classList.remove('hidden');
        if (errorMessageEl) errorMessageEl.textContent = message;
        if (placeholderEl) placeholderEl.classList.add('hidden');
        if (containerEl) containerEl.classList.add('hidden');
    }

    // Handle form submission via AJAX (optional enhancement)
    async submitForm(event) {
        event.preventDefault();

        const form = event.target;
        const formData = new FormData(form);

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: formData,
            });

            if (response.ok) {
                // Show success message
                this.showFlashMessage('Settings saved successfully', 'success');

                // Refresh workflow if N8N and URL changed
                if (this.webhookTypeTarget.value === 'N8N') {
                    this.refreshWorkflowVisualization();
                }
            } else {
                this.showFlashMessage('Failed to save settings', 'error');
            }
        } catch (error) {
            console.error('Error submitting form:', error);
            this.showFlashMessage('An error occurred while saving', 'error');
        }
    }

    showFlashMessage(message, type) {
        // Create and show a flash message
        const alertDiv = document.createElement('div');
        alertDiv.className = `alert alert-${type}`;
        alertDiv.textContent = message;
        alertDiv.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px 20px;
            background-color: ${type === 'success' ? '#10b981' : '#ef4444'};
            color: white;
            border-radius: 6px;
            z-index: 1000;
            animation: slideIn 0.3s ease-out;
        `;

        document.body.appendChild(alertDiv);

        // Remove after 3 seconds
        setTimeout(() => {
            alertDiv.remove();
        }, 3000);
    }

    // Modal Methods
    openWorkflowModal() {
        if (!this.hasWorkflowModalTarget) {
            console.error('Modal target not found');
            return;
        }

        // Check if workflow data is available
        if (!this.workflowData) {
            console.warn('No workflow data available yet');
            return;
        }

        console.log('Opening workflow modal with data:', this.workflowData);

        // Show the modal first
        this.workflowModalTarget.classList.add('show');

        // Recreate the n8n-demo component to ensure proper initialization while visible
        const container = document.getElementById('modal-workflow-container');
        if (container) {
            console.log('Recreating n8n-demo component in modal');

            // Remove existing component if present
            container.innerHTML = '';

            // Create fresh n8n-demo element
            const newViewer = document.createElement('n8n-demo');
            newViewer.id = 'modal-workflow-viewer';
            newViewer.setAttribute('theme', 'dark');

            // Append to container
            container.appendChild(newViewer);

            // Wait for component to initialize, then set workflow data
            setTimeout(() => {
                console.log('Setting workflow data on recreated component');
                newViewer.workflow = JSON.stringify(this.workflowData);
            }, 500);
        } else {
            console.error('Modal workflow container not found');
        }

        // Add click outside listener
        setTimeout(() => {
            document.addEventListener('click', this.boundHandleClickOutside);
        }, 100);
    }

    closeWorkflowModal() {
        if (!this.hasWorkflowModalTarget) {
            return;
        }

        // Hide the modal
        this.workflowModalTarget.classList.remove('show');

        // Remove click outside listener
        document.removeEventListener('click', this.boundHandleClickOutside);
    }

    handleKeydown(event) {
        // Close modal on ESC key
        if (event.key === 'Escape' && this.hasWorkflowModalTarget && this.workflowModalTarget.classList.contains('show')) {
            this.closeWorkflowModal();
        }
    }

    handleClickOutside(event) {
        // Close modal when clicking outside the modal content
        if (this.hasWorkflowModalTarget && event.target === this.workflowModalTarget) {
            this.closeWorkflowModal();
        }
    }
}
