import { mountAttributes } from './attribute-controls.js';
import { mountMediaSource } from './media-source.js';
export default context => {
    const source = mountMediaSource(context, 'video');
    const attributes = mountAttributes(context, [['poster', 'Poster URL'], ['controls', 'Player controls', ['controls']], ['autoplay', 'Autoplay', ['autoplay']], ['loop', 'Loop', ['loop']], ['muted', 'Muted', ['muted']], ['preload', 'Preload', ['none', 'metadata', 'auto']]]);
    return { update(block, change) { source.update(block, change); attributes.update(block, change); } };
};
