<?php
// Run: php tests/urpc_to_quill.php. No database or framework bootstrap required.

use Quill\services\conversion\quillToUrpc\QuillContentSource;
use Quill\services\conversion\quillToUrpc\QuillToUrpcConverter;
use Quill\services\conversion\quillToUrpc\UrpcJsonSerializer;
use Quill\services\conversion\shared\ConversionException;
use Quill\services\conversion\urpcToQuill\ConversionResult;
use Quill\services\conversion\urpcToQuill\UrpcToQuillConverter;
use URPC\api\URPCBlock;
use URPC\api\URPCBlockDescriptor;
use URPC\api\URPCDocument;

$urpc = getenv('URPC_PACKAGE_ROOT') ?: dirname(__DIR__, 2) . '/URPC';
if (!is_dir($urpc . '/URPC/api')) throw new RuntimeException('Provide URPC_PACKAGE_ROOT containing URPC/api.');
spl_autoload_register(static function (string $class) use ($urpc): void {
    $relative = str_replace('\\', '/', $class) . '.php';
    $path = str_starts_with($class, 'Quill\\') ? dirname(__DIR__) . '/' . $relative : $urpc . '/' . $relative;
    if (is_file($path)) require_once $path;
});
$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
function fails(callable $operation, string ...$fragments): void
{
    try { $operation(); } catch (ConversionException $error) {
        foreach ($fragments as $fragment) check(str_contains($error->getMessage(), $fragment), $error->getMessage() . ' must contain ' . $fragment);
        return;
    }
    throw new RuntimeException('Expected conversion failure: ' . implode(', ', $fragments));
}
function element(string $tag = 'h1', string $content = 'Naslov', string $uid = ''): URPCBlock
{
    $descriptor = new URPCBlockDescriptor($tag, [], '', 'Application/page/1');
    $descriptor->setBlockUid($uid);
    $block = new URPCBlock($descriptor);
    $block->setBlockContent($content);
    return $block;
}
function document(URPCBlock ...$blocks): URPCDocument
{
    $document = new URPCDocument();
    $document->addSection('main');
    foreach ($blocks as $block) $document->addBlock('main', $block);
    $document->addSection('footer');
    return $document;
}

$converter = new UrpcToQuillConverter();
$heading = element('h1', '<Naslov> & tekst', 'urpc-original-uid');
$heading->setBlockAttributes(['class' => 'hero', 'hidden' => false, 'data-count' => 2]);
$heading->getBlockDescriptor()->setBlockRenderer('UnregisteredProcessorMustNotBeExecuted');
$heading->getBlockDescriptor()->setBlockProps(get_object_vars(json_decode('{"empty":{},"list":[],"nested":{"values":[{},[]]},"float":1.0}')));
$container = element('section', '', 'container-uid');
$container->addBlockChild($heading);
$container->addBlockChild(element('p', 'second'));
$document = document($container, $heading);
$result = $converter->convert($document);
check(array_map(fn ($section) => $section->name, $result->document->sections) === ['main', 'footer'], 'Sections preserved including empty section');
check($result->document->sections[0]->blocks === [1, 4] && $result->document->sections[1]->blocks === [], 'Root references preserve order');
check(array_keys($result->blocks) === [1, 2, 3, 4] && $result->blocks[1]->children === [2, 3], 'Preorder local IDs replace nested blocks with references');
check($result->blocks[2]->content === '<Naslov> & tekst', 'Text is not escaped');
check($result->blocks[2]->descriptor->renderer === 'UnregisteredProcessorMustNotBeExecuted', 'Processor identifier preserved without execution');
check($result->identities[2] === ['uid' => 'urpc-original-uid', 'source_key' => 'Application/page/1'], 'Original identity retained separately');
check($result->identities[4] === $result->identities[2], 'Repeated same instance retains source identity with a new local ID');
check(!isset($result->blocks[2]->descriptor->uid) && !isset($result->blocks[2]->urpc_uid), 'UID is not repurposed as a Quill ID or descriptor field');
check(!property_exists($result->blocks[2], 'definition_key'), 'No invented registry definition key');
check($result->blocks[2]->bindings instanceof stdClass && $result->blocks[2]->custom_settings instanceof stdClass, 'Empty feature maps are objects');
check($result->blocks[2]->attributes->class === 'hero', 'Attributes preserved');
$blockJson = $result->serializeBlock(2);
check(str_contains($blockJson, '"empty":{}') && str_contains($blockJson, '"list":[]') && str_contains($blockJson, '"float":1.0'), 'Nested JSON object/list and numeric value types preserved');
check(str_contains($blockJson, '"base":{}') && str_contains($blockJson, '"children":[]'), 'Style maps and empty children have correct JSON types');
check(json_decode($result->serializeDocument())->settings instanceof stdClass, 'Document settings serialize as object');
$convertedHeading = $result->blocks[2];
$convertedHeading->descriptor->props->nested->values[0]->changed = true;
check(!isset($result->blocks[4]->descriptor->props->nested->values[0]->changed), 'Shared URPC instance gets independent Quill block data');
check(!isset($heading->getBlockDescriptor()->getBlockProps()['nested']->values[0]->changed), 'Original nested props are not mutated');
check($heading->getBlockDescriptor()->getBlockUid() === 'urpc-original-uid', 'Original URPC UID unchanged');
check($converter->convert($document)->serializeBlock(2) === $blockJson, 'State resets and subsequent calls are deterministic');
fails(fn () => $result->serializeBlock(999), 'blocks/999', 'does not exist');

// Re-import the generated bundle through the actual forward converter without persistence.
$roundTripSource = new class($converter->convert($document)) implements QuillContentSource {
    public function __construct(private ConversionResult $result) {}
    public function getScopeKey(): string { return 'test/round-trip'; }
    public function getDocument(string $path): string { return $this->result->serializeDocument(); }
    public function getBlock(int $blockId, string $path): string { return $this->result->serializeBlock($blockId); }
    public function getComponent(int $componentId, string $path): QuillContentSource { throw new LogicException('No components'); }
};
$forward = (new QuillToUrpcConverter())->convert($roundTripSource);
$serializer = new UrpcJsonSerializer();
check($serializer->serializeDocument($forward->document) === $serializer->serializeDocument($document), 'URPC → Quill → URPC preserves renderable structure and sections');

$jsonResult = $converter->convertJson($serializer->serializeDocument($document));
check($jsonResult->document->sections[0]->blocks === [1, 4], 'Reads sections transport');
check($jsonResult->blocks[2]->descriptor->props->empty instanceof stdClass && $jsonResult->blocks[2]->descriptor->props->list === [], 'JSON reader preserves nested empty objects and arrays');
$singleSection = $converter->convertJson($serializer->serializeSection($document, 'main'), 'body');
check(count($singleSection->document->sections) === 1 && $singleSection->document->sections[0]->name === 'body', 'Reads native URPC loader envelope into selected section');
check($singleSection->identities[1] === ['uid' => '', 'source_key' => ''], 'JSON import does not generate identities absent from transport');
check($converter->convertJson('{"blocks":[]}')->document->sections[0]->blocks === [], 'Empty block envelope accepted');
check($converter->convert(new URPCDocument())->document->sections === [], 'Empty API document accepted');

$cycle = element('div', '', 'cyclic-uid');
$cycle->addBlockChild($cycle);
fails(fn () => $converter->convert(document($cycle)), 'main/blocks/0', 'cyclic-uid', 'children/0', 'Cyclic');
fails(fn () => (new UrpcToQuillConverter(1))->convert($document), 'children/0', 'depth');
fails(fn () => (new UrpcToQuillConverter(1))->convertJson($serializer->serializeSection($document, 'main')), 'children/0', 'depth');
foreach (['img', 'script', 'style'] as $tag) {
    fails(fn () => $converter->convert(document(element($tag, 'forbidden', 'invalid-content'))), 'invalid-content', $tag);
    $invalid = element($tag, '');
    $invalid->addBlockChild(element());
    fails(fn () => $converter->convert(document($invalid)), 'children', $tag);
}
check(count($converter->convert(document(element('br', '')))->blocks) === 1, 'Empty void element accepted');
fails(fn () => $converter->convert(document(element('invalid tag'))), 'descriptor/name', 'HTML');
foreach ([['invalid name' => 'value'], ['class' => []]] as $attributes) {
    $invalid = element();
    $invalid->setBlockAttributes($attributes);
    fails(fn () => $converter->convert(document($invalid)), 'attributes', 'scalar');
}
foreach ([new DateTimeImmutable(), INF, NAN, "\xff"] as $invalidValue) {
    $invalid = element();
    $invalid->getBlockDescriptor()->setBlockProps(['invalid' => $invalidValue]);
    fails(fn () => $converter->convert(document($invalid)), 'descriptor/props/invalid');
}
$cyclicValue = new stdClass();
$cyclicValue->self = $cyclicValue;
$invalid = element();
$invalid->getBlockDescriptor()->setBlockProps(['cyclic' => $cyclicValue]);
fails(fn () => $converter->convert(document($invalid)), 'props/cyclic', 'Cyclic JSON');
$invalidChildren = new class extends URPCBlock {
    public function getBlockChildren(): array { return [new stdClass()]; }
};
$invalidChildren->getBlockDescriptor()->setBlockName('div');
fails(fn () => $converter->convert(document($invalidChildren)), 'children/0', 'IURPCBlock');
$invalidDocument = new class extends URPCDocument {
    public function getSections(): array { return ['main' => 'invalid']; }
};
fails(fn () => $converter->convert($invalidDocument), 'sections/main', 'array');
foreach ([
    ['{', 'Invalid JSON'],
    ['[]', 'JSON object'],
    ['{}', 'schema_version'],
    ['{"schema_version":2,"sections":[]}', 'schema_version'],
    ['{"blocks":[],"sections":[]}', 'Ambiguous'],
    ['{"blocks":{}}', 'ordered JSON array'],
    ['{"blocks":[1]}', 'JSON object'],
    ['{"blocks":[{}]}', 'descriptor'],
    ['{"blocks":[{"descriptor":{"name":"p","props":[]}}]}', 'props'],
    ['{"blocks":[{"descriptor":{"name":"p","renderer":null}}]}', 'renderer'],
    ['{"blocks":[{"descriptor":{"name":"p"},"attributes":[]}]}', 'attributes'],
    ['{"blocks":[{"descriptor":{"name":"p"},"content":null}]}', 'content'],
    ['{"blocks":[{"descriptor":{"name":"p"},"children":{}}]}', 'children'],
    ['{"blocks":[{"descriptor":{"name":"p","uid":"unsupported"}}]}', 'Unsupported transport fields'],
    ['{"schema_version":1,"sections":[{"name":"main","blocks":[]},{"name":"main","blocks":[]}]}', 'unique'],
    ['{"schema_version":1,"sections":[{"name":"main","blocks":[101]}]}', 'JSON object'],
] as [$json, $message]) fails(fn () => $converter->convertJson($json), 'document', $message);
check($converter->convert(document(element()))->document->sections[0]->blocks === [1], 'Calls remain usable after failed conversions');
echo "Passed $checks URPC-to-Quill checks using real URPC API and an in-memory round trip.\n";
