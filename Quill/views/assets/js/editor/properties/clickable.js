import { mountGroups } from './controls.js';

export default function (context) {
    const controls = mountGroups(context, [
        ['Pointer', [
            ['cursor', 'Cursor', ['auto', 'default', 'pointer', 'help', 'wait', 'progress', 'not-allowed', 'context-menu', 'crosshair', 'text', 'move', 'grab', 'grabbing', 'zoom-in', 'zoom-out', 'none']],
            ['pointer-events', 'Respond to pointer', ['auto', 'none']],
            ['user-select', 'Text selection', ['auto', 'text', 'none', 'all']],
        ]],
    ]);
    const action = document.createElement('details'); action.className = 'editor-style-group'; action.open = true;
    const summary = document.createElement('summary');
    const title = document.createElement('span'); title.textContent = 'Events and actions';
    const chevron = document.createElement('i'); chevron.className = 'fa-solid fa-chevron-down'; chevron.setAttribute('aria-hidden', 'true');
    summary.append(title, chevron);
    const assignments = document.createElement('div'); assignments.className = 'editor-action-assignment';
    action.append(summary, assignments); context.container.prepend(action);
    assignments.addEventListener('click', event => {
        const button = event.target.closest('[data-action-operation]'); if (!button) return;
        editor.ui.actions.open(button.dataset.actionOperation === 'edit' ? button.dataset.actionId : null, editor.selectedPath);
    }, { signal: context.signal });
    assignments.addEventListener('change', event => {
        if (!event.target.matches('[data-action-binding]')) return;
        try { editor.ui.actions.assign(event.target.value, editor.selectedPath, event.target.dataset.actionBinding); editor.reportError(null); }
        catch (error) { editor.reportError(error); }
    }, { signal: context.signal });
    const editor = context.editor;
    let signature;
    return { ...controls, update(block, change) {
        controls.update(block, change);
        const actions = editor.getDocument().settings?.actions || [];
        const next = JSON.stringify([actions.map(action => [action.id, action.name, action.trigger]), Object.fromEntries(Object.values(window.QuillActions.bindings).map(field => [field, block.editor?.[field] || '']))]);
        if (signature === next) return; signature = next;
        assignments.replaceChildren();
        const button = (title, operation) => {
            const element = document.createElement('button'); element.type = 'button'; element.textContent = title;
            element.dataset.actionOperation = operation; return element;
        };
        if (!actions.length) { assignments.append(button('Create action', 'create')); return; }
        for (const [trigger, field] of Object.entries(window.QuillActions.bindings)) {
            const available = actions.filter(action => action.trigger === trigger);
            const binding = block.editor?.[field] || '';
            if (!available.length && !binding) continue;
            const label = document.createElement('label'); label.textContent = window.QuillActions.triggers[trigger];
            const select = document.createElement('select'); select.dataset.actionBinding = trigger; select.id = `${context.container.id}-action-${trigger}`;
            label.htmlFor = select.id; select.add(new Option('None', ''));
            available.forEach(action => select.add(new Option(action.name, action.id))); select.value = binding;
            const edit = button('Edit action', 'edit'); edit.disabled = !binding; edit.dataset.actionId = binding;
            const row = document.createElement('div'); row.className = 'editor-action-binding-row'; row.append(select, edit);
            assignments.append(label, row);
        }
        assignments.append(button('New action', 'create'));
    } };
}
