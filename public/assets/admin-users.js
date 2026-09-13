(() => {
    const dialog = document.getElementById('admin-user-detail-dialog');
    if (!(dialog instanceof HTMLDialogElement)) return;
    const body = dialog.querySelector('[data-admin-user-detail-body]');
    const error = dialog.querySelector('[data-admin-user-detail-error]');
    const edit = dialog.querySelector('[data-admin-user-detail-edit]');
    const loading = dialog.dataset.loading || 'Loading…';
    const failed = dialog.dataset.failed || 'Unable to load user details.';
    const labels = {
        id: 'ID', username: dialog.dataset.username || 'Username', email: dialog.dataset.email || 'Email',
        role: dialog.dataset.role || 'Role', active: dialog.dataset.active || 'Active',
        email_verified: dialog.dataset.verified || 'Email verified', approved: dialog.dataset.approved || 'Approved',
        created_at: dialog.dataset.created || 'Created',
    };
    const yes = dialog.dataset.yes || 'Yes';
    const no = dialog.dataset.no || 'No';
    const add = (key, value) => {
        const dt = document.createElement('dt'); const dd = document.createElement('dd');
        dt.textContent = labels[key] || key;
        dd.textContent = ['active','email_verified','approved'].includes(key) ? (value ? yes : no) : (value ?? '—');
        body.append(dt, dd);
    };
    const open = async (id) => {
        body.replaceChildren(); error.hidden = true; edit.hidden = true; dialog.showModal();
        const dt=document.createElement('dt'); const dd=document.createElement('dd'); dt.textContent=''; dd.textContent=loading; body.append(dt,dd);
        try {
            const response = await fetch(`/api/admin/users/${encodeURIComponent(id)}`, {headers:{Accept:'application/json'}});
            const data = await response.json(); if (!response.ok) throw new Error(data.error || failed);
            body.replaceChildren();
            for (const key of ['id','username','email','role','active','email_verified','approved','created_at']) add(key,data[key]);
            edit.href=data.edit_url; edit.hidden=false;
        } catch (problem) {
            body.replaceChildren(); error.textContent=problem instanceof Error ? problem.message : failed; error.hidden=false;
        }
    };
    document.addEventListener('click', (event) => {
        const trigger=event.target.closest('[data-admin-user-detail]');
        if (trigger) { event.preventDefault(); void open(trigger.getAttribute('data-admin-user-detail')); return; }
        if (event.target.closest('[data-admin-user-detail-close]')) dialog.close();
    });
    dialog.addEventListener('click', (event) => { if (event.target === dialog) dialog.close(); });
})();
