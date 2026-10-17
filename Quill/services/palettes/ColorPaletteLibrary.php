<?php
namespace Quill\services\palettes;

use Quill\models\QuillColorPalette;
use Quill\models\QuillModel;

/** Named global palettes. Applying a swatch copies its value into the document. */
final class ColorPaletteLibrary
{
    public function __construct(private ?QuillModel $connection = null) {}
    private function model(): QuillColorPalette
    {
        $model = new QuillColorPalette();
        if ($this->connection) $model->useConnectionFrom($this->connection);
        return $model;
    }
    public function all(): array
    {
        return array_map($this->summary(...), $this->model()->newQuery()->where(['deleted_at' => null])->orderBy('name', 'ASC')->getModels());
    }
    public function find(int $id): ?QuillColorPalette
    {
        return $this->model()->newQuery()->where(['id' => $id, 'deleted_at' => null])->firstModel();
    }
    public function definition(int $id): ?\stdClass { return $this->find($id)?->getDefinition(); }
    public function summary(QuillColorPalette $palette): array
    {
        return ['id' => $palette->getKey(), 'name' => $palette->name, 'version' => $palette->lock_version,
            'admin_id' => $palette->admin_id, 'created_at' => $palette->created_at, 'updated_at' => $palette->updated_at, 'colors' => $palette->getDefinition()->colors];
    }
    public function save(\stdClass $definition, int $adminId, ?int $id = null, ?int $version = null): array
    {
        \Quill\services\document\ColorPalette::validateDefinition($definition);
        $model = $id === null ? $this->model() : ($this->find($id) ?? throw new \OutOfBoundsException('Palette not found.'));
        if ($id === null) {
            $model->name = trim($definition->name); $model->admin_id = $adminId;
            $model->lock_version = 1; $model->setDefinition($definition);
            if ($model->saveModel() === null) throw new \RuntimeException('Palette insert failed.');
            $model = $this->find((int) $model->getKey()) ?? throw new \RuntimeException('Palette could not be reloaded.');
        } else {
            if ($version !== $model->lock_version) throw new \DomainException('This palette changed. Reopen it before replacing it.');
            $changed = $model->table('quill_color_palette')->where(['id' => $id, 'lock_version' => $version, 'deleted_at' => null])->update([
                'name' => trim($definition->name), 'palette_json' => json_encode($definition, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'updated_at' => date('Y-m-d H:i:s'), 'lock_version' => $version + 1,
            ]);
            if (!$changed) throw new \DomainException('This palette changed. Reopen it before replacing it.');
            $model = $this->find($id) ?? throw new \OutOfBoundsException('Palette not found.');
        }
        return $this->summary($model) + ['definition' => $model->getDefinition()];
    }
    public function delete(int $id, int $version): void
    {
        $model = $this->find($id) ?? throw new \OutOfBoundsException('Palette not found.');
        if ($model->lock_version !== $version || !$model->table('quill_color_palette')->where(['id' => $id, 'lock_version' => $version, 'deleted_at' => null])->update([
            'deleted_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'), 'lock_version' => $version + 1,
        ])) throw new \DomainException('This palette changed. Refresh the library before deleting it.');
    }
}
