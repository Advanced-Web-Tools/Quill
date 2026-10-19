export function mountMediaSource({ container, editor, signal }, type) {
    const urlPanel = document.createElement('div'); urlPanel.className = 'editor-media-url';
    const label = document.createElement('label'); label.textContent = type === 'image' ? 'Image URL' : 'Video URL';
    const input = document.createElement('input'); input.type = 'text'; input.id = `${container.id}-src`; input.dataset.attribute = 'src'; label.htmlFor = input.id;
    urlPanel.append(label, input);
    const select = item => {
        const block = editor.getSelectedBlock();
        if (block?.descriptor.name.toLowerCase() !== (type === 'image' ? 'img' : 'video')) throw new Error(`Select a ${type} block first.`);
        const attributes = { ...block.attributes, src: item.url };
        if (type === 'image' && item.alt !== undefined) attributes.alt = item.alt;
        editor.updateBlock({ attributes });
    };
    const library = editor.mediaLibrary || new window.QuillEditorMediaLibrary(editor);
    const picker = library.mount({ container, type, urlPanel, onSelect: select, signal });
    input.addEventListener('input', () => {
        try {
            if (input.value.trim()) select(window.QuillEditorMediaLibrary.item(input.value, type));
            else editor.setAttribute('src', null);
            editor.reportError(null);
        } catch (error) { editor.reportError(error); }
    }, { signal });
    return { update(block, context) {
        const value = block.attributes.src || '';
        if (input !== document.activeElement || context.selectionChanged) input.value = value;
        picker.update(value, context.selectionChanged);
    } };
}
