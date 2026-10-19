<?php
namespace Quill\services\editor;

use Quill\models\QuillBlockRevision;
use Quill\models\QuillComponent;
use Quill\models\QuillPage;
use Quill\services\conversion\shared\ConversionInput;

final class BlockVersionHistory
{
    private function scope(QuillPage|QuillComponent $owner, string $uid): array
    {
        if ($uid === '' || strlen($uid) > 255) throw new \InvalidArgumentException('Invalid block history identity.');
        return ['page_id' => $owner instanceof QuillPage ? $owner->getKey() : null,
            'component_id' => $owner instanceof QuillComponent ? $owner->getKey() : null, 'block_uid' => $uid];
    }

    /** Called after validation, inside the owner's optimistic-lock transaction. */
    public function capture(QuillPage|QuillComponent $owner, \stdClass $document, int $adminId): void
    {
        $seen = [];
        $external = $owner instanceof QuillPage && $owner->source_type === 'urpc';
        $visit = function (array $blocks) use (&$visit, &$seen, $owner, $adminId, $external): void {
            foreach ($blocks as $block) {
                if (isset($block->_quill->component)) continue;
                $uid = $external ? ($block->_quill->editor->urpcUid ?? null) : ($block->_quill->editor->historyId ?? null);
                // Older API clients can still save documents without editor history identities.
                if ($uid !== null) {
                    if (!is_string($uid)) throw new \InvalidArgumentException('Invalid block history identity.');
                    $scope = $this->scope($owner, $uid);
                    if (isset($seen[$uid])) throw new \InvalidArgumentException('Duplicate block history identity.');
                    $seen[$uid] = true;
                    $own = ConversionInput::copy($block);
                    $this->cleanScopes($own);
                    $model = (new QuillBlockRevision())->useConnectionFrom($owner);
                    $latest = $model->newQuery()->where($scope)->orderBy('revision', 'DESC')->firstModel();
                    if (!$latest || $this->canonical($latest->getDocument()) !== $this->canonical($own)) {
                        foreach ($scope as $key => $value) $model->{$key} = $value;
                        $model->revision = ($latest?->revision ?? 0) + 1;
                        $model->admin_id = $adminId;
                        $model->setDocument($own);
                        if ($model->saveModel() === null) throw new \RuntimeException('Unable to save block history.');
                    }
                }
                $visit($block->children ?? []);
            }
        };
        foreach ($document->sections as $section) $visit($section->blocks);
    }

    private function cleanScopes(\stdClass $block): void
    {
        // Shared instances use their component instance ID; their preview history IDs are regenerated on load.
        if (isset($block->_quill->component, $block->_quill->editor)) unset($block->_quill->editor->historyId);
        if (isset($block->_quill->styles, $block->attributes->class) && is_string($block->attributes->class)) {
            $classes = trim(preg_replace('/\s+/', ' ', preg_replace('/\bquill-scope-[a-f0-9]{64}\b/', '', $block->attributes->class)));
            if ($classes === '') unset($block->attributes->class); else $block->attributes->class = $classes;
        }
        foreach ($block->children ?? [] as $child) $this->cleanScopes($child);
    }

    private function canonical(mixed $value): string
    {
        // Conversion supplies empty metadata defaults when reopening a document.
        // Those defaults are equivalent to fields omitted by the original editor transport.
        $value = ConversionInput::copy($value);
        $prune = function (\stdClass $object) use (&$prune): void {
            foreach (get_object_vars($object) as $key => $item) {
                if ($item instanceof \stdClass) $prune($item);
                if (($item instanceof \stdClass && get_object_vars($item) === []) || $item === []) unset($object->{$key});
            }
        };
        $normalize = function (\stdClass $block) use (&$normalize, $prune): void {
            if (isset($block->_quill)) {
                foreach (['styles', 'bindings', 'custom_settings', 'animations'] as $field) {
                    $item = $block->_quill->{$field} ?? null;
                    if ($item instanceof \stdClass) $prune($item);
                    if (($item instanceof \stdClass && get_object_vars($item) === []) || $item === []) unset($block->_quill->{$field});
                }
            }
            foreach ($block->children ?? [] as $child) $normalize($child);
        };
        $normalize($value);
        $sort = function (mixed $item) use (&$sort): mixed {
            if ($item instanceof \stdClass) {
                $fields = get_object_vars($item); ksort($fields);
                return (object) array_map($sort, $fields);
            }
            return is_array($item) ? array_map($sort, $item) : $item;
        };
        return json_encode($sort($value), JSON_THROW_ON_ERROR);
    }

    public function listing(QuillPage|QuillComponent $owner, string $uid, int $page): array
    {
        $scope = $this->scope($owner, $uid);
        $model = (new QuillBlockRevision())->useConnectionFrom($owner);
        $count = $model->table($model->model_source)->select(['COUNT(*) AS total'])->where($scope)->first();
        $total = (int) ($count['total'] ?? 0);
        $page = min($page, max(1, (int) ceil($total / 10)));
        $rows = $model->newQuery()->where($scope)->orderBy('revision', 'DESC')->getModels(10, ($page - 1) * 10);
        $authors = [];
        foreach ($rows as $row) {
            if (array_key_exists($row->admin_id, $authors)) continue;
            $authors[$row->admin_id] = $model->table('awt_admin')->select(['firstname', 'lastname', 'profile_picture'])
                ->where(['id' => $row->admin_id])->first();
        }
        return ['total' => $total, 'page' => $page, 'revisions' => array_map(static fn($row) => [
            'revision' => $row->revision, 'admin_id' => $row->admin_id, 'created_at' => $row->created_at,
            'author' => $authors[$row->admin_id], 'block' => $row->getDocument(),
        ], $rows)];
    }
}
