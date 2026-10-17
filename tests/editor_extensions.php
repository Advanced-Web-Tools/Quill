<?php
// Run: php tests/editor_extensions.php. Real event dispatcher, converters and persistence.
require __DIR__ . '/editor_save.php';

use Quill\api\editor\BlockDefinition;
use Quill\api\editor\BlockRegistry;
use Quill\api\editor\PropertyDefinition;
use Quill\api\editor\PropertyElement;
use Quill\api\editor\PropertyRegistry;
use Quill\api\events\EditorBlocksRequestEvent;
use Quill\api\events\EditorPropertiesRequestEvent;
use Quill\services\editor\EditorBlockCompiler;
use Quill\services\editor\EditorDocumentMapper;
use Quill\services\editor\EditorExtensionLoader;
use URPC\api\URPCBlock;
use URPC\api\URPCBlockDescriptor;
use URPC\api\URPCDocument;

$extensionStart = $count;
$external = new URPCBlock(new URPCBlockDescriptor('article', ['options' => (object) ['empty' => new stdClass(), 'list' => []]], 'ProcessorMustNotRun'));
$external->getBlockDescriptor()->setBlockUid('original-uid');
$child = new URPCBlock(new URPCBlockDescriptor('h2'));
$child->setBlockContent('External heading');
$external->addBlockChild($child);
$dispatcher = new event\EventDispatcher();
$blockListener = new class($external) implements event\interfaces\IEventListener {
    public int $calls = 0;
    public function __construct(private URPCBlock $block) {}
    public function handle(event\interfaces\IEvent $event): array
    {
        $this->calls++;
        $registry = $event->bundle()['blockRegistry'];
        check($registry === $event->blockRegistry, 'Event bundle contains the same mutable block registry');
        $registry->add(new BlockDefinition('Demo:card', 'Card', '▣', 'A reusable external card', $this->block));
        $registry->remove('Quill:hr');
        $registry->add(new BlockDefinition('Quill:p', 'Custom paragraph', '¶', 'Replacement', '{"blocks":[{"descriptor":{"name":"p"},"content":"Replacement"}]}'), true);
        return [];
    }
};
$propertyListener = new class implements event\interfaces\IEventListener {
    public int $calls = 0;
    public function handle(event\interfaces\IEvent $event): array
    {
        $this->calls++;
        $registry = $event->bundle()['propertyRegistry'];
        check($registry === $event->propertyRegistry, 'Event bundle contains the same mutable property registry');
        $registry->add(new PropertyDefinition('Demo:appearance', 'appearance', [
            '/demo/primary.js', new PropertyElement('/demo/secondary.js', '/demo/secondary-condition.js'),
        ], '/demo/card-condition.js', 'Appearance'));
        return [];
    }
};
$dispatcher->addListener(EditorBlocksRequestEvent::NAME, $blockListener);
$dispatcher->addListener(EditorPropertiesRequestEvent::NAME, $propertyListener);
$loader = new EditorExtensionLoader('/custom/quill/assets');
$catalog = $loader->load($dispatcher);
check($blockListener->calls === 1 && $propertyListener->calls === 1, 'Both request events dispatch once per catalog');
check(count($catalog['blocks']) === 23 && count($catalog['properties']) === 14, 'Extensions can add, replace and remove defaults');
$iconDefinition = array_values(array_filter($catalog['blocks'], fn ($definition) => $definition['key'] === 'Quill:icon'))[0];
check($iconDefinition['blocks'][0]->descriptor->name === 'i' && $iconDefinition['blocks'][0]->attributes->{'data-quill-icon'} === true, 'Icon is a native registered block with dedicated settings');
$builtins = array_column($catalog['blocks'], null, 'key');
foreach (range(1, 6) as $level) {
    check($builtins['Quill:h' . $level]['blocks'][0]->descriptor->name === 'h' . $level, 'All six heading levels are registered');
}
foreach (['ul', 'ol'] as $tag) {
    $list = $builtins['Quill:' . $tag]['blocks'][0];
    check($list->descriptor->name === $tag && count($list->children) === 3 && $list->children[0]->descriptor->name === 'li', 'Lists contain native editable list item blocks');
}
$tableTemplate = $builtins['Quill:table']['blocks'][0];
check($tableTemplate->children[0]->descriptor->name === 'thead' && $tableTemplate->children[1]->descriptor->name === 'tbody'
    && count($tableTemplate->children[1]->children) === 2 && count($tableTemplate->children[0]->children[0]->children) === 3
    && $tableTemplate->children[0]->children[0]->children[0]->attributes->scope === 'col', 'Table template has semantic header/body groups and scoped header cells');
$structured = (new EditorDocumentMapper())->map((object) ['schema_version' => 1, 'sections' => [(object) ['name' => 'main', 'blocks' => [
    $tableTemplate, $builtins['Quill:ol']['blocks'][0], $builtins['Quill:li']['blocks'][0],
]]]], new stdClass());
check(count($structured->blocks) === 20 && $structured->blocks[1]->definition_key === 'Quill:table', 'Native table and list templates pass production persistence validation');
$card = array_values(array_filter($catalog['blocks'], fn ($definition) => $definition['key'] === 'Demo:card'))[0];
check($card['name'] === 'Card' && $card['icon'] === '▣' && $card['description'] === 'A reusable external card', 'Palette metadata is retained');
check($card['blocks'][0]->children[0]->content === 'External heading' && $card['blocks'][0]->_quill->definition_key === 'Demo:card', 'URPC definition is converted to Quill and expanded into editor structure');
check($card['blocks'][0]->descriptor->props->options->empty instanceof stdClass && is_array($card['blocks'][0]->descriptor->props->options->list), 'Templates retain nested object/list types');
check($external->getBlockDescriptor()->getBlockUid() === 'original-uid' && $external->getBlockChildren()['0']->getBlockContent() === 'External heading', 'Registration does not mutate or render source URPC blocks');
check($catalog['properties'][0]['elements'][0]['definition'] === '/custom/quill/assets/js/editor/properties/layout.js', 'Built-in property modules use configured asset base');
check($catalog['properties'][13]['condition'] === '/demo/card-condition.js' && $catalog['properties'][13]['elements'][1]['condition'] === '/demo/secondary-condition.js', 'Both group and element condition modules are exported');
check(count($loader->load($dispatcher)['blocks']) === 23 && $blockListener->calls === 2, 'Registries are isolated between requests');
$mapped = (new EditorDocumentMapper())->map((object) ['schema_version' => 1, 'sections' => [(object) ['name' => 'main', 'blocks' => $card['blocks']]]], new stdClass());
check($mapped->blocks[1]->definition_key === 'Demo:card' && $mapped->blocks[2]->content === 'External heading', 'External template passes existing persistence mapping with Quill definition metadata');
$document = new URPCDocument();
$document->addBlock('first', $child);
$document->addBlock('second', new URPCBlock(new URPCBlockDescriptor('hr')));
$compiled = (new EditorBlockCompiler())->compile(new BlockDefinition('Demo:multiple', 'Multiple roots', '', '', $document));
check(array_map(fn ($block) => $block->descriptor->name, $compiled['blocks']) === ['h2', 'hr'], 'Multi-section URPC definitions preserve root order');
$registry = new BlockRegistry();
$definition = new BlockDefinition('Demo:test', 'Test', '', '', $child);
$registry->add($definition);
try { $registry->add($definition); throw new RuntimeException('Duplicate must fail'); }
catch (InvalidArgumentException $expected) { check(str_contains($expected->getMessage(), 'Duplicate'), 'Duplicate keys require explicit replacement'); }
$registry->remove('Demo:test');
check($registry->get('Demo:test') === null, 'Registry supports removal and lookup');
foreach (['javascript:alert(1)', '/demo/not-a-module.php', '', '/demo/code.js#fragment'] as $invalidPath) {
    try { new PropertyElement($invalidPath); throw new RuntimeException('Module URL must fail'); }
    catch (InvalidArgumentException $expected) { check(true, 'Invalid module URL rejected'); }
}
$viewController->eventDispatcher = $dispatcher;
$previousErrors = libxml_use_internal_errors(true);
$view = $viewController->editor(['type' => 'page', 'id' => (string) $owner['id']]);
libxml_clear_errors();
libxml_use_internal_errors($previousErrors);
check(count($view->bundle['editorExtensions']['properties']) === 14, 'Real editor controller dispatches extension requests and bootstraps resulting catalog');
check(!array_key_exists(EditorPropertiesRequestEvent::NAME, (new event\EventDispatcher())->listeners), 'Default listeners do not mutate shared dispatchers');
check($compiled['category'] === 'Basic', 'Default block category is exported');
check(array_reduce($catalog['properties'], fn ($valid, $property) => $valid && $property['condition'] !== null, true), 'All default property categories supply condition modules');
check($catalog['properties'][0]['icon'] === 'fa-solid fa-layer-group', 'Default property tab icons are exported through the event registry');
echo 'Passed ' . ($count - $extensionStart) . " PHP editor extension checks.\n";
