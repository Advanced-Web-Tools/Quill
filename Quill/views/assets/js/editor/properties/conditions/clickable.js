export default block => {
    const tag = block.descriptor.name.toLowerCase();
    const attributes = block.attributes || {};
    if (['script', 'style', 'head', 'meta', 'link', 'br'].includes(tag)) return false;
    if (Object.values(window.QuillActions.bindings).some(field => block.editor?.[field])) return true;
    if (tag === 'input') return String(attributes.type || '').toLowerCase() !== 'hidden';
    if (['button', 'a', 'area', 'summary', 'label', 'select', 'textarea', 'form'].includes(tag)) return true;
    if (['audio', 'video'].includes(tag) && Object.hasOwn(attributes, 'controls') && attributes.controls !== false && attributes.controls !== null) return true;
    const roles = String(attributes.role || '').toLowerCase().trim().split(/\s+/);
    if (roles.some(role => ['button', 'link', 'checkbox', 'radio', 'switch', 'tab', 'menuitem', 'menuitemcheckbox', 'menuitemradio', 'option', 'combobox', 'slider', 'spinbutton', 'treeitem'].includes(role))) return true;
    return Object.keys(attributes).some(name => /^on(?:click|dblclick|mouseenter|mouseleave|focus|blur|change|submit|keydown)$/i.test(name) && attributes[name] !== false && attributes[name] !== null);
};
