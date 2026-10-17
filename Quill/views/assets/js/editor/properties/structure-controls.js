// Structural controls use the same groups and rows as the CSS property modules.
export const tag = block => block.descriptor.name.toLowerCase();
export const element = (name, content = '', children = [], attributes = {}) => ({
    descriptor: { name, props: {}, renderer: '' }, attributes, content, children,
});
export function count(value, max = 100) {
    const number = Number(value);
    if (!Number.isInteger(number) || number < 1 || number > max) throw new Error(`Enter a whole number from 1 to ${max}.`);
    return number;
}
export function structureControls({ container, editor, signal }, title, fields) {
    const group = document.createElement('details');
    group.className = 'editor-style-group'; group.open = true;
    const summary = document.createElement('summary');
    const heading = document.createElement('span'); heading.textContent = title;
    const icon = document.createElement('i'); icon.className = 'fa-solid fa-chevron-down'; icon.setAttribute('aria-hidden', 'true');
    summary.append(heading, icon); group.append(summary);
    const controls = fields.map(({ key, label, choices, type = 'text', min, max, read, apply, visible }) => {
        const row = document.createElement('div'); row.className = 'editor-property-row';
        const caption = document.createElement('label'); caption.textContent = label;
        const input = document.createElement(choices ? 'select' : 'input');
        input.dataset.structure = key; input.id = `${container.id}-${key}`; caption.htmlFor = input.id;
        if (choices) choices.forEach(([value, name]) => input.add(new Option(name, value)));
        else { input.type = type; if (min !== undefined) input.min = min; if (max !== undefined) input.max = max; }
        input.addEventListener('change', () => {
            try {
                const block = editor.getSelectedBlock();
                apply(block, type === 'checkbox' ? input.checked : input.value);
                editor.reportError(null);
            } catch (error) { editor.reportError(error); }
            update(editor.getSelectedBlock(), { selectionChanged: true });
        }, { signal });
        row.append(caption, input); group.append(row);
        return { row, input, type, read, visible };
    });
    container.append(group);
    function update(block, context) {
        if (!block) return;
        controls.forEach(({ row, input, type, read, visible }) => {
            row.hidden = visible ? !visible(block) : false;
            if (input === document.activeElement && !context.selectionChanged) return;
            if (type === 'checkbox') input.checked = !!read(block);
            else input.value = read(block) ?? '';
        });
    }
    return { update };
}
