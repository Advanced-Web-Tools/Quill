export default (block, editor) => !editor.editingContext?.isolated && block.kind === 'element'
    && (!block.descriptor.renderer || block.descriptor.renderer.replace(/^\\/, '') === 'Quill\\services\\rendering\\ConnectedDataRenderer');
