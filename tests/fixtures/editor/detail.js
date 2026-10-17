export default ({ container, editor, signal }) => {
    const input = document.createElement('input');
    input.dataset.demoDetail = '';
    input.addEventListener('input', () => editor.setStyle('border-radius', input.value), { signal });
    container.append(input);
    return { update(block) { input.value = block.attributes.style || ''; } };
};
