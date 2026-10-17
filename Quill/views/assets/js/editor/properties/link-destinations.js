const encode = value => encodeURIComponent(value);
export function mailDestination(address, subject, body, extras = {}) {
    if (!address.trim()) return '';
    const headers = {...extras, subject, body};
    const query = Object.entries(headers).filter(([,value]) => value !== '').map(([key,value]) => `${encode(key)}=${encode(value)}`).join('&');
    const recipients = address.trim().split(',').map(value => encode(value.trim()).replace(/%40/gi,'@')).join(',');
    return `mailto:${recipients}${query ? '?' + query : ''}`;
}
export function readMail(href) {
    const [address, ...query] = href.slice(7).split('?');
    const headers = Object.fromEntries(new URLSearchParams(query.join('?')));
    let recipient = address;
    try { recipient = decodeURIComponent(address); } catch {}
    const {subject = '', body = '', ...extras} = headers;
    return {address:recipient, subject, body, extras};
}
export function routeDestination(route, values, suffix = '') {
    if (!route || route.parameters.some(key => !String(values[key] || '').trim())) return '';
    return route.path.split('/').map(segment => segment.startsWith('{') && segment.endsWith('}')
        ? encode(String(values[segment.slice(1,-1)]).trim()) : segment).join('/') + suffix;
}
export function matchRoute(routes, href) {
    const boundary = href.search(/[?#]/);
    const path = boundary < 0 ? href : href.slice(0,boundary), suffix = boundary < 0 ? '' : href.slice(boundary);
    for (const route of [...routes].sort((a,b) => a.parameters.length - b.parameters.length)) {
        const pattern = route.path.split('/'), segments = path.split('/'), values = {};
        if (pattern.length !== segments.length) continue;
        let matches = true;
        pattern.forEach((segment,index) => {
            if (segment.startsWith('{') && segment.endsWith('}')) {
                try { values[segment.slice(1,-1)] = decodeURIComponent(segments[index]); } catch { values[segment.slice(1,-1)] = segments[index]; }
            } else if (segment !== segments[index]) matches = false;
        });
        if (matches) return {route, values, suffix};
    }
    return null;
}
export function assertLink(value) {
    // App links may use their own schemes; executable/document payloads are not destinations.
    if (/^[\s\u0000-\u0020]*(?:javascript|data|vbscript|file|blob)\s*:/i.test(value.replace(/[\u0000-\u0020]/g,''))) throw new Error('Enter a web address or a link provided by an app.');
}
