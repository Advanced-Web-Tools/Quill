export default block => !['script', 'style', 'head', 'meta', 'link', 'br'].includes(block.descriptor.name.toLowerCase());
