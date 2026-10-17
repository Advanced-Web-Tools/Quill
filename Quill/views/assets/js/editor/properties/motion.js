import { mountGroups } from './controls.js';
export default function (context) {
    const controls = mountGroups(context, [
        ['Transitions', [['animate-changes', 'Animate changes']], null, 'Animate visual changes between element states, such as Default and Hover. Animations triggered by actions are configured in Interactions.'],
    ]);
    if (context.editor.type !== 'page') return controls;
    const studio = document.createElement('details'); studio.className = 'editor-style-group'; studio.open = true;
    studio.innerHTML = '<summary><span>Keyframe animations</span><i class="fa-solid fa-chevron-down" aria-hidden="true"></i></summary><p class="editor-group-description">Apply shared animations or create one in Animation Studio. Select an animation to edit or remove it.</p>';
    context.container.prepend(studio);
    const library = context.editor.ui.animationLibrary?.mount(studio, context.signal);
    return { ...controls, update(block, change) { controls.update(block, change); library?.update(); } };
}
