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
     * Test task execution - shows result in modal
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
            this.showModal(
                data.success ? 'success' : 'error',
                data.success ? 'Test Successful' : 'Test Failed',
                data.success
                    ? `Webhook responded with HTTP ${data.httpStatusCode} in ${(data.duration / 1000).toFixed(1)}s`
                    : (data.errorMessage || 'The test execution failed.'),
                data.output
            );
        } catch (error) {
            this.showModal('error', 'Test Error', 'An unexpected error occurred while testing.', null);
        } finally {
            button.disabled = false;
            button.innerHTML = originalContent;
        }
    }

    /**
     * Run task now
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
            this.showModal(
                data.success ? 'success' : 'error',
                data.success ? 'Execution Successful' : 'Execution Failed',
                data.success
                    ? `Webhook responded with HTTP ${data.httpStatusCode} in ${(data.duration / 1000).toFixed(1)}s`
                    : (data.errorMessage || 'The execution failed.'),
                data.output
            );
        } catch (error) {
            this.showModal('error', 'Execution Error', 'An unexpected error occurred.', null);
        } finally {
            button.disabled = false;
            button.innerHTML = originalContent;
        }
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
     * View execution output in modal
     */
    viewOutput(event) {
        const button = event.currentTarget;
        const output = button.dataset.output;
        const status = button.dataset.execStatus;
        const taskName = button.dataset.taskName;

        this.showModal(
            status === 'success' ? 'success' : 'error',
            taskName,
            status === 'success' ? 'Execution completed successfully' : 'Execution failed',
            output
        );
    }

    /**
     * Show modal with execution result
     */
    showModal(type, title, message, output) {
        const modal = document.getElementById('scheduledTaskModal');
        if (!modal) return;

        const contentWrapper = document.getElementById('stModalContentWrapper');
        const icon = document.getElementById('stModalIcon');
        const modalTitle = document.getElementById('stModalTitle');
        const modalMessage = document.getElementById('stModalMessage');
        const modalOutput = document.getElementById('stModalOutput');
        const closeBtn = document.getElementById('stModalCloseBtn');

        modalTitle.textContent = title;
        modalMessage.textContent = message;
        modalMessage.className = 'modal-message ' + type;
        contentWrapper.className = 'modal-content status-' + type;
        closeBtn.className = 'btn btn-status-' + type;

        icon.className = 'modal-icon ' + type;
        icon.innerHTML = type === 'success'
            ? '<i class="fas fa-check-circle"></i>'
            : '<i class="fas fa-exclamation-circle"></i>';

        if (output) {
            modalOutput.textContent = output;
            modalOutput.classList.remove('hidden');
        } else {
            modalOutput.classList.add('hidden');
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
