export default block => !block.attributes['data-quill-icon'] && !['img', 'hr', 'br', 'video', 'audio', 'iframe', 'input', 'script', 'style', 'source'].includes(block.descriptor.name.toLowerCase());
