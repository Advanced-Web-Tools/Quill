import { mountGroups } from './controls.js';
import { structureControls, element, tag, count } from './structure-controls.js';
export default context => {
    const { editor } = context;
    const list = block => tag(block) !== 'li';
    const structure = structureControls(context, 'List', [
        { key: 'list-type', label: 'Type', choices: [['ul', 'Bulleted'], ['ol', 'Numbered']], visible: list, read: tag,
            apply(block, name) {
                const attributes = { ...block.attributes };
                if (name === 'ul') { delete attributes.start; delete attributes.reversed; }
                editor.updateBlock({ descriptor: { ...block.descriptor, name }, attributes, definition_key: 'Quill:' + name,
                    editor: { ...block.editor, label: name === 'ul' ? 'Bulleted list' : 'Numbered list' } });
            } },
        { key: 'list-items', label: 'Items', type: 'number', min: 1, max: 100, visible: list,
            read: block => block.children.filter(child => tag(child) === 'li').length,
            apply(block, value) {
                const size = count(value), children = block.children;
                let remaining = size;
                const next = children.filter(child => tag(child) !== 'li' || remaining-- > 0);
                for (let index = children.filter(child => tag(child) === 'li').length; index < size; index++) next.push(element('li', 'List item ' + (index + 1)));
                editor.updateBlock({ children: next });
            } },
        { key: 'list-start', label: 'Start at', type: 'number', visible: block => tag(block) === 'ol', read: block => block.attributes.start ?? 1,
            apply(block, value) {
                if (value !== '' && !Number.isSafeInteger(Number(value))) throw new Error('Enter a whole starting number.');
                editor.setAttribute('start', value === '' ? null : Number(value));
            } },
        { key: 'list-reversed', label: 'Reverse numbering', type: 'checkbox', visible: block => tag(block) === 'ol',
            read: block => Object.hasOwn(block.attributes, 'reversed') && block.attributes.reversed !== false && block.attributes.reversed !== null,
            apply: (block, value) => editor.setAttribute('reversed', value ? true : null) },
        { key: 'item-value', label: 'Item number', type: 'number', visible: block => tag(block) === 'li', read: block => block.attributes.value ?? '',
            apply(block, value) {
                if (value !== '' && !Number.isSafeInteger(Number(value))) throw new Error('Enter a whole item number.');
                editor.setAttribute('value', value === '' ? null : Number(value));
            } },
    ]);
    const note = document.createElement('p'); note.className = 'editor-panel-description';
    note.textContent = 'Double-click an item to edit text. Reducing the item count removes the last items.';
    context.container.append(note);
    const styles = mountGroups(context, [['Markers', [
        ['list-style-type', 'Marker', ['disc', 'circle', 'square', 'decimal', 'decimal-leading-zero', 'lower-alpha', 'upper-alpha', 'lower-roman', 'upper-roman', 'none']],
        ['list-style-position', 'Position', ['outside', 'inside']],
    ]]]);
    return { update(block, state) { structure.update(block, state); styles.update(block, state); }, destroy: styles.destroy };
};
