import { structureControls } from './structure-controls.js';
export default context => structureControls(context, 'Heading', [{
    key: 'heading-level', label: 'Level', choices: [1, 2, 3, 4, 5, 6].map(level => ['h' + level, 'Heading ' + level]),
    read: block => block.descriptor.name.toLowerCase(),
    apply: (block, name) => context.editor.updateBlock({ descriptor: { ...block.descriptor, name }, definition_key: 'Quill:' + name, editor: { ...block.editor, label: 'Heading ' + name.slice(1) } }),
}]);
