(() => {
    const icons = {
        view: '<svg class="ui-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M2.5 12s3.6-6 9.5-6 9.5 6 9.5 6-3.6 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="2.7"/></svg>',
        edit: '<svg class="ui-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 16.8V20h3.2L18.7 8.5l-3.2-3.2L4 16.8Z"/><path d="m14.8 6 3.2 3.2"/></svg>',
        delete: '<svg class="ui-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 7h16"/><path d="M9 7V4h6v3"/><path d="m6.5 7 .8 13h9.4l.8-13"/><path d="M10 11v5M14 11v5"/></svg>',
    };

    const enhance = (element, kind) => {
        if (!element || element.dataset.iconAction) return;
        const label = element.textContent.trim();
        if (!label) return;
        element.dataset.iconAction = kind;
        element.classList.add('icon-action');
        if (kind === 'delete') element.classList.add('danger');
        element.setAttribute('title', label);
        element.setAttribute('aria-label', label);
        element.innerHTML = icons[kind];
    };

    document.querySelectorAll('.row-actions [data-task-preview], .row-actions [data-admin-user-detail]').forEach((element) => enhance(element, 'view'));
    document.querySelectorAll('.row-actions a[href$="/edit"]').forEach((element) => enhance(element, 'edit'));
    document.querySelectorAll('.row-actions form[action$="/delete"] button, .attachment-row form[action$="/delete"] button, .metadata-actions form[action$="/delete"] button').forEach((element) => enhance(element, 'delete'));
})();
