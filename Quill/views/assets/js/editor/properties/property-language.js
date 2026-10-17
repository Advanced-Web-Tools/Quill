const weights = {100:'Thin',200:'Extra light',300:'Light',400:'Regular',500:'Medium',600:'Semibold',700:'Bold',800:'Heavy',900:'Black'};
export const weightLabel = value => weights[value] || `Custom (${value})`;
const common = {
    normal:'Automatic',auto:'Automatic',none:'None',initial:'Standard',inherit:'Use parent setting',unset:'Automatic',
    start:'Start edge',end:'End edge','flex-start':'Group start','flex-end':'Group end',center:'Center',stretch:'Fill available space',
    baseline:'Text baseline','first baseline':'First text line','last baseline':'Last text line',
    'space-between':'Space between','space-around':'Space around','space-evenly':'Equal spacing',
    visible:'Show outside',hidden:'Hide outside',clip:'Crop outside',scroll:'Always scroll',
};
const options = {
    'pointer-events':{auto:'Respond to clicks and taps',none:'Let clicks pass through'},
    'user-select':{auto:'Automatic',text:'Allow selection',none:'Prevent selection',all:'Select all at once'},
    cursor:{auto:'Automatic',default:'Arrow',pointer:'Hand',help:'Help',wait:'Wait',progress:'Working','not-allowed':'Unavailable','context-menu':'Menu',crosshair:'Precise selection',text:'Text',move:'Move',grab:'Grab',grabbing:'Grabbing','zoom-in':'Zoom in','zoom-out':'Zoom out',none:'Hide pointer'},
    position:{static:'In the page flow',relative:'Offset in the page',absolute:'Pin to container',fixed:'Pin to window',sticky:'Stay in view'},
    display:{block:'Flow',inline:'Within text','inline-block':'Inline item',flex:'Flexible', 'inline-flex':'Inline flexible',grid:'Grid','inline-grid':'Inline grid','flow-root':'Independent flow',contents:'Group only',none:'Hidden'},
    'box-sizing':{'content-box':'Content only','border-box':'Include spacing and outline'},
    'flex-direction':{row:'Horizontal',column:'Vertical','row-reverse':'Horizontal, reversed','column-reverse':'Vertical, reversed'},
    'flex-wrap':{nowrap:'Keep one line',wrap:'Wrap to a new line','wrap-reverse':'Wrap in reverse'},
    'grid-auto-flow':{row:'Across, then down',column:'Down, then across',dense:'Fill empty spaces','row dense':'Across, filling gaps','column dense':'Down, filling gaps'},
    'font-weight':{normal:'Regular',bold:'Bold',bolder:'Heavier than parent',lighter:'Lighter than parent'},
    'font-style':{normal:'Upright',italic:'Italic',oblique:'Slanted'},
    'text-transform':{none:'As typed',uppercase:'ALL CAPS',lowercase:'lowercase',capitalize:'Title Case'},
    'white-space':{normal:'Wrap naturally',nowrap:'Keep on one line',pre:'Keep all spacing','pre-wrap':'Keep spacing and wrap','pre-line':'Keep line breaks','break-spaces':'Keep every space'},
    'word-break':{normal:'Keep words together','break-all':'Break anywhere','keep-all':'Avoid word breaks'},
    'text-overflow':{clip:'Crop text',ellipsis:'Show …'},
    'text-decoration-line':{none:'None',underline:'Underline',overline:'Line above','line-through':'Strikethrough','underline overline':'Above and below','underline line-through':'Underline and strike'},
    'background-size':{auto:'Original',cover:'Fill',contain:'Fit'},
    'background-repeat':{'no-repeat':'Do not repeat',repeat:'Tile','repeat-x':'Repeat across','repeat-y':'Repeat down',space:'Tile with gaps',round:'Tile to fit'},
    'border-style':{none:'None',solid:'Continuous',dashed:'Dashed',dotted:'Dotted',double:'Double'},
    'text-decoration-style':{solid:'Continuous',double:'Double',dotted:'Dotted',dashed:'Dashed',wavy:'Wavy'},
    float:{none:'No text wrapping',left:'Text wraps on the right',right:'Text wraps on the left','inline-start':'Wrap after this item','inline-end':'Wrap before this item'},
    clear:{none:'No separation',left:'Below left-wrapped items',right:'Below right-wrapped items',both:'Below all wrapped items','inline-start':'Below start-wrapped items','inline-end':'Below end-wrapped items'},
};
export function optionLabel(property,value) {
    if(property==='font-weight' && /^\d+$/.test(value))return weightLabel(value);
    return options[property]?.[value] || common[value] || value.replace(/-/g,' ').replace(/^./,character=>character.toUpperCase());
}
export const propertyHints = {
    'position':'Choose whether this item follows the page or stays pinned in place.',
    'z-index':'Higher values place this item in front of overlapping content.',
    'opacity':'Lower values let the content behind this item show through.',
    'box-sizing':'Choose whether the size includes the space inside and the outline.',
    'margin':'Space between this item and nearby content.', 'padding':'Space between the content and its edge.',
    'overflow':'Choose what happens when content extends beyond this item.',
    'flex-grow':'Allow this item to use extra space in its row or column.',
    'flex-shrink':'Allow this item to become smaller when space is limited.',
    'flex-basis':'The size this item starts with before sharing available space.',
    'order':'Change where this item appears among its siblings.',
    'align-self':'Override the alignment for this item only.',
    'justify-self':'Choose where this item sits across its grid cell.',
    'grid-template-columns':'Choose the number of equal columns. More options preserves custom layouts.',
    'grid-template-rows':'Choose the number of equal rows. More options preserves custom layouts.',
    'line-height':'Space between lines in a paragraph.',
    'letter-spacing':'Space between individual letters.',
    'text-indent':'Move the first line of a paragraph inward.',
};
