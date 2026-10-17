(() => {
    const dialog = document.getElementById('quill-create-dialog');
    if (!dialog) return;
    const form = document.getElementById('quill-create-form');
    const status = document.getElementById('quill-create-status');
    const list = document.getElementById('quill-route-parts');
    const template = document.getElementById('quill-route-part-template');
    const orderStatus = document.getElementById('quill-route-order-status');
    const title = document.getElementById('quill-create-title');
    const description = document.getElementById('quill-create-description');
    const createTitle = title.textContent;
    const createDescription = description.textContent;
    const createAction = form.action;
    const kind = new URL(createAction).pathname.split('/').pop();
    const itemId = document.getElementById('quill-item-id');
    const itemVersion = document.getElementById('quill-item-version');
    const info = document.getElementById('quill-item-info');
    const deleteButton = document.getElementById('quill-delete');
    const deleteDialog = document.getElementById('quill-delete-dialog');
    const deleteForm = document.getElementById('quill-delete-form');
    const deleteStatus = document.getElementById('quill-delete-status');
    let managedItem = null;
    let opener;
    let busy = false;
    const builder = list ? new window.QuillRouteBuilder({ scope: dialog, list, template,
        preview: document.getElementById('quill-route-preview'), orderStatus, isBusy: () => busy }) : null;
    const addPart = (...args) => builder.add(...args);
    const updateParts = () => builder.update();

    function openEditor(button, item = null) {
        if (busy) return;
        opener = button;
        managedItem = item;
        form.querySelectorAll('[data-unavailable-page]').forEach(option => option.remove());
        form.reset();
        status.textContent = '';
        title.textContent = item ? `Manage ${kind}` : createTitle;
        description.textContent = item ? 'Review the details and save your changes.' : createDescription;
        form.action = item ? `/dashboard/quill/update/${kind}` : createAction;
        form.querySelector('[type="submit"]').textContent = item ? 'Save changes' : 'Create';
        itemId.disabled = !item;
        itemId.value = item?.id || '';
        itemVersion.disabled = !item || kind === 'route';
        itemVersion.value = item?.lock_version || '';
        info.hidden = !item;
        deleteButton.hidden = !item;
        if (item) {
            info.querySelectorAll('[data-quill-info]').forEach(field => {
                field.textContent = item[field.dataset.quillInfo] ?? '—';
            });
            document.getElementById('quill-item-status-row').hidden = !item.status;
            if (kind !== 'route') {
                form.elements.namedItem('name').value = item.name || '';
                form.elements.namedItem('description').value = item.description || '';
            }
        }
        const templateChoice = form.elements.namedItem('template_id');
        if (templateChoice) { templateChoice.disabled = !!item; templateChoice.closest('[data-quill-template-choice]').hidden = !!item; }
        if (list) {
            list.replaceChildren();
            orderStatus.textContent = '';
            if (item) {
                const segments = item.path === '/' ? [] : item.path.replace(/^\//, '').split('/');
                segments.forEach(segment => {
                    const parameter = /^\{([^{}]+)\}$/.exec(segment);
                    addPart(parameter ? 'param' : 'part', false, parameter ? parameter[1] : segment);
                });
                const select = form.elements.namedItem('page_id');
                const pageId = String(item.page_id ?? '');
                if (![...select.options].some(option => option.value === pageId)) {
                    const option = new Option('Page no longer available — choose another page', pageId);
                    option.dataset.unavailablePage = '';
                    select.add(option);
                }
                select.value = pageId;
            } else {
                addPart('part', false);
            }
            updateParts();
        }
        dialog.showModal();
        (list?.querySelector('[data-route-value]') || form.querySelector('[autofocus]') || form.elements.namedItem('page_id'))?.focus();
    }

    document.querySelectorAll('[data-quill-create]').forEach(button => {
        button.addEventListener('click', () => openEditor(button));
    });
    document.querySelectorAll('[data-quill-manage]').forEach(button => {
        button.addEventListener('click', () => openEditor(button, JSON.parse(button.dataset.quillManage)));
    });
    dialog.querySelectorAll('[data-quill-close]').forEach(button => {
        button.addEventListener('click', () => { if (!busy) dialog.close(); });
    });
    dialog.addEventListener('cancel', event => {
        if (busy) event.preventDefault();
    });
    dialog.addEventListener('close', () => {
        if (opener?.isConnected) opener.focus();
    });
    deleteButton.addEventListener('click', () => {
        if (busy || !managedItem) return;
        deleteForm.action = `/dashboard/quill/delete/${kind}`;
        document.getElementById('quill-delete-id').value = managedItem.id;
        const version = document.getElementById('quill-delete-version');
        version.disabled = kind === 'route';
        version.value = managedItem.lock_version || '';
        document.getElementById('quill-delete-name').textContent = managedItem.name || managedItem.path;
        deleteStatus.textContent = '';
        deleteDialog.showModal();
        deleteDialog.querySelector('[autofocus]').focus();
    });
    deleteDialog.querySelectorAll('[data-quill-delete-cancel]').forEach(button => {
        button.addEventListener('click', () => { if (!busy) deleteDialog.close(); });
    });
    deleteDialog.addEventListener('cancel', event => {
        if (busy) event.preventDefault();
    });
    deleteDialog.addEventListener('close', () => {
        if (dialog.open) deleteButton.focus();
    });

    async function submit(targetForm, feedback, scope) {
        if (busy || !targetForm.reportValidity()) return;
        const payload = new FormData(targetForm);
        const controls = [...scope.querySelectorAll('button, input, textarea, select')];
        const disabledStates = controls.map(control => control.disabled);
        busy = true;
        controls.forEach(control => { control.disabled = true; });
        targetForm.setAttribute('aria-busy', 'true');
        feedback.textContent = targetForm === deleteForm ? 'Deleting…' : 'Saving…';
        try {
            const response = await fetch(targetForm.action, {
                method: 'POST', body: payload, credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            if (response.redirected) throw new Error('Please sign in again, then reload this page.');
            const result = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(result.message || (response.status === 403
                ? 'Your session token expired. Reload the page and try again.' : 'Unable to save. Please try again.'));
            feedback.textContent = result.message || 'Saved successfully.';
            window.location.reload();
        } catch (error) {
            feedback.textContent = error.message || 'Unable to save. Please try again.';
        } finally {
            busy = false;
            targetForm.removeAttribute('aria-busy');
            controls.forEach((control, index) => { control.disabled = disabledStates[index]; });
        }
    }
    form.addEventListener('submit', event => {
        event.preventDefault();
        submit(form, status, dialog);
    });
    deleteForm.addEventListener('submit', event => {
        event.preventDefault();
        submit(deleteForm, deleteStatus, deleteDialog);
    });
})();
