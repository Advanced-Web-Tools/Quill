export default ({ container, editor, signal }) => {
    window.extensionMounts = (window.extensionMounts || 0) + 1;
    const input = document.createElement('input');
    input.dataset.demoCaption = '';
    input.setAttribute('aria-label', 'Card caption');
    container.append(input);
    input.addEventListener('input', () => editor.setProps({ ...editor.getSelectedBlock().descriptor.props, title: input.value }), { signal });
    signal.addEventListener('abort', () => { window.extensionAborts = (window.extensionAborts || 0) + 1; });
    return {
        update(block) { if (input !== document.activeElement) input.value = block.descriptor.props.title || ''; },
        destroy() { window.extensionDestroys = (window.extensionDestroys || 0) + 1; },
    };
};
