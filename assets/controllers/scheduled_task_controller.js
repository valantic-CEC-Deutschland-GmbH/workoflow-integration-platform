import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['frequencySelect', 'timeField', 'weekdayField', 'row'];

    connect() {
        this.resumePendingPolling();
    }

    /**
     * On page load, find any existing pending rows and start polling them
     */
    resumePendingPolling() {
        const pendingRows = document.querySelectorAll('[data-pending-execution-id]');
        pendingRows.forEach(row => {
            const executionId = row.dataset.pendingExecutionId;
            this.pollExecution(executionId);
        });
    }

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
     * Test task execution - dispatches async and adds inline pending row
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
                this.addPendingRow(data.executionId, data.taskName, 'test');
                this.pollExecution(data.executionId);
            } else {
                this.showToast('error', data.message || 'The test execution failed.');
            }
        } catch (error) {
            this.showToast('error', 'An unexpected error occurred while testing.');
        } finally {
            button.disabled = false;
            button.innerHTML = originalContent;
        }
    }

    /**
     * Run task now - dispatches async and adds inline pending row
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
                this.addPendingRow(data.executionId, data.taskName, 'manual');
                this.pollExecution(data.executionId);
            } else {
                this.showToast('error', data.message || 'The execution failed.');
            }
        } catch (error) {
            this.showToast('error', 'An unexpected error occurred.');
        } finally {
            button.disabled = false;
            button.innerHTML = originalContent;
        }
    }

    /**
     * Add a pending row to the top of the execution history table
     */
    addPendingRow(executionId, taskName, trigger) {
        // Ensure execution history section is visible
        const section = document.getElementById('execution-history-section');
        if (section) {
            section.style.display = '';
        }

        const tbody = document.getElementById('executions-tbody');
        if (!tbody) return;

        const now = new Date();
        const timestamp = now.getFullYear() + '-' +
            String(now.getMonth() + 1).padStart(2, '0') + '-' +
            String(now.getDate()).padStart(2, '0') + ' ' +
            String(now.getHours()).padStart(2, '0') + ':' +
            String(now.getMinutes()).padStart(2, '0') + ':' +
            String(now.getSeconds()).padStart(2, '0');

        const row = document.createElement('tr');
        row.id = `execution-row-${executionId}`;
        row.innerHTML = `
            <td>${this.escapeHtml(taskName)}</td>
            <td><span class="badge badge-trigger badge-${trigger}">${trigger}</span></td>
            <td><span class="badge badge-status badge-pending"><i class="fas fa-spinner fa-spin"></i> pending</span></td>
            <td>${timestamp}</td>
            <td>\u2014</td>
            <td><span class="text-muted"><i class="fas fa-spinner fa-spin"></i></span></td>
            <td class="actions-cell"></td>
        `;

        tbody.insertBefore(row, tbody.firstChild);
    }

    /**
     * Poll execution status until complete, then update the row in-place
     */
    async pollExecution(executionId) {
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
                        this.showToast('error', 'Execution is still running. Check back later.');
                        return;
                    }
                    setTimeout(poll, 3000);
                    return;
                }

                this.updateExecutionRow(executionId, data);
            } catch (error) {
                this.showToast('error', 'Failed to check execution status.');
            }
        };

        setTimeout(poll, 3000);
    }

    /**
     * Update a pending execution row in-place with completed data
     */
    updateExecutionRow(executionId, data) {
        const row = document.getElementById(`execution-row-${executionId}`);
        if (!row) return;

        const cells = row.querySelectorAll('td');
        const isSuccess = data.status === 'success';

        // Update status badge (3rd cell)
        cells[2].innerHTML = `<span class="badge badge-status badge-${data.status}">${data.status}</span>`;

        // Update duration (5th cell)
        cells[4].textContent = data.duration ? `${(data.duration / 1000).toFixed(1)}s` : '\u2014';

        // Update output (6th cell) — add View button
        const taskName = cells[0].textContent.trim();
        cells[5].innerHTML = `
            <button type="button" class="btn btn-sm btn-outline"
                    data-action="click->scheduled-task#viewOutput"
                    data-execution-id="${executionId}"
                    data-exec-status="${data.status}"
                    data-task-name="${this.escapeHtml(taskName)}"
                    data-output="">
                <i class="fas fa-eye"></i> View
            </button>
        `;

        // Show toast
        this.showToast(
            isSuccess ? 'success' : 'error',
            isSuccess ? 'Execution completed successfully' : 'Execution failed'
        );
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

    /**
     * Escape HTML to prevent XSS in dynamic content
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}
