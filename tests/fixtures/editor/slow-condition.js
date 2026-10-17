export default async block => {
    await new Promise(resolve => setTimeout(resolve, 120));
    return block?.descriptor.name === 'article';
};
