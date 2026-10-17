<?php

namespace Quill\services\rendering;

use Quill\services\data\ConnectionRecords;
use Quill\services\data\DataConnection;
use Quill\services\data\ModelConnectionRecords;
use URPC\api\contracts\IURPCBlock;
use URPC\api\contracts\IURPCBlockRenderer;
use URPC\api\contracts\IURPCDocument;
use URPC\service\renderer\URPC;

/** Local block processor. All changes apply to URPC's render-only working tree. */
final class ConnectedDataRenderer implements IURPCBlockRenderer
{
    private int $copyNumber = 0;
    public function __construct(private ?ConnectionRecords $records = null) {}

    public function process(IURPCBlock $block, URPC $urpc): void
    {
        $connection = DataConnection::parse($block->getBlockDescriptor()->getBlockProps());
        foreach ($this->records($connection, $urpc) as $record) {
            $this->apply($block, $connection, $record);
            break;
        }
    }

    /** Run in URPC's prepareDocument hook, before identity assignment and render events. */
    public function prepare(IURPCDocument $document, URPC $urpc): IURPCDocument
    {
        $this->copyNumber = 0;
        $sections = [];
        foreach ($document->getSections() as $name => $blocks) $sections[$name] = $this->expand(array_values($blocks), $urpc);
        $document->fromArray($sections);
        return $document;
    }

    private function expand(array $blocks, URPC $urpc, int $depth = 0): array
    {
        if ($depth > 128) throw new \LogicException('Maximum connected block depth exceeded.');
        $expanded = [];
        foreach ($blocks as $block) {
            $descriptor = $block->getBlockDescriptor();
            $copies = [$block];
            if (ltrim($descriptor->getBlockRenderer(), '\\') === self::class) {
                $connection = DataConnection::parse($descriptor->getBlockProps());
                $copies = [];
                foreach ($this->records($connection, $urpc) as $record) {
                    $copy = $this->copy($block);
                    $copy->getBlockDescriptor()->setBlockRenderer('');
                    $this->apply($copy, $connection, $record);
                    if ($copies !== []) $this->uniqueIds($copy, ++$this->copyNumber);
                    $copies[] = $copy;
                    if ($connection['mode'] !== 'collection') break;
                }
                // No records: retain the original authored content, attributes and children.
                if ($copies === []) {
                    $descriptor->setBlockRenderer('');
                    $copies[] = $block;
                }
            }
            foreach ($copies as $copy) {
                $copy->setBlockChildren($this->expand(array_values($copy->getBlockChildren()), $urpc, $depth + 1));
                $expanded[] = $copy;
            }
        }
        return $expanded;
    }

    private function records(array $connection, URPC $urpc): iterable
    {
        try {
            // Materialize here so a missing/deleted table also falls back during collection iteration.
            return [...($this->records ??= new ModelConnectionRecords())->find($connection, $urpc->shared['quill.route_parameters'] ?? [])];
        } catch (\PDOException $error) {
            return [];
        }
    }

    private function apply(IURPCBlock $block, array $connection, \model\Model $record): void
    {
        $attributes = $block->getBlockAttributes();
        foreach ($connection['set']['attributes'] as $mapping) {
            $value = $record->getParam($mapping['value']);
            if (!is_scalar($value) || !$this->safeValue($mapping['name'], $value)) continue;
            $attributes[$mapping['name']] = $value;
        }
        $block->setBlockAttributes($attributes);
        $column = $connection['set']['content']['content'];
        if ($column === '' || in_array(strtolower($block->getBlockDescriptor()->getBlockName()), [...DataConnection::VOID_TAGS, 'script', 'style'], true)) return;
        $parts = []; $found = false;
        foreach ([$column, ...($connection['set']['content']['additional'] ?? [])] as $field) {
            $value = $record->getParam($field);
            if (!is_scalar($value)) continue;
            $found = true;
            if ((string) $value !== '') $parts[] = (string) $value;
        }
        if ($found) {
            $block->setBlockContent($connection['set']['content']['before'] . implode(' ', $parts) . $connection['set']['content']['after']);
            $block->setBlockChildren([]);
        }
    }

    private function safeValue(string $attribute, mixed $value): bool
    {
        if (!in_array(strtolower($attribute), ['href','src','action','formaction','poster','background','xlink:href'], true)) return true;
        $url = preg_replace('/[\x00-\x20\x7f]+/', '', (string) $value);
        return !preg_match('/^([a-z][a-z0-9+.-]*):/i', $url, $match) || in_array(strtolower($match[1]), ['http','https','mailto','tel','sms'], true);
    }

    private function copy(IURPCBlock $block): IURPCBlock
    {
        $copy = clone $block;
        $copy->setBlockDescriptor(clone $block->getBlockDescriptor());
        $copy->setBlockChildren(array_map(fn ($child) => $this->copy($child), array_values($block->getBlockChildren())));
        return $copy;
    }

    private function uniqueIds(IURPCBlock $block, int $number): void
    {
        $map = [];
        $visit = function ($node, $operation) use (&$visit): void {
            $operation($node);
            foreach ($node->getBlockChildren() as $child) $visit($child, $operation);
        };
        $visit($block, static function ($node) use (&$map, $number) {
            $attributes = $node->getBlockAttributes();
            if (isset($attributes['id']) && is_string($attributes['id'])) {
                $id = $attributes['id']; $map[$id] = $attributes['id'] = $id . '--quill-record-' . $number;
                $node->setBlockAttributes($attributes);
            }
        });
        $visit($block, static function ($node) use ($map) {
            $attributes = $node->getBlockAttributes();
            foreach (['for','aria-labelledby','aria-describedby','aria-controls','aria-owns','headers'] as $name) {
                if (isset($attributes[$name]) && is_string($attributes[$name])) $attributes[$name] = implode(' ', array_map(static fn ($id) => $map[$id] ?? $id, explode(' ', $attributes[$name])));
            }
            if (isset($attributes['href']) && is_string($attributes['href']) && str_starts_with($attributes['href'], '#')) {
                $id = substr($attributes['href'], 1); if (isset($map[$id])) $attributes['href'] = '#' . $map[$id];
            }
            $node->setBlockAttributes($attributes);
        });
    }
}
