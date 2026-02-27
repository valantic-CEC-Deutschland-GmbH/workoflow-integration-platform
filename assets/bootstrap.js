import { Application } from '@hotwired/stimulus';

// Create the Stimulus application
const application = Application.start();

// Register the channel controller
import channelController from './controllers/channel_controller';
application.register('channel', channelController);

// Register the flash message controller
import flashMessageController from './controllers/flash_message_controller';
application.register('flash-message', flashMessageController);

// Register the skills table controller
import skillsTableController from './controllers/skills_table_controller';
application.register('skills-table', skillsTableController);

// Register the integration dropdown controller
import integrationDropdownController from './controllers/integration_dropdown_controller';
application.register('integration-dropdown', integrationDropdownController);

// Register the audit log controller
import auditLogController from './controllers/audit_log_controller';
application.register('audit-log', auditLogController);

// Register the json modal controller
import jsonModalController from './controllers/json_modal_controller';
application.register('json-modal', jsonModalController);

// Register the nav dropdown controller
import navDropdownController from './controllers/nav_dropdown_controller';
application.register('nav-dropdown', navDropdownController);

// Register the scheduled task controller
import scheduledTaskController from './controllers/scheduled_task_controller';
application.register('scheduled-task', scheduledTaskController);

// Export for debugging
window.Stimulus = application;

export { application };