import { mountGroups } from './controls.js';
const hasImage=({style,computed})=>(style.backgroundImage||computed.backgroundImage)!=='none';
export default context => mountGroups(context, [
    ['Background', [
        ['background-color','Background color'],['background-image','Image'],
        ['background-size','Image fit',['auto','cover','contain'],hasImage],
        ['background-position','Image position',null,hasImage],
        ['background-repeat','Tiling',['no-repeat','repeat','repeat-x','repeat-y','space','round'],hasImage],
    ]],
    ['Border and corners', [['border-radius','Corners'],['border-width','Thickness'],['border-style','Line style',['none','solid','dashed','dotted','double']],['border-color','Outline color']]],
    ['Shadows', [['box-shadow','Outer shadow'],['text-shadow','Text and icon shadow']]],
    ['Opacity', [['opacity', 'Opacity']]],
]);
