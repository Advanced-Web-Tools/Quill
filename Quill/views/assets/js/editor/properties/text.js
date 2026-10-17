import { mountGroups } from './controls.js';
const decorated=({style,computed})=>(style.textDecorationLine||computed.textDecorationLine)!=='none';
export default context => mountGroups(context, [
    ['Font', [
        ['font-family','Typeface'],['font-size','Size'],
        ['font-weight','Weight',['normal','bold','100','200','300','400','500','600','700','800','900','bolder','lighter']],
        ['font-style','Style',['normal','italic','oblique']],['color','Text color'],
    ]],
    ['Paragraph', [
        ['text-align','Alignment',['left','center','right','justify','start','end']],
        ['line-height','Line spacing'],['letter-spacing','Letter spacing'],['word-spacing','Word spacing'],['text-indent','First line offset'],
    ]],
    ['Emphasis', [
        ['text-decoration-line','Text lines',['none','underline','overline','line-through','underline overline','underline line-through']],
        ['text-decoration-style','Line style',['solid','double','dotted','dashed','wavy'],decorated],
        ['text-decoration-color','Line color',null,decorated],['text-decoration-thickness','Thickness',null,decorated],
        ['text-underline-offset','Distance from text',null,({style,computed})=>(style.textDecorationLine||computed.textDecorationLine).includes('underline')],
    ]],
    ['Letter case', [['text-transform','Capitalization',['none','uppercase','lowercase','capitalize']]]],
    ['Wrapping', [
        ['white-space','Spaces and lines',['normal','nowrap','pre','pre-wrap','pre-line','break-spaces']],
        ['word-break','Long words',['normal','break-all','keep-all']],['text-overflow','When text runs out',['clip','ellipsis']],
    ]],
]);
