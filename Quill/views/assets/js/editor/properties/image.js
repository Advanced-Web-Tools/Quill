import { mountAttributes } from './attribute-controls.js';
export default context => mountAttributes(context, [['src', 'Image URL'], ['alt', 'Alternative text'], ['loading', 'Loading', ['lazy', 'eager']]]);
