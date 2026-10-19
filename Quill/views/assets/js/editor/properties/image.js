import { mountAttributes } from './attribute-controls.js';
import { mountMediaSource } from './media-source.js';
export default context => {
    const source = mountMediaSource(context, 'image');
    const attributes = mountAttributes(context, [['alt', 'Alternative text'], ['loading', 'Loading', ['lazy', 'eager']]]);
    return { update(block, change) { source.update(block, change); attributes.update(block, change); } };
};
