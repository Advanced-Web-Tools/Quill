import { mountGroups } from './controls.js';
import { structureControls, element, tag, count } from './structure-controls.js';
const groups = new Set(['thead', 'tbody', 'tfoot']);
const rows = block => block.children.flatMap(child => tag(child) === 'tr' ? [child] : groups.has(tag(child)) ? child.children.filter(row => tag(row) === 'tr') : []);
const cells = row => row.children.filter(cell => ['td', 'th'].includes(tag(cell)));
const cell = (heading = false) => element(heading ? 'th' : 'td', heading ? 'Heading' : 'Cell', [], {
    style: 'border:1px solid #d1d5db;padding:8px', ...(heading ? { scope: 'col' } : {}),
});
function assertGrid(block) {
    if (rows(block).some(row => cells(row).some(cell => Number(cell.attributes.colspan || 1) > 1 || Number(cell.attributes.rowspan || 1) > 1))) {
        throw new Error('Remove merged cells before changing the table structure.');
    }
}
function body(block) {
    let result = block.children.find(child => tag(child) === 'tbody');
    if (!result) { result = element('tbody'); const footer = block.children.findIndex(child => tag(child) === 'tfoot'); block.children.splice(footer < 0 ? block.children.length : footer, 0, result); }
    return result;
}
export default context => {
    const { editor } = context;
    const structure = structureControls(context, 'Table', [
        { key: 'table-rows', label: 'Rows', type: 'number', min: 1, max: 100, read: block => rows(block).length,
            apply(block, value) {
                const size = count(value); assertGrid(block);
                const current = rows(block), width = Math.max(1, ...current.map(row => cells(row).length));
                const remove = new Set(current.slice(size));
                block.children = block.children.filter(child => !remove.has(child));
                block.children.filter(child => groups.has(tag(child))).forEach(group => { group.children = group.children.filter(row => !remove.has(row)); });
                for (let index = current.length; index < size; index++) body(block).children.push(element('tr', '', Array.from({ length: width }, () => cell())));
                editor.updateBlock({ children: block.children });
            } },
        { key: 'table-columns', label: 'Columns', type: 'number', min: 1, max: 20, read: block => Math.max(1, ...rows(block).map(row => cells(row).length)),
            apply(block, value) {
                const size = count(value, 20); assertGrid(block);
                for (const row of rows(block)) {
                    const current = cells(row); const remove = new Set(current.slice(size));
                    row.children = row.children.filter(child => !remove.has(child));
                    const heading = block.children.some(group => tag(group) === 'thead' && group.children.includes(row));
                    for (let index = current.length; index < size; index++) row.children.push(cell(heading));
                }
                editor.updateBlock({ children: block.children });
            } },
        { key: 'table-header', label: 'Header row', type: 'checkbox', read: block => block.children.some(child => tag(child) === 'thead' && rows({ children: [child] }).length),
            apply(block, enabled) {
                assertGrid(block);
                if (enabled) {
                    const first = rows(block)[0];
                    if (!first) throw new Error('Add a row first.');
                    block.children = block.children.filter(child => child !== first);
                    for (const group of block.children.filter(child => groups.has(tag(child)))) group.children = group.children.filter(row => row !== first);
                    for (const item of cells(first)) { item.descriptor.name = 'th'; item.attributes.scope = 'col'; }
                    let head = block.children.find(child => tag(child) === 'thead');
                    if (!head) { head = element('thead'); const index = block.children.findIndex(child => tag(child) !== 'caption' && tag(child) !== 'colgroup'); block.children.splice(index < 0 ? block.children.length : index, 0, head); }
                    head.children.unshift(first);
                } else {
                    const headers = block.children.filter(child => tag(child) === 'thead');
                    const headerRows = headers.flatMap(group => group.children);
                    for (const row of headerRows.filter(row => tag(row) === 'tr')) for (const item of cells(row)) { item.descriptor.name = 'td'; delete item.attributes.scope; }
                    block.children = block.children.filter(child => tag(child) !== 'thead');
                    body(block).children.unshift(...headerRows);
                }
                editor.updateBlock({ children: block.children });
            } },
    ]);
    const note = document.createElement('p'); note.className = 'editor-panel-description';
    note.textContent = 'Double-click a cell to edit text. Reducing rows or columns removes the last cells.';
    context.container.append(note);
    const styles = mountGroups(context, [['Table layout', [
        ['table-layout', 'Column sizing', ['auto', 'fixed']],
        ['border-collapse', 'Cell borders', ['collapse', 'separate']],
        ['border-spacing', 'Cell spacing', null, ({ style, computed }) => (style.borderCollapse || computed.borderCollapse) !== 'collapse'],
        ['caption-side', 'Caption position', ['top', 'bottom']],
    ]]]);
    return { update(block, state) { structure.update(block, state); styles.update(block, state); }, destroy: styles.destroy };
};
