<?php
namespace Quill\services\editor;

use Quill\models\{QuillPage, QuillPageSnapshot};

final class PageSnapshotHistory
{
    public function listing(QuillPage $owner, int $page): array
    {
        if ($page < 1) throw new \InvalidArgumentException('Invalid history page.');
        $model = (new QuillPageSnapshot())->useConnectionFrom($owner);
        $scope = ['page_id' => $owner->getKey()];
        $count = $model->table($model->model_source)->select(['COUNT(*) AS total'])->where($scope)->first();
        $total = (int) ($count['total'] ?? 0);
        $page = min($page, max(1, (int) ceil($total / 10)));
        $rows = $model->newQuery()->where($scope)->orderBy('revision', 'DESC')->getModels(10, ($page - 1) * 10);
        $authors = [];
        foreach ($rows as $row) {
            if (!array_key_exists($row->admin_id, $authors)) {
                $authors[$row->admin_id] = $model->table('awt_admin')->select(['firstname', 'lastname', 'profile_picture'])->where(['id' => $row->admin_id])->first();
            }
        }
        return ['total' => $total, 'page' => $page, 'revisions' => array_map(static fn($row) => [
            'id' => $row->getKey(), 'revision' => $row->revision, 'admin_id' => $row->admin_id,
            'created_at' => $row->created_at, 'author' => $authors[$row->admin_id],
        ], $rows)];
    }

    public function document(QuillPage $owner, int $id): array
    {
        $snapshot = (new QuillPageSnapshot())->useConnectionFrom($owner)->newQuery()->where(['id' => $id, 'page_id' => $owner->getKey()])->firstModel()
            ?? throw new \OutOfBoundsException('Version not found.');
        return (new EditorDocumentLoader())->snapshot($owner, $snapshot);
    }
}
