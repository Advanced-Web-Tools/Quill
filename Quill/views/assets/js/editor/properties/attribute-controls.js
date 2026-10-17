export function mountAttributes({ container, editor, signal }, fields) {
    const controls = [];
    for (const [attribute, title, choices] of fields) {
        const label = document.createElement('label');
        label.textContent = title;
        const input = document.createElement(choices ? 'select' : 'input');
        input.id = `${container.id}-${attribute}`;
        input.dataset.attribute = attribute;
        label.htmlFor = input.id;
        if (choices) {
            input.add(new Option('Default', ''));
            choices.forEach(value => input.add(new Option(value, value)));
        } else input.type = 'text';
        input.addEventListener('input', () => {
            try { editor.setAttribute(attribute, input.value || null); editor.reportError(null); }
            catch (error) { editor.reportError(error); }
        }, { signal });
        controls.push(input);
        container.append(label, input);
    }
    return { update(block, context) {
        for (const input of controls) if (input !== document.activeElement || context.selectionChanged) input.value = block.attributes[input.dataset.attribute] ?? '';
    } };
}
