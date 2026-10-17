<?php

namespace Quill\services\editor;

use Quill\api\editor\BlockDefinition;
use Quill\api\editor\BlockRegistry;
use Quill\api\editor\PropertyDefinition;
use Quill\api\editor\PropertyRegistry;
use URPC\api\URPCBlock;
use URPC\api\URPCBlockDescriptor;

final class DefaultEditorDefinitions implements \event\interfaces\IEventListener
{
    public function __construct(private string $assetBase) {}

    public function handle(\event\interfaces\IEvent $event): array
    {
        if ($event instanceof \Quill\api\events\EditorBlocksRequestEvent) $this->registerBlocks($event->blockRegistry);
        if ($event instanceof \Quill\api\events\EditorPropertiesRequestEvent) $this->registerProperties($event->propertyRegistry);
        return [];
    }

    private function registerBlocks(BlockRegistry $blocks): void
    {
        foreach ([
            ['section', 'Section', 'fa-solid fa-layer-group', 'A section for your content', ''],
            ['div', 'Container', 'fa-solid fa-square', 'Group and arrange blocks', ''],
            ['h1', 'Heading 1', 'fa-solid fa-heading', 'A title that stands out', 'Heading'],
            ['h2', 'Heading 2', 'fa-solid fa-heading', 'A second-level heading', 'Heading 2'],
            ['h3', 'Heading 3', 'fa-solid fa-heading', 'A third-level heading', 'Heading 3'],
            ['h4', 'Heading 4', 'fa-solid fa-heading', 'A fourth-level heading', 'Heading 4'],
            ['h5', 'Heading 5', 'fa-solid fa-heading', 'A fifth-level heading', 'Heading 5'],
            ['h6', 'Heading 6', 'fa-solid fa-heading', 'A sixth-level heading', 'Heading 6'],
            ['li', 'List item', 'fa-solid fa-list', 'An item to insert inside a list', 'List item'],
            ['p', 'Paragraph', 'fa-solid fa-paragraph', 'A block of text', 'Write your text here.'],
            ['button', 'Button', 'fa-solid fa-hand-pointer', 'A call to action', 'Button'],
            ['a', 'Link', 'fa-solid fa-link', 'A text link', 'Link'],
            ['img', 'Image', 'fa-solid fa-image', 'An image with an alt description', ''],
            ['hr', 'Divider', 'fa-solid fa-minus', 'Separate your content', ''],
        ] as [$tag, $name, $icon, $description, $content]) {
            $block = new URPCBlock(new URPCBlockDescriptor($tag));
            $block->setBlockContent($content);
            if ($tag === 'img') $block->setBlockAttributes(['alt' => 'Image description']);
            $blocks->add(new BlockDefinition('Quill:' . $tag, $name, $icon, $description, $block, in_array($tag, ['section', 'div', 'hr'], true) ? 'Layout' : 'Content'));
        }
        foreach (['ul' => ['Bulleted list', 'fa-list-ul'], 'ol' => ['Numbered list', 'fa-list-ol']] as $tag => [$name, $icon]) {
            $block = new URPCBlock(new URPCBlockDescriptor($tag));
            for ($index = 1; $index <= 3; $index++) {
                $item = new URPCBlock(new URPCBlockDescriptor('li'));
                $item->setBlockContent('List item ' . $index);
                $block->addBlockChild($item);
            }
            $blocks->add(new BlockDefinition('Quill:' . $tag, $name, 'fa-solid ' . $icon, 'A list with editable items', $block, 'Content'));
        }
        $table = new URPCBlock(new URPCBlockDescriptor('table'));
        $table->setBlockAttributes(['style' => 'width:100%;border-collapse:collapse']);
        foreach (['thead' => 1, 'tbody' => 2] as $tag => $count) {
            $group = new URPCBlock(new URPCBlockDescriptor($tag));
            for ($rowIndex = 0; $rowIndex < $count; $rowIndex++) {
                $row = new URPCBlock(new URPCBlockDescriptor('tr'));
                for ($column = 1; $column <= 3; $column++) {
                    $cell = new URPCBlock(new URPCBlockDescriptor($tag === 'thead' ? 'th' : 'td'));
                    $cell->setBlockContent(($tag === 'thead' ? 'Heading ' : 'Cell ') . $column);
                    $cell->setBlockAttributes(['style' => 'border:1px solid #d1d5db;padding:8px', ...($tag === 'thead' ? ['scope' => 'col'] : [])]);
                    $row->addBlockChild($cell);
                }
                $group->addBlockChild($row);
            }
            $table->addBlockChild($group);
        }
        $blocks->add(new BlockDefinition('Quill:table', 'Table', 'fa-solid fa-table', 'A table with editable rows, columns and header', $table, 'Content'));
        $iconBlock = new URPCBlock(new URPCBlockDescriptor('i'));
        $iconBlock->setBlockAttributes(['class' => 'fa-solid fa-star', 'data-quill-icon' => true, 'aria-hidden' => 'true', 'style' => 'font-size:32px']);
        $blocks->add(new BlockDefinition('Quill:icon', 'Icon', 'fa-solid fa-icons', 'A searchable Font Awesome icon', $iconBlock, 'Content'));
        foreach ([
            ['stack', 'Vertical stack', 'fa-solid fa-bars', 'Two vertically stacked containers with a 24px gap', 'display:flex;flex-direction:column;gap:24px', 2, ''],
            ['row', 'Flexible row', 'fa-solid fa-grip-lines-vertical', 'Two flexible columns that wrap on narrow screens', 'display:flex;flex-wrap:wrap;gap:24px', 2, 'flex:1 1 240px;min-width:0'],
            ['two-columns', 'Two columns', 'fa-solid fa-table-columns', 'Two equal grid columns with a 24px gap', 'display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:24px', 2, 'min-width:0'],
            ['three-columns', 'Three columns', 'fa-solid fa-table-cells', 'Three equal grid columns with a 24px gap', 'display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:24px', 3, 'min-width:0'],
            ['sidebar', 'Sidebar layout', 'fa-solid fa-columns', 'A narrow sidebar and wider content column', 'display:grid;grid-template-columns:minmax(0,1fr) minmax(0,2fr);gap:24px', 2, 'min-width:0'],
        ] as [$key, $name, $icon, $description, $style, $count, $childStyle]) {
            $block = new URPCBlock(new URPCBlockDescriptor('div'));
            $block->setBlockAttributes(['style' => $style]);
            for ($index = 0; $index < $count; $index++) {
                $child = new URPCBlock(new URPCBlockDescriptor('div'));
                if ($childStyle !== '') $child->setBlockAttributes(['style' => $childStyle]);
                $block->addBlockChild($child);
            }
            $blocks->add(new BlockDefinition('Quill:' . $key, $name, $icon, $description, $block, 'Layout'));
        }
    }

    private function registerProperties(PropertyRegistry $properties): void
    {
        $base = rtrim($this->assetBase, '/') . '/js/editor/properties/';
        foreach (['layout' => 'Layout', 'style' => 'Appearance', 'text' => 'Typography', 'heading' => 'Heading', 'list' => 'List', 'table' => 'Table', 'image' => 'Image', 'icon' => 'Icon', 'link' => 'Link', 'clickable' => 'Interactions', 'display' => 'Position', 'motion' => 'Motion', 'connect-data' => 'Connect data', 'advanced' => 'Advanced'] as $key => $label) {
            $properties->add(new PropertyDefinition('Quill:' . $key, $key, [$base . $key . '.js'], $base . 'conditions/' . $key . '.js', $label, match ($key) {
                'display' => 'fa-solid fa-table-cells-large', 'layout' => 'fa-solid fa-layer-group',
                'style' => 'fa-solid fa-palette', 'text' => 'fa-solid fa-font', 'motion' => 'fa-solid fa-wand-magic-sparkles',
                'heading' => 'fa-solid fa-heading', 'list' => 'fa-solid fa-list-ul', 'table' => 'fa-solid fa-table',
                'clickable' => 'fa-solid fa-hand-pointer', 'icon' => 'fa-solid fa-icons',
                'connect-data' => 'fa-solid fa-database',
                'link' => 'fa-solid fa-link', 'image' => 'fa-solid fa-image', default => 'fa-solid fa-sliders',
            }));
        }
    }
}
