(() => {
    const dialog = document.getElementById('task-preview-dialog');
    if (!(dialog instanceof HTMLDialogElement)) return;

    const title = dialog.querySelector('[data-task-preview-title]');
    const meta = dialog.querySelector('[data-task-preview-meta]');
    const description = dialog.querySelector('[data-task-preview-description]');
    const details = dialog.querySelector('[data-task-preview-details]');
    const custom = dialog.querySelector('[data-task-preview-custom]');
    const customList = dialog.querySelector('[data-task-preview-custom-list]');
    const attachments = dialog.querySelector('[data-task-preview-attachments]');
    const attachmentList = dialog.querySelector('[data-task-preview-attachment-list]');
    const error = dialog.querySelector('[data-task-preview-error]');
    const edit = dialog.querySelector('[data-task-preview-edit]');
    const deleteForm = dialog.querySelector('[data-task-preview-delete-form]');

    const labels = {
        loading: dialog.dataset.labelLoading, failed: dialog.dataset.labelFailed,
        noDescription: dialog.dataset.labelNoDescription, status: dialog.dataset.labelStatus,
        type: dialog.dataset.labelType, priority: dialog.dataset.labelPriority,
        customer: dialog.dataset.labelCustomer, deadline: dialog.dataset.labelDeadline,
        created: dialog.dataset.labelCreated, updated: dialog.dataset.labelUpdated,
        deleteConfirm: dialog.dataset.labelDeleteConfirm,
    };
    const text = (value) => value === null || value === undefined || value === '' ? '—' : String(value);
    const dateTime = (value) => {
        if (!value) return '—';
        const match = String(value).match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
        if (!match) return text(value);
        return document.documentElement.lang === 'ru'
            ? `${match[3]}.${match[2]}.${match[1]} ${match[4]}:${match[5]}`
            : `${match[1]}-${match[2]}-${match[3]} ${match[4]}:${match[5]}`;
    };
    const valueNode = (value, kind, data = {}) => {
        if (kind === 'status' && value) {
            const badge = document.createElement('span'); badge.className = 'status-badge';
            badge.style.setProperty('--status-color', data.statusColor || 'var(--status-neutral)');
            const dot = document.createElement('span'); dot.className = 'status-dot';
            const label = document.createElement('span'); label.textContent = text(value);
            badge.append(dot, label); return badge;
        }
        if (kind === 'priority' && value) {
            const badge = document.createElement('span');
            const level = Number(data.priorityValue);
            badge.className = `priority priority-${Number.isInteger(level) ? level : 1}`;
            badge.textContent = text(value); return badge;
        }
        const span = document.createElement('span'); span.textContent = text(value); return span;
    };
    const addFact = (label, value, kind = '', data = {}) => {
        const item = document.createElement('div'); item.className = 'task-preview-fact';
        const name = document.createElement('span'); name.className = 'task-preview-fact-label'; name.textContent = label;
        const content = document.createElement('div'); content.className = 'task-preview-fact-value'; content.append(valueNode(value, kind, data));
        item.append(name, content); details.append(item);
    };
    const addCustomRow = (label, value) => {
        const dt = document.createElement('dt'); const dd = document.createElement('dd');
        dt.textContent = label; dd.textContent = text(value); customList.append(dt, dd);
    };
    const formatBytes = (value) => {
        const bytes = Number(value); if (!Number.isFinite(bytes) || bytes < 1) return '';
        if (bytes < 1024) return `${bytes} B`;
        if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KiB`;
        return `${(bytes / (1024 * 1024)).toFixed(1)} MiB`;
    };
    const reset = () => {
        error.hidden = true; details.replaceChildren(); customList.replaceChildren(); custom.hidden = true;
        attachmentList.replaceChildren(); attachments.hidden = true; deleteForm.hidden = true; deleteForm.action = '/tasks';
        title.textContent = labels.loading || '…'; meta.textContent = ''; description.textContent = '';
    };

    const open = async (taskId) => {
        reset(); dialog.showModal();
        try {
            const response = await fetch(`/api/tasks/${encodeURIComponent(taskId)}`, {headers: {Accept: 'application/json'}});
            const data = await response.json(); if (!response.ok) throw new Error(data.error || labels.failed || 'Unable to load task.');
            title.textContent = data.title; meta.textContent = `#${data.id}`;
            description.textContent = data.description || labels.noDescription || '';
            addFact(labels.status || 'Status', data.status, 'status', {statusColor: data.status_color});
            addFact(labels.created || 'Created', dateTime(data.created_at));
            addFact(labels.type || 'Type', data.type);
            addFact(labels.updated || 'Updated', dateTime(data.updated_at));
            addFact(labels.priority || 'Priority', data.priority, 'priority', {priorityValue: data.priority_value});
            addFact(labels.deadline || 'Deadline', dateTime(data.deadline));
            addFact(labels.customer || 'Customer', data.customer);
            if (Array.isArray(data.custom_fields) && data.custom_fields.length) {
                for (const field of data.custom_fields) addCustomRow(field.name, field.value);
                custom.hidden = false;
            }
            if (Array.isArray(data.attachments) && data.attachments.length) {
                for (const file of data.attachments) {
                    const item = document.createElement('li'); const link = document.createElement('a');
                    link.href = file.download_url; link.textContent = text(file.name); item.append(link);
                    const size = formatBytes(file.file_size);
                    if (size) { const sizeLabel = document.createElement('span'); sizeLabel.className = 'muted'; sizeLabel.textContent = ` (${size})`; item.append(sizeLabel); }
                    attachmentList.append(item);
                }
                attachments.hidden = false;
            }
            edit.href = data.edit_url; deleteForm.action = data.delete_url; deleteForm.hidden = false;
        } catch (problem) {
            error.textContent = problem instanceof Error ? problem.message : (labels.failed || 'Unable to load task.'); error.hidden = false;
        }
    };

    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-task-preview]');
        if (trigger) { event.preventDefault(); const id = trigger.getAttribute('data-task-preview'); if (id) void open(id); return; }
        if (event.target.closest('[data-task-preview-close]')) dialog.close();
    });
    deleteForm.addEventListener('submit', (event) => { if (!window.confirm(labels.deleteConfirm || 'Delete this task?')) event.preventDefault(); });
    dialog.addEventListener('click', (event) => { if (event.target === dialog) dialog.close(); });
})();
