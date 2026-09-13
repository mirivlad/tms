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
        loading: dialog.dataset.labelLoading,
        failed: dialog.dataset.labelFailed,
        noDescription: dialog.dataset.labelNoDescription,
        status: dialog.dataset.labelStatus,
        type: dialog.dataset.labelType,
        priority: dialog.dataset.labelPriority,
        customer: dialog.dataset.labelCustomer,
        deadline: dialog.dataset.labelDeadline,
        updated: dialog.dataset.labelUpdated,
        deleteConfirm: dialog.dataset.labelDeleteConfirm,
    };
    const text = (value) => value === null || value === undefined || value === '' ? '—' : String(value);
    const addRow = (list, label, value) => {
        const dt = document.createElement('dt');
        const dd = document.createElement('dd');
        dt.textContent = label;
        dd.textContent = text(value);
        list.append(dt, dd);
    };
    const formatBytes = (value) => {
        const bytes = Number(value);
        if (!Number.isFinite(bytes) || bytes < 1) return '';
        if (bytes < 1024) return `${bytes} B`;
        if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KiB`;
        return `${(bytes / (1024 * 1024)).toFixed(1)} MiB`;
    };
    const reset = () => {
        error.hidden = true;
        details.replaceChildren();
        customList.replaceChildren();
        custom.hidden = true;
        attachmentList.replaceChildren();
        attachments.hidden = true;
        deleteForm.hidden = true;
        deleteForm.action = '/tasks';
        title.textContent = labels.loading || '…';
        meta.textContent = '';
        description.textContent = '';
    };

    const open = async (taskId) => {
        reset();
        dialog.showModal();
        try {
            const response = await fetch(`/api/tasks/${encodeURIComponent(taskId)}`, {headers: {'Accept': 'application/json'}});
            const data = await response.json();
            if (!response.ok) throw new Error(data.error || labels.failed || 'Unable to load task.');
            title.textContent = data.title;
            meta.textContent = `#${data.id}`;
            description.textContent = data.description || labels.noDescription || '';
            addRow(details, labels.status || 'Status', data.status);
            addRow(details, labels.type || 'Type', data.type);
            addRow(details, labels.priority || 'Priority', data.priority);
            addRow(details, labels.customer || 'Customer', data.customer);
            addRow(details, labels.deadline || 'Deadline', data.deadline);
            addRow(details, labels.updated || 'Updated', data.updated_at);
            if (Array.isArray(data.custom_fields) && data.custom_fields.length) {
                for (const field of data.custom_fields) addRow(customList, field.name, field.value);
                custom.hidden = false;
            }
            if (Array.isArray(data.attachments) && data.attachments.length) {
                for (const file of data.attachments) {
                    const item = document.createElement('li');
                    const link = document.createElement('a');
                    link.href = file.download_url;
                    link.textContent = text(file.name);
                    const size = formatBytes(file.file_size);
                    item.append(link);
                    if (size) {
                        const sizeLabel = document.createElement('span');
                        sizeLabel.className = 'muted';
                        sizeLabel.textContent = ` (${size})`;
                        item.append(sizeLabel);
                    }
                    attachmentList.append(item);
                }
                attachments.hidden = false;
            }
            edit.href = data.edit_url;
            deleteForm.action = data.delete_url;
            deleteForm.hidden = false;
        } catch (problem) {
            error.textContent = problem instanceof Error ? problem.message : (labels.failed || 'Unable to load task.');
            error.hidden = false;
        }
    };

    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-task-preview]');
        if (trigger) {
            event.preventDefault();
            const id = trigger.getAttribute('data-task-preview');
            if (id) void open(id);
            return;
        }
        if (event.target.closest('[data-task-preview-close]')) dialog.close();
    });

    deleteForm.addEventListener('submit', (event) => {
        if (!window.confirm(labels.deleteConfirm || 'Delete this task?')) event.preventDefault();
    });
    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) dialog.close();
    });
})();
