import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['frequencySelect', 'timeField', 'weekdayField', 'row'];

    /**
     * Show/hide conditional fields based on frequency selection
     */
    frequencyChanged() {
        const frequency = this.frequencySelectTarget.value;
        const showTime = ['daily', 'weekdays', 'weekly'].includes(frequency);
        const showWeekday = frequency === 'weekly';

        if (this.hasTimeFieldTarget) {
            this.timeFieldTarget.style.display = showTime ? '' : 'none';
        }
        if (this.hasWeekdayFieldTarget) {
            this.weekdayFieldTarget.style.display = showWeekday ? '' : 'none';
        }
    }

    /**
     * Test task execution - dispatches async and polls for result
     */
    async testTask(event) {
        const button = event.currentTarget;
        const uuid = button.dataset.uuid;
        const originalContent = button.innerHTML;

        button.disabled = true;
        button.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

        try {
            const response = await fetch(`/scheduled-tasks/${uuid}/test`, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            const data = await response.json();

            if (response.status === 202 && data.executionId) {
                this.showModal('pending', 'Test Started', 'Task dispatched. Waiting for result...', null);
                this.pollExecution(data.executionId, 'Test');
            } else {
                this.showModal('error', 'Test Failed', data.message || 'The test execution failed.', null);
            }
        } catch (error) {
            this.showModal('error', 'Test Error', 'An unexpected error occurred while testing.', null);
        } finally {
            button.disabled = false;
            button.innerHTML = originalContent;
        }
    }

    /**
     * Run task now - dispatches async and polls for result
     */
    async runNow(event) {
        const button = event.currentTarget;
        const uuid = button.dataset.uuid;
        const originalContent = button.innerHTML;

        button.disabled = true;
        button.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

        try {
            const response = await fetch(`/scheduled-tasks/${uuid}/run`, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            const data = await response.json();

            if (response.status === 202 && data.executionId) {
                this.showModal('pending', 'Execution Started', 'Task dispatched. Waiting for result...', null);
                this.pollExecution(data.executionId, 'Execution');
            } else {
                this.showModal('error', 'Execution Failed', data.message || 'The execution failed.', null);
            }
        } catch (error) {
            this.showModal('error', 'Execution Error', 'An unexpected error occurred.', null);
        } finally {
            button.disabled = false;
            button.innerHTML = originalContent;
        }
    }

    /**
     * Poll execution status until complete
     */
    async pollExecution(executionId, label) {
        const maxAttempts = 60;
        let attempts = 0;

        const poll = async () => {
            attempts++;

            try {
                const response = await fetch(`/scheduled-tasks/execution/${executionId}/status`, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                const data = await response.json();

                if (data.status === 'pending') {
                    if (attempts >= maxAttempts) {
                        this.showModal('error', `${label} Timeout`, 'The execution is still running. Check back later.', null);
                        return;
                    }
                    // Update modal message with elapsed time
                    const modalMessage = document.getElementById('stModalMessage');
                    if (modalMessage) {
                        modalMessage.textContent = `Task dispatched. Waiting for result... (${attempts * 3}s)`;
                    }
                    setTimeout(poll, 3000);
                    return;
                }

                // Execution completed - fetch rendered output
                const outputResponse = await fetch(`/scheduled-tasks/execution/${executionId}/output`, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                const outputData = await outputResponse.json();

                const isSuccess = data.status === 'success';
                const message = isSuccess
                    ? `Completed in ${(data.duration / 1000).toFixed(1)}s (HTTP ${data.httpStatusCode})`
                    : (data.errorMessage || 'The execution failed.');

                this.showModal(
                    isSuccess ? 'success' : 'error',
                    `${label} ${isSuccess ? 'Successful' : 'Failed'}`,
                    message,
                    null,
                    outputData.html
                );

                // Reload to refresh execution history
                setTimeout(() => window.location.reload(), 1000);
            } catch (error) {
                this.showModal('error', `${label} Error`, 'Failed to check execution status.', null);
            }
        };

        setTimeout(poll, 3000);
    }

    /**
     * Confirm delete
     */
    confirmDelete(event) {
        if (!confirm('Are you sure you want to delete this scheduled task? This action cannot be undone.')) {
            event.preventDefault();
        }
    }

    /**
     * Confirm delete execution history entry
     */
    confirmDeleteExecution(event) {
        if (!confirm('Are you sure you want to delete this execution record?')) {
            event.preventDefault();
        }
    }

    /**
     * Toggle task active state
     */
    async toggleActive(event) {
        const button = event.currentTarget;
        const uuid = button.dataset.uuid;

        try {
            const response = await fetch(`/scheduled-tasks/${uuid}/toggle`, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            const data = await response.json();
            if (data.success) {
                const icon = button.querySelector('i');
                if (data.active) {
                    button.classList.add('active');
                    icon.className = 'fas fa-toggle-on';
                    button.childNodes[button.childNodes.length - 1].textContent = ' Active';
                } else {
                    button.classList.remove('active');
                    icon.className = 'fas fa-toggle-off';
                    button.childNodes[button.childNodes.length - 1].textContent = ' Inactive';
                }
                this.showToast('success', `Task ${data.active ? 'activated' : 'deactivated'}`);
            }
        } catch (error) {
            this.showToast('error', 'Failed to toggle task status');
        }
    }

    /**
     * View execution output - fetches rendered HTML from server
     */
    async viewOutput(event) {
        const button = event.currentTarget;
        const executionId = button.dataset.executionId;
        const status = button.dataset.execStatus;
        const taskName = button.dataset.taskName;

        if (status === 'pending') {
            this.showModal('pending', taskName, 'Execution is still running...', null);
            return;
        }

        try {
            const response = await fetch(`/scheduled-tasks/execution/${executionId}/output`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const data = await response.json();

            this.showModal(
                status === 'success' ? 'success' : 'error',
                taskName,
                status === 'success' ? 'Execution completed successfully' : 'Execution failed',
                null,
                data.html
            );
        } catch (error) {
            // Fallback to raw data attribute if fetch fails
            const output = button.dataset.output;
            this.showModal(
                status === 'success' ? 'success' : 'error',
                taskName,
                status === 'success' ? 'Execution completed successfully' : 'Execution failed',
                output
            );
        }
    }

    /**
     * Show modal with execution result
     */
    showModal(type, title, message, output, renderedHtml) {
        const modal = document.getElementById('scheduledTaskModal');
        if (!modal) return;

        const contentWrapper = document.getElementById('stModalContentWrapper');
        const icon = document.getElementById('stModalIcon');
        const modalTitle = document.getElementById('stModalTitle');
        const modalMessage = document.getElementById('stModalMessage');
        const modalOutput = document.getElementById('stModalOutput');
        const closeBtn = document.getElementById('stModalCloseBtn');

        // Map pending to a visual type
        const visualType = type === 'pending' ? 'pending' : type;

        modalTitle.textContent = title;
        modalMessage.textContent = message;
        modalMessage.className = 'modal-message ' + visualType;
        contentWrapper.className = 'modal-content status-' + visualType;
        closeBtn.className = 'btn btn-status-' + visualType;

        if (type === 'pending') {
            icon.className = 'modal-icon pending';
            icon.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        } else {
            icon.className = 'modal-icon ' + type;
            icon.innerHTML = type === 'success'
                ? '<i class="fas fa-check-circle"></i>'
                : '<i class="fas fa-exclamation-circle"></i>';
        }

        if (renderedHtml) {
            modalOutput.innerHTML = renderedHtml;
            modalOutput.classList.remove('hidden');
            modalOutput.classList.add('rendered-output');
        } else if (output) {
            modalOutput.textContent = output;
            modalOutput.classList.remove('hidden', 'rendered-output');
        } else {
            modalOutput.classList.add('hidden');
            modalOutput.classList.remove('rendered-output');
        }

        modal.classList.add('show');
    }

    /**
     * Show toast notification
     */
    showToast(type, message) {
        let toast = document.getElementById('toast-notification');
        if (!toast) {
            toast = document.createElement('div');
            toast.id = 'toast-notification';
            toast.className = 'toast-notification';
            document.body.appendChild(toast);
        }

        toast.textContent = message;
        toast.className = `toast-notification toast-${type} show`;

        setTimeout(() => {
            toast.classList.remove('show');
        }, 3000);
    }
}
