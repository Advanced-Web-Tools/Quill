function parseObject(value, name) {
    let result;
    try { result = JSON.parse(value); } catch { throw new Error(`${name} must contain valid JSON.`); }
    if (!result || Array.isArray(result) || typeof result !== 'object') throw new Error(`${name} must be a JSON object.`);
    return result;
}

export default function ({ container, editor, signal }) {
    container.innerHTML = '<fieldset><legend>Identity</legend>'
        + '<label for="editor-tag">HTML tag</label><input id="editor-tag" name="tag" required autocomplete="off">'
        + '<p class="editor-panel-description editor-text-hint">Double-click text on the canvas to edit it.</p>'
        + '<label for="editor-class">CSS classes</label><input id="editor-class" name="classes" autocomplete="off">'
        + '<label for="editor-html-id">HTML ID</label><input id="editor-html-id" name="htmlId" autocomplete="off"></fieldset>'
        + '<details class="editor-advanced"><summary>More properties</summary>'
        + '<label for="editor-attributes">Other attributes (JSON object)</label><textarea id="editor-attributes" name="attributes" rows="5" spellcheck="false"></textarea>'
        + '<label for="editor-props">Descriptor props (JSON object)</label><textarea id="editor-props" name="props" rows="5" spellcheck="false"></textarea>'
        + '<label for="editor-renderer">Renderer</label><input id="editor-renderer" readonly></details>';
    container.addEventListener('input', event => {
        const name = event.target.name;
        if (!name) return;
        try {
            const block = editor.getSelectedBlock();
            if (!block) return;
            const changes = {};
            if (name === 'tag') changes.descriptor = { ...block.descriptor, name: event.target.value.trim() };
            if (name === 'props') changes.descriptor = { ...block.descriptor, props: parseObject(event.target.value, 'Descriptor props') };
            if (name === 'classes' || name === 'htmlId') {
                editor.setAttribute(name === 'classes' ? 'class' : 'id', event.target.value || null);
            } else if (name === 'attributes') {
                changes.attributes = parseObject(event.target.value, 'Attributes');
                for (const key of ['class', 'id', 'style']) {
                    if (Object.hasOwn(changes.attributes, key)) throw new Error('Use the dedicated controls for class, id and style.');
                    if (Object.hasOwn(block.attributes, key)) changes.attributes[key] = block.attributes[key];
                }
            }
            if (Object.keys(changes).length) editor.updateBlock(changes);
            editor.reportError(null);
        } catch (error) { editor.reportError(error); }
    }, { signal });
    return {
        update(block, context) {
            const attributes = { ...block.attributes };
            for (const key of ['class', 'id', 'style']) delete attributes[key];
            const values = {
                'editor-tag': block.descriptor.name, 'editor-class': block.attributes.class ?? '',
                'editor-html-id': block.attributes.id ?? '', 'editor-attributes': JSON.stringify(attributes, null, 2),
                'editor-props': JSON.stringify(block.descriptor.props, null, 2), 'editor-renderer': block.descriptor.renderer,
            };
            for (const [id, value] of Object.entries(values)) {
                const control = container.querySelector(`#${id}`);
                if (control !== document.activeElement || context.selectionChanged) control.value = value;
            }
        },
    };
}
