import { mountGroups } from './controls.js';

export default context => mountGroups(context, [
    ['Placement', [
        ['position', 'Placement', ['static', 'relative', 'absolute', 'fixed', 'sticky']],
        ['constraints', 'Pin to edges'],
        ['z-index', 'Layer order', null, ({computed}) => computed.position !== 'static'],
    ], null, 'Keep an item in the flow, or pin it to a container or the window.'],
    ['Transform', [['move-transform', 'Adjustments']]],
]);
