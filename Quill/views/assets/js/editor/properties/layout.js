import { mountGroups } from './controls.js';

const alignment = ['normal', 'start', 'end', 'flex-start', 'flex-end', 'center', 'stretch', 'baseline', 'first baseline', 'last baseline'];
const distribution = [...alignment, 'space-between', 'space-around', 'space-evenly'];
const layoutFields = [
    ['Arrangement', [
        ['display', 'Layout mode', ['block', 'inline', 'inline-block', 'flex', 'inline-flex', 'grid', 'inline-grid', 'flow-root', 'contents', 'none']],
    ]],
    ['Size', [['width', 'Width'], ['height', 'Height'], ['aspect-ratio', 'Proportions']]],
    ['Spacing', [['margin', 'Space around'], ['padding', 'Space inside']]],
    ['Gaps between items', [
        ['gap', 'Spacing'], ['row-gap', 'Between rows'], ['column-gap', 'Between columns'],
    ], ({display}) => /flex|grid/.test(display)],
    ['Flex layout', [
        ['flex-direction', 'Direction', ['row', 'row-reverse', 'column', 'column-reverse']],
        ['flex-wrap', 'New lines', ['nowrap', 'wrap', 'wrap-reverse']],
        ['justify-content', 'Space across', distribution], ['align-items', 'Line up items', alignment], ['align-content', 'Space between lines', distribution],
    ], ({display}) => display.includes('flex')],
    ['Grid layout', [
        ['grid-template-columns', 'Columns'], ['grid-template-rows', 'Rows'], ['grid-template-areas', 'Named regions'],
        ['grid-auto-columns', 'New column size'], ['grid-auto-rows', 'New row size'],
        ['grid-auto-flow', 'Fill direction', ['row', 'column', 'dense', 'row dense', 'column dense']],
        ['justify-items', 'Position across cells', alignment], ['justify-content', 'Space across', distribution],
        ['align-items', 'Line up items', alignment], ['align-content', 'Space between lines', distribution],
    ], ({display}) => display.includes('grid')],
    ['Text around this item', [
        ['float', 'Wrap text', ['none', 'left', 'right', 'inline-start', 'inline-end']],
        ['clear', 'Start below', ['none', 'left', 'right', 'both', 'inline-start', 'inline-end']],
    ]],
    ['Item alignment and order', [
        ['order', 'Sequence'], ['flex', 'Space sharing', null, ({parentDisplay}) => parentDisplay.includes('flex')], ['flex-grow', 'Use extra space', null, ({parentDisplay}) => parentDisplay.includes('flex')], ['flex-shrink', 'Shrink to fit', null, ({parentDisplay}) => parentDisplay.includes('flex')], ['flex-basis', 'Starting size', null, ({parentDisplay}) => parentDisplay.includes('flex')],
        ['align-self', 'Line up this item', ['auto', ...alignment]], ['justify-self', 'Position in cell', ['auto', ...alignment], ({parentDisplay}) => parentDisplay.includes('grid')],
        ['grid-column', 'Column placement', null, ({parentDisplay}) => parentDisplay.includes('grid')], ['grid-row', 'Row placement', null, ({parentDisplay}) => parentDisplay.includes('grid')], ['grid-area', 'Named region', null, ({parentDisplay}) => parentDisplay.includes('grid')],
    ], ({parentDisplay}) => /flex|grid/.test(parentDisplay)],
    ['Overflow', [
        ['overflow', 'Content outside', ['visible', 'hidden', 'clip', 'scroll', 'auto']],
        ['overflow-x', 'Across the sides', ['visible', 'hidden', 'clip', 'scroll', 'auto']],
        ['overflow-y', 'Above and below', ['visible', 'hidden', 'clip', 'scroll', 'auto']],
    ]],
    ['Size limits', [
        ['min-width', 'Smallest width'], ['max-width', 'Largest width'], ['min-height', 'Smallest height'], ['max-height', 'Largest height'],
        ['box-sizing', 'Measure size', ['content-box', 'border-box']],
    ]],
];

export default context => mountGroups(context, layoutFields);
